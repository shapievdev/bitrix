<?php

namespace Tests\Feature\Bitrix24;

use App\Models\Portal;
use App\Models\PortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bitrix24.client_id' => 'local.test',
            'bitrix24.client_secret' => 'secret',
            'bitrix24.throttle.enabled' => false,
        ]);
    }

    /**
     * Ответ на batch с user.current + user.admin.
     */
    protected function fakeHandshake(bool $isAdmin = true): void
    {
        Http::fake([
            '*/rest/batch.json' => Http::response([
                'result' => [
                    'result' => [
                        'profile' => [
                            'ID' => '42',
                            'NAME' => 'Иван',
                            'LAST_NAME' => 'Петров',
                            'EMAIL' => 'ivan@example.com',
                            'WORK_POSITION' => 'Руководитель проектов',
                        ],
                        'is_admin' => $isAdmin,
                        'scope' => ['task', 'user', 'placement'],
                    ],
                    'result_error' => [],
                ],
            ]),
        ]);
    }

    protected function placementPayload(array $overrides = []): array
    {
        return array_merge([
            'AUTH_ID' => 'access-token-abc',
            'REFRESH_ID' => 'refresh-token-abc',
            'AUTH_EXPIRES' => '3600',
            'member_id' => 'member-123',
            'DOMAIN' => 'example.bitrix24.ru',
            'PLACEMENT' => 'DEFAULT',
            'PLACEMENT_OPTIONS' => '[]',
        ], $overrides);
    }

    public function test_вход_из_iframe_заводит_портал_и_пользователя(): void
    {
        $this->fakeHandshake();

        $response = $this->post('/', $this->placementPayload());

        $response->assertRedirect(route('app.home'));

        $portal = Portal::firstWhere('member_id', 'member-123');

        $this->assertNotNull($portal);
        $this->assertSame('example.bitrix24.ru', $portal->domain);
        $this->assertSame('access-token-abc', $portal->access_token);
        $this->assertSame('refresh-token-abc', $portal->refresh_token);
        $this->assertTrue($portal->is_active);
        // Права приходят не с токенами, а отдельным методом scope.
        $this->assertSame(['task', 'user', 'placement'], $portal->scope);

        $user = PortalUser::firstWhere('bitrix_user_id', 42);

        $this->assertNotNull($user);
        $this->assertSame('Иван Петров', $user->name);
        $this->assertTrue($user->is_admin);
        $this->assertSame($portal->id, $user->portal_id);

        $this->assertSame($portal->id, session('bitrix.portal_id'));
        $this->assertSame($user->id, session('bitrix.user_id'));
    }

    public function test_повторный_вход_не_плодит_дубликаты(): void
    {
        $this->fakeHandshake();

        $this->post('/', $this->placementPayload());
        $this->post('/', $this->placementPayload(['AUTH_ID' => 'access-token-second']));

        $this->assertSame(1, Portal::count());
        $this->assertSame(1, PortalUser::count());
        $this->assertSame('access-token-second', Portal::first()->access_token);
    }

    public function test_вход_из_iframe_не_затирает_application_token(): void
    {
        $this->fakeHandshake();

        $portal = Portal::create([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'application_token' => 'настоящий-токен-установки',
            'is_active' => true,
            'installed_at' => now(),
        ]);

        // APP_SID приходит с каждым открытием фрейма и каждый раз новый.
        // Раньше он попадал в application_token, и события портала
        // начинали отклоняться сразу после первого входа сотрудника.
        $this->post('/', $this->placementPayload(['APP_SID' => 'случайный-сид-фрейма']));

        $this->assertSame('настоящий-токен-установки', $portal->fresh()->application_token);
    }

    public function test_вход_без_токенов_отклоняется(): void
    {
        $this->post('/', ['PLACEMENT' => 'DEFAULT'])->assertForbidden();

        $this->assertSame(0, Portal::count());
    }

    public function test_недействительный_токен_не_создаёт_пользователя(): void
    {
        Http::fake([
            '*/rest/batch.json' => Http::response(['error' => 'invalid_token'], 401),
        ]);

        $this->post('/', $this->placementPayload())->assertForbidden();

        $this->assertSame(0, PortalUser::count());
    }

    public function test_страница_приложения_недоступна_без_рукопожатия(): void
    {
        $this->get('/app')->assertForbidden();
    }

    public function test_страница_приложения_открывается_после_входа(): void
    {
        $this->fakeHandshake();
        $this->post('/', $this->placementPayload());

        // Корень приложения ведёт сразу на канбан: экрана между входом
        // и работой нет.
        $response = $this->get('/app');

        $response->assertRedirect();
        $response->assertHeader('Content-Security-Policy');
        $this->assertStringContainsString(
            'frame-ancestors',
            $response->headers->get('Content-Security-Policy'),
        );
    }
}
