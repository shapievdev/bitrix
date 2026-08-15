<?php

namespace Tests\Feature\Bitrix24;

use App\Models\Portal;
use App\Models\PortalUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Хранение токенов в базе.
 *
 * Шифрование раздувает строку почти втрое: токен на 50 символов
 * превращается в 288. Колонки должны это выдерживать, иначе установка
 * упадёт на вставке — а поймать такое можно только на настоящем Postgres.
 */
class PortalStorageTest extends TestCase
{
    use RefreshDatabase;

    /** Токены Битрикса — 32-50 символов; берём с запасом. */
    protected function longToken(string $prefix): string
    {
        return $prefix.'_'.str_repeat('a1b2c3d4', 8);
    }

    public function test_длинные_токены_портала_помещаются_в_колонки(): void
    {
        $portal = Portal::create([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => $this->longToken('access'),
            'refresh_token' => $this->longToken('refresh'),
            'application_token' => $this->longToken('app'),
            'token_expires_at' => now()->addHour(),
            'scope' => ['task', 'user', 'placement'],
            'is_active' => true,
        ]);

        $stored = Portal::findOrFail($portal->id);

        $this->assertSame($this->longToken('access'), $stored->access_token);
        $this->assertSame($this->longToken('refresh'), $stored->refresh_token);
        $this->assertSame($this->longToken('app'), $stored->application_token);
        $this->assertSame(['task', 'user', 'placement'], $stored->scope);
    }

    public function test_длинные_токены_пользователя_помещаются_в_колонки(): void
    {
        $portal = Portal::create([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'is_active' => true,
        ]);

        $user = PortalUser::create([
            'portal_id' => $portal->id,
            'bitrix_user_id' => 42,
            'name' => 'Иван Петров',
            'access_token' => $this->longToken('user-access'),
            'refresh_token' => $this->longToken('user-refresh'),
            'token_expires_at' => now()->addHour(),
        ]);

        $stored = PortalUser::findOrFail($user->id);

        $this->assertSame($this->longToken('user-access'), $stored->access_token);
        $this->assertSame($this->longToken('user-refresh'), $stored->refresh_token);
    }

    public function test_токены_лежат_в_базе_зашифрованными(): void
    {
        Portal::create([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'секретный-токен',
            'is_active' => true,
        ]);

        // Дамп базы не должен быть дампом доступов ко всем порталам.
        $raw = DB::table('portals')->where('member_id', 'member-123')->value('access_token');

        $this->assertNotSame('секретный-токен', $raw);
        $this->assertStringNotContainsString('секретный-токен', $raw);
    }

    public function test_один_портал_не_заводится_дважды(): void
    {
        $attributes = [
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'is_active' => true,
        ];

        Portal::create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        Portal::create($attributes);
    }
}
