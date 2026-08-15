<?php

namespace Tests\Feature\Bitrix24;

use App\Jobs\ProcessBitrixEvent;
use App\Models\Portal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    protected function portal(array $attributes = []): Portal
    {
        return Portal::create(array_merge([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'application_token' => 'app-token-secret',
            'is_active' => true,
            'installed_at' => now(),
        ], $attributes));
    }

    protected function event(string $event, array $auth = [], array $data = []): array
    {
        return [
            'event' => $event,
            'data' => $data,
            'auth' => array_merge([
                'member_id' => 'member-123',
                'domain' => 'example.bitrix24.ru',
                'application_token' => 'app-token-secret',
                'access_token' => 'access',
                'expires_in' => 3600,
            ], $auth),
        ];
    }

    public function test_событие_с_верным_токеном_уходит_в_очередь(): void
    {
        Queue::fake();
        $this->portal();

        $this->post('/bitrix/event', $this->event('ONTASKUPDATE', data: [
            'FIELDS_AFTER' => ['ID' => '777'],
        ]))->assertNoContent();

        Queue::assertPushed(ProcessBitrixEvent::class, fn ($job) => $job->event === 'ONTASKUPDATE');
    }

    public function test_событие_с_чужим_токеном_отклоняется(): void
    {
        Queue::fake();
        $this->portal();

        $this->post('/bitrix/event', $this->event('ONTASKUPDATE', [
            'application_token' => 'подделка',
        ]))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_событие_без_токена_отклоняется(): void
    {
        Queue::fake();
        $this->portal();

        $payload = $this->event('ONTASKUPDATE');
        unset($payload['auth']['application_token']);

        $this->post('/bitrix/event', $payload)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_событие_для_неизвестного_портала_отклоняется(): void
    {
        Queue::fake();

        $this->post('/bitrix/event', $this->event('ONTASKUPDATE'))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_onappinstall_сохраняет_портал_и_application_token(): void
    {
        $this->post('/bitrix/event', $this->event('ONAPPINSTALL', [
            'application_token' => 'свежий-токен',
            'refresh_token' => 'refresh-new',
            'scope' => 'task,user',
        ], ['VERSION' => '2']))->assertNoContent();

        $portal = Portal::firstWhere('member_id', 'member-123');

        $this->assertNotNull($portal);
        $this->assertSame('свежий-токен', $portal->application_token);
        $this->assertSame(['task', 'user'], $portal->scope);
        $this->assertSame('2', $portal->app_version);
    }

    public function test_onappuninstall_выключает_портал_и_стирает_токены(): void
    {
        $this->portal();

        $this->post('/bitrix/event', $this->event('ONAPPUNINSTALL'))->assertNoContent();

        $portal = Portal::firstWhere('member_id', 'member-123');

        $this->assertFalse($portal->is_active);
        $this->assertNull($portal->access_token);
        $this->assertNull($portal->refresh_token);
        $this->assertNotNull($portal->uninstalled_at);
    }

    public function test_неактивный_портал_не_пускает_в_приложение(): void
    {
        $portal = $this->portal();

        $this->post('/bitrix/event', $this->event('ONAPPUNINSTALL'));

        $this->withSession([
            'bitrix.portal_id' => $portal->id,
            'bitrix.user_id' => null,
        ])->get('/app')->assertForbidden();
    }
}
