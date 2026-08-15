<?php

namespace App\Console\Commands;

use App\Facades\Bitrix24;
use App\Models\Portal;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Проверка готовности окружения к работе с Битрикс24.
 *
 * Портал на неверную настройку отвечает невнятно: пустой фрейм, вечная
 * «установка» или отказ без причины. Эта команда называет причину прямо.
 */
class BitrixDoctor extends Command
{
    protected $signature = 'bitrix:doctor {--ping : Дополнительно дёрнуть REST каждого портала}';

    protected $description = 'Проверить настройки приложения перед подключением к Битрикс24';

    protected int $problems = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Проверка окружения');

        $this->checkAppKey();
        $this->checkCredentials();
        $this->checkHttps();
        $this->checkSessionCookie();
        $this->checkDatabase();
        $this->checkCacheLocks();
        $this->checkQueue();

        $this->newLine();
        $this->components->info('Подключённые порталы');
        $this->checkPortals();

        $this->newLine();

        if ($this->problems > 0) {
            $this->components->error("Найдено проблем: {$this->problems}. Приложение работать не будет.");

            return self::FAILURE;
        }

        $this->components->info('Всё готово.');

        return self::SUCCESS;
    }

    protected function pass(string $label, string $detail = ''): void
    {
        $this->components->twoColumnDetail("  <fg=green>✓</> {$label}", "<fg=gray>{$detail}</>");
    }

    protected function problem(string $label, string $fix): void
    {
        $this->problems++;
        $this->components->twoColumnDetail("  <fg=red>✗</> {$label}", "<fg=yellow>{$fix}</>");
    }

    protected function note(string $label, string $detail): void
    {
        $this->components->twoColumnDetail("  <fg=yellow>!</> {$label}", "<fg=gray>{$detail}</>");
    }

    protected function checkAppKey(): void
    {
        // Токены порталов лежат в базе шифрованными. Без ключа их не
        // прочитать, а после смены ключа — не расшифровать уже сохранённые.
        config('app.key')
            ? $this->pass('APP_KEY задан', 'токены шифруются')
            : $this->problem('APP_KEY пуст', 'php artisan key:generate');
    }

    protected function checkCredentials(): void
    {
        $id = config('bitrix24.client_id');
        $secret = config('bitrix24.client_secret');

        if (! $id || ! $secret) {
            $this->problem(
                'Нет client_id / client_secret',
                'Возьмите их в карточке приложения на портале и впишите в .env',
            );

            return;
        }

        $this->pass('client_id и client_secret заданы', $id);

        $scope = config('bitrix24.scope');

        $scope
            ? $this->pass('Запрошенные права', implode(', ', $scope))
            : $this->problem('BITRIX24_SCOPE пуст', 'Укажите права, выданные приложению на портале');
    }

    protected function checkHttps(): void
    {
        $url = (string) config('app.url');

        if (! str_starts_with($url, 'https://')) {
            $this->problem(
                "APP_URL не https ({$url})",
                'Битрикс24 грузит приложение только по HTTPS',
            );

            return;
        }

        if (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1')) {
            $this->problem(
                'APP_URL указывает на localhost',
                'Портал обращается к приложению снаружи и localhost не увидит',
            );

            return;
        }

        $this->pass('APP_URL', $url);

        $this->components->twoColumnDetail(
            '    <fg=gray>путь обработчика</>',
            '<fg=cyan>'.rtrim($url, '/').'/</>',
        );
        $this->components->twoColumnDetail(
            '    <fg=gray>путь установки</>',
            '<fg=cyan>'.rtrim($url, '/').'/bitrix/install</>',
        );
    }

    protected function checkSessionCookie(): void
    {
        $sameSite = strtolower((string) config('session.same_site'));
        $secure = config('session.secure');

        // Приложение открывается в iframe чужого домена: cookie без
        // SameSite=None браузер просто не отдаст, и вход будет слетать.
        if ($sameSite !== 'none') {
            $this->problem(
                "session.same_site = {$sameSite}",
                'Нужно SESSION_SAME_SITE=none, иначе сессия во фрейме не живёт',
            );
        } else {
            $this->pass('session.same_site = none', 'сессия переживает фрейм');
        }

        $secure
            ? $this->pass('session.secure = true', 'обязательно при SameSite=None')
            : $this->problem('session.secure выключен', 'SESSION_SECURE_COOKIE=true');
    }

    protected function checkDatabase(): void
    {
        try {
            $name = DB::connection()->getDatabaseName();
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->problem('Нет связи с базой', mb_substr($e->getMessage(), 0, 90));

            return;
        }

        $this->pass('База доступна', DB::connection()->getDriverName().': '.$name);

        Schema::hasTable('portals') && Schema::hasTable('portal_users')
            ? $this->pass('Таблицы на месте', 'portals, portal_users')
            : $this->problem('Миграции не прогнаны', 'php artisan migrate');
    }

    protected function checkCacheLocks(): void
    {
        // На блокировках держится и ограничитель частоты, и обновление
        // токенов. Драйвер без атомарных блокировок их молча сломает.
        $store = Cache::store()->getStore();

        $store instanceof LockProvider
            ? $this->pass('Кэш умеет блокировки', config('cache.default'))
            : $this->problem(
                'Драйвер кэша без атомарных блокировок ('.config('cache.default').')',
                'Возьмите redis, database или file',
            );
    }

    protected function checkQueue(): void
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            $this->note(
                'Очередь работает синхронно',
                'События портала будут обрабатываться прямо в запросе — Битрикс может отвалиться по таймауту',
            );

            return;
        }

        $this->pass('Очередь', $connection.' (не забудьте queue:work)');
    }

    protected function checkPortals(): void
    {
        if (! Schema::hasTable('portals')) {
            $this->note('Проверка пропущена', 'нет таблицы portals');

            return;
        }

        $portals = Portal::all();

        if ($portals->isEmpty()) {
            $this->note(
                'Порталов пока нет',
                'Установите приложение на портал — запись появится сама',
            );

            return;
        }

        foreach ($portals as $portal) {
            $status = match (true) {
                ! $portal->is_active => '<fg=red>отключён</>',
                $portal->tokenExpired() => '<fg=yellow>токен истёк</>',
                default => '<fg=green>активен</>',
            };

            $this->components->twoColumnDetail("  {$portal->domain}", $status);

            if (! $portal->application_token) {
                $this->note(
                    '    нет application_token',
                    'события от портала будут отклоняться — переустановите приложение',
                );
            }

            if ($this->option('ping') && $portal->is_active) {
                $this->ping($portal);
            }
        }
    }

    protected function ping(Portal $portal): void
    {
        try {
            $info = Bitrix24::forPortal($portal)->call('app.info');

            $this->components->twoColumnDetail(
                '    <fg=gray>REST отвечает</>',
                '<fg=green>права: '.implode(', ', $info['SCOPE'] ?? []).'</>',
            );
        } catch (Bitrix24Exception $e) {
            $this->problems++;
            $this->components->twoColumnDetail(
                '    <fg=red>REST недоступен</>',
                '<fg=yellow>'.mb_substr($e->getMessage(), 0, 80).'</>',
            );
        }
    }
}
