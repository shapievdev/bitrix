<?php

namespace App\Services\Bitrix24;

use App\Models\Portal;
use App\Models\PortalUser;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент REST API одного портала.
 *
 * Создаётся не напрямую, а через фасад Bitrix24:
 *
 *     Bitrix24::forPortal($portal)->call('tasks.task.list');
 *     Bitrix24::forUser($portalUser)->call('task.item.add', [...]);
 */
class Bitrix24Client
{
    public function __construct(
        protected Portal $portal,
        protected ?PortalUser $user,
        protected TokenManager $tokens,
        protected PortalThrottle $throttle,
        /**
         * Разовый токен вместо сохранённых.
         *
         * Используется, пока токен ещё не привязан ни к порталу, ни к
         * пользователю — например, при проверке рукопожатия на входе.
         * Обновить такой токен нельзя: при истечении будет исключение.
         */
        protected ?TokenSet $adhocToken = null,
    ) {}

    public function portal(): Portal
    {
        return $this->portal;
    }

    /**
     * Вызвать метод и получить содержимое result.
     */
    public function call(string $method, array $params = []): mixed
    {
        return $this->raw($method, $params)['result'] ?? null;
    }

    /**
     * Вызвать метод и получить полный ответ, включая next/total.
     */
    public function raw(string $method, array $params = []): array
    {
        $attempt = 0;
        $maxAttempts = max(1, (int) config('bitrix24.http.retries'));
        $refreshed = false;

        while (true) {
            $attempt++;

            $response = $this->throttle->run(
                $this->portal->member_id,
                fn () => $this->send($method, $params),
            );

            $payload = $response->json() ?? [];

            if ($response->successful() && ! isset($payload['error'])) {
                return $payload;
            }

            $error = (string) ($payload['error'] ?? 'http_'.$response->status());

            // Портал считает токен недействительным — его слово важнее
            // нашего срока годности, поэтому обновляем принудительно.
            // Повторяем ровно один раз, иначе при отозванном доступе
            // получится бесконечный круг.
            if (! $refreshed && $this->isAuthError($error)) {
                $this->refreshTokens(force: true);
                $refreshed = true;

                continue;
            }

            // Лимит запросов или сбой на стороне портала — ждём и повторяем.
            if ($attempt < $maxAttempts && $this->isRetryable($error, $response)) {
                usleep($this->backoffMicroseconds($attempt));

                continue;
            }

            Log::warning('Bitrix24: ошибка REST', [
                'portal' => $this->portal->domain,
                'method' => $method,
                'error' => $error,
                'status' => $response->status(),
            ]);

            throw Bitrix24Exception::fromResponse($method, $payload + ['error' => $error]);
        }
    }

    /**
     * Постранично обойти список, отдавая элементы по одному.
     *
     * Пагинация в Битриксе идёт через start/next с шагом 50 — размер
     * страницы не настраивается. Генератор скрывает это от вызывающего:
     *
     *     foreach ($client->list('tasks.task.list', [...], 'tasks') as $task) { ... }
     *
     * @param  ?string  $key  Ключ внутри result (например, 'tasks' у tasks.task.list).
     */
    public function list(string $method, array $params = [], ?string $key = null): Generator
    {
        $start = 0;

        // start задаём только здесь и перекрываем то, что передал вызывающий:
        // при слиянии через + его значение осталось бы навсегда, страница
        // не сдвигалась бы, а непустой next гнал бы цикл по кругу вечно.
        unset($params['start']);

        do {
            $payload = $this->raw($method, array_merge($params, ['start' => $start]));

            $result = $payload['result'] ?? [];
            $items = $key !== null ? ($result[$key] ?? []) : $result;

            foreach ($items as $item) {
                yield $item;
            }

            $next = $payload['next'] ?? null;

            // Предохранитель от зацикливания: портал вернул next, не
            // сдвинувший выборку вперёд. Раньше здесь был break, но
            // молча оборванный обход выглядит снаружи как полный ответ,
            // и вызывающий принимает огрызок за весь список. Для
            // синхронизации это означало снятие с доски всего, что в
            // огрызок не попало. Лучше громко упасть.
            if ($next !== null && (int) $next <= $start) {
                throw new Bitrix24Exception(
                    "Постраничный обход {$method} не двигается вперёд: start={$start}, next={$next}. "
                    .'Ответ портала неполный, продолжать нельзя.'
                );
            }

            // То же самое с пустой страницей посреди выборки: портал
            // обещал продолжение, но ничего не отдал.
            if ($items === [] && $next !== null) {
                throw new Bitrix24Exception(
                    "Постраничный обход {$method} получил пустую страницу на start={$start}, "
                    .'хотя портал обещал продолжение. Ответ неполный.'
                );
            }

            $start = $next;
        } while ($start !== null && $items !== []);
    }

