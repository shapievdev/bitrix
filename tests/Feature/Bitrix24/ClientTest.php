<?php

namespace Tests\Feature\Bitrix24;

use App\Facades\Bitrix24;
use App\Models\Portal;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use App\Services\Bitrix24\Exceptions\TokenRefreshFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bitrix24.client_id' => 'local.test',
            'bitrix24.client_secret' => 'secret',
            'bitrix24.throttle.enabled' => false,
            'bitrix24.http.retry_delay' => 1,
        ]);
    }

    protected function portal(array $attributes = []): Portal
    {
        return Portal::create(array_merge([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'old-access',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ], $attributes));
    }

    public function test_протухший_токен_обновляется_и_запрос_повторяется(): void
    {
        $portal = $this->portal();

        Http::fake([
            'oauth.bitrix.info/*' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'refresh-2',
                'expires_in' => 3600,
            ]),
            '*/rest/*' => Http::sequence()
                ->push(['error' => 'expired_token', 'error_description' => 'The access token provided has expired'], 401)
                ->push(['result' => ['ID' => '1']], 200),
        ]);

        $result = Bitrix24::forPortal($portal)->call('user.current');

        $this->assertSame(['ID' => '1'], $result);
        $this->assertSame('new-access', $portal->fresh()->access_token);
        $this->assertSame('refresh-2', $portal->fresh()->refresh_token);
    }

    public function test_отозванный_refresh_токен_выключает_портал(): void
    {
        $portal = $this->portal(['token_expires_at' => now()->subMinute()]);

        Http::fake([
            'oauth.bitrix.info/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(TokenRefreshFailed::class);

        try {
            Bitrix24::forPortal($portal)->call('user.current');
        } finally {
            $this->assertFalse($portal->fresh()->is_active);
        }
    }

    public function test_превышение_лимита_повторяется_и_затем_проходит(): void
    {
        $portal = $this->portal();

        Http::fakeSequence()
            ->push(['error' => 'QUERY_LIMIT_EXCEEDED'], 503)
            ->push(['result' => ['ok' => true]], 200);

        $this->assertSame(['ok' => true], Bitrix24::forPortal($portal)->call('user.current'));
    }

    public function test_ошибка_прав_пробрасывается_без_повторов(): void
    {
        $portal = $this->portal();

        Http::fake(['*' => Http::response([
            'error' => 'INVALID_CREDENTIALS',
            'error_description' => 'Недостаточно прав',
        ], 403)]);

        try {
            Bitrix24::forPortal($portal)->call('tasks.task.list');
            $this->fail('Ожидалось исключение Bitrix24Exception');
        } catch (Bitrix24Exception $e) {
            $this->assertSame('INVALID_CREDENTIALS', $e->errorCode);
            $this->assertSame('tasks.task.list', $e->method);
        }

        Http::assertSentCount(1);
    }

    public function test_постраничный_обход_собирает_все_страницы(): void
    {
        $portal = $this->portal();

        Http::fakeSequence()
            ->push(['result' => ['tasks' => [['ID' => '1'], ['ID' => '2']]], 'next' => 50, 'total' => 3])
            ->push(['result' => ['tasks' => [['ID' => '3']]], 'total' => 3]);

        $ids = collect(iterator_to_array(
            Bitrix24::forPortal($portal)->list('tasks.task.list', [], 'tasks')
        ))->pluck('ID')->all();

        $this->assertSame(['1', '2', '3'], $ids);
    }

    public function test_переданный_вызывающим_start_не_ломает_пагинацию(): void
    {
        $portal = $this->portal();

        Http::fakeSequence()
            ->push(['result' => ['tasks' => [['ID' => '1']]], 'next' => 50])
            ->push(['result' => ['tasks' => [['ID' => '2']]], 'next' => 100])
            ->push(['result' => ['tasks' => [['ID' => '3']]]]);

        // Раньше start из параметров вызывающего побеждал при слиянии через +,
        // выборка не сдвигалась, а непустой next гнал обход по кругу вечно.
        $ids = collect(iterator_to_array(
            Bitrix24::forPortal($portal)->list('tasks.task.list', ['start' => 0], 'tasks')
        ))->pluck('ID')->all();

        $this->assertSame(['1', '2', '3'], $ids);

        $starts = collect(Http::recorded())->map(fn ($pair) => $pair[0]['start'])->all();
        $this->assertSame([0, 50, 100], $starts);
    }

    public function test_не_сдвигающийся_next_роняет_обход(): void
    {
        $portal = $this->portal();

        // Портал упорно отдаёт next, не двигающий выборку вперёд.
        Http::fake(['*' => Http::response([
            'result' => ['tasks' => [['ID' => '1']]],
            'next' => 0,
        ])]);

        // Раньше обход здесь молча прерывался, и снаружи огрызок из одной
        // задачи выглядел как полный список. Синхронизация принимала его
        // за истину и снимала с доски всё остальное — так и потеряли
        // 797 карточек 18.08.2026. Неполный ответ обязан быть громким.
        $this->expectException(Bitrix24Exception::class);

        iterator_to_array(
            Bitrix24::forPortal($portal)->list('tasks.task.list', [], 'tasks')
        );
    }

    public function test_пустая_страница_с_обещанным_продолжением_роняет_обход(): void
    {
        $portal = $this->portal();

        Http::fakeSequence()
            ->push(['result' => ['tasks' => [['ID' => '1']]], 'next' => 50])
            ->push(['result' => ['tasks' => []], 'next' => 100]);

        $this->expectException(Bitrix24Exception::class);

        iterator_to_array(
            Bitrix24::forPortal($portal)->list('tasks.task.list', [], 'tasks')
        );
    }

    public function test_batch_разбивает_команды_по_лимиту(): void
    {
        $portal = $this->portal();
        config(['bitrix24.batch_size' => 2]);

        Http::fake(['*' => Http::response(['result' => ['result' => ['a' => 1], 'result_error' => []]])]);

        Bitrix24::forPortal($portal)->batch([
            'a' => ['user.get', []],
            'b' => ['user.get', []],
            'c' => ['user.get', []],
        ]);

        // 3 команды при лимите 2 — это ровно два REST-запроса.
        Http::assertSentCount(2);
    }
}