    /**
     * Выполнить до 50 команд одним запросом.
     *
     * Главный способ уложиться в лимиты при синхронизации: 50 обращений
     * стоят одного запроса вместо пятидесяти.
     *
     *     $client->batch([
     *         'user'  => ['user.current', []],
     *         'tasks' => ['tasks.task.list', ['filter' => ['RESPONSIBLE_ID' => 1]]],
     *     ]);
     *
     * @param  array<string, array{0: string, 1?: array}>  $commands
     * @param  bool  $halt  Прервать выполнение пачки на первой ошибке.
     * @return array<string, mixed> Результаты по тем же ключам.
     */
    public function batch(array $commands, bool $halt = false): array
    {
        $results = [];

        foreach (array_chunk($commands, config('bitrix24.batch_size'), true) as $chunk) {
            $cmd = [];

            foreach ($chunk as $key => [$method, $params]) {
                $query = http_build_query($params ?? []);
                $cmd[$key] = $query === '' ? $method : "{$method}?{$query}";
            }

            $payload = $this->raw('batch', ['halt' => $halt ? 1 : 0, 'cmd' => $cmd]);
            $result = $payload['result'] ?? [];

            foreach ($result['result_error'] ?? [] as $key => $error) {
                Log::warning('Bitrix24: команда в batch завершилась ошибкой', [
                    'portal' => $this->portal->domain,
                    'command' => $key,
                    'error' => $error,
                ]);
            }

            $results += $result['result'] ?? [];
        }

        return $results;
    }

    protected function send(string $method, array $params): Response
    {
        return Http::asForm()
            ->timeout(config('bitrix24.http.timeout'))
            ->connectTimeout(config('bitrix24.http.connect_timeout'))
            ->post($this->portal->restUrl($method), $params + [
                'auth' => $this->accessToken(),
            ]);
    }

    protected function accessToken(): string
    {
        if ($this->adhocToken !== null) {
            return $this->adhocToken->accessToken;
        }

        $holder = $this->user ?? $this->portal;

        if ($holder->tokenExpired()) {
            $this->refreshTokens();
            $holder = $this->user ?? $this->portal;
        }

        return $holder->access_token;
    }

    protected function refreshTokens(bool $force = false): void
    {
        if ($this->adhocToken !== null) {
            throw new Bitrix24Exception(
                'Разовый токен истёк и не может быть обновлён — требуется повторный вход из Битрикс24.',
                errorCode: 'expired_token',
            );
        }

        if ($this->user !== null) {
            $this->user = $this->tokens->refreshUser($this->user, $force);

            return;
        }

        $this->portal = $this->tokens->refreshPortal($this->portal, $force);
    }

    protected function isAuthError(string $error): bool
    {
        return in_array($error, [
            'expired_token',
            'invalid_token',
            'NO_AUTH_FOUND',
            'WRONG_AUTH_TYPE',
        ], true);
    }

    protected function isRetryable(string $error, Response $response): bool
    {
        if (in_array($error, ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT', 'INTERNAL_SERVER_ERROR'], true)) {
            return true;
        }

        return $response->serverError() || $response->status() === 429;
    }

    protected function backoffMicroseconds(int $attempt): int
    {
        $base = (int) config('bitrix24.http.retry_delay');

        // Экспоненциальный рост + джиттер, чтобы воркеры не били в лимит синхронно.
        return (int) (($base * (2 ** ($attempt - 1)) + random_int(0, 250)) * 1000);
    }
}
