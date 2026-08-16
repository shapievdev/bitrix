<?php

namespace App\Services\Bitrix24;

use App\Models\Portal;
use App\Models\PortalUser;

/**
 * Заведение и обновление порталов и их пользователей.
 *
 * Единая точка, через которую токены попадают в базу: и при установке,
 * и при каждом входе в iframe, и при обработке событий.
 */
class PortalRegistrar
{
    public function __construct(protected Bitrix24Manager $bitrix) {}

    /**
     * Найти портал по member_id или завести новый, обновив токены.
     */
    public function upsertPortal(TokenSet $tokens, array $attributes = []): Portal
    {
        if (! $tokens->memberId) {
            throw new Exceptions\Bitrix24Exception('В запросе от Битрикс24 отсутствует member_id.');
        }

        $portal = Portal::firstOrNew(['member_id' => $tokens->memberId]);

        $portal->fill(array_filter([
            'domain' => $tokens->domain ? $this->normalizeDomain($tokens->domain) : null,
            'scope' => $tokens->scope,
        ], fn ($value) => $value !== null) + $attributes);

        $portal->access_token = $tokens->accessToken;
        $portal->token_expires_at = $tokens->expiresAt;

        // refresh_token приходит не во всех запросах — не затираем сохранённый.
        if ($tokens->refreshToken) {
            $portal->refresh_token = $tokens->refreshToken;
        }

        // application_token приходит только с ONAPPINSTALL. Он же — единственный
        // способ убедиться, что событие пришло от Битрикса, а не от постороннего.
        if ($tokens->applicationToken) {
            $portal->application_token = $tokens->applicationToken;
        }

        if (! $portal->exists) {
            $portal->kind = $attributes['kind'] ?? config('bitrix24.kind');
            $portal->installed_at = now();
        }

        $portal->is_active = true;
        $portal->uninstalled_at = null;
        $portal->save();

        return $portal;
    }

    /**
     * Синхронизировать пользователя, открывшего приложение.
     *
     * Данные берём из user.current — заодно это проверка, что присланный
     * AUTH_ID действительно выдан Битриксом, а не подобран.
     */
    public function upsertCurrentUser(Portal $portal, TokenSet $tokens): PortalUser
    {
        // Один batch вместо трёх запросов: профиль, признак администратора
        // (user.current его не отдаёт — нужен отдельный user.admin) и
        // выданные права (app.info их не возвращает — только метод scope).
        $response = $this->bitrix->withToken($portal, $tokens)->batch([
            'profile' => ['user.current', []],
            'is_admin' => ['user.admin', []],
            'scope' => ['scope', []],
        ]);

        $profile = $response['profile'] ?? null;

        if (! is_array($profile) || empty($profile['ID'])) {
            throw new Exceptions\Bitrix24Exception('Не удалось получить профиль пользователя по присланному токену.');
        }

        // Права перечитываем при каждом входе: администратор портала может
        // изменить их в карточке приложения уже после установки, и узнать
        // об этом иначе мы сможем только по отказу REST в самый неудобный момент.
        if (is_array($response['scope'] ?? null)) {
            $portal->forceFill(['scope' => $response['scope']])->save();
        }

        $user = PortalUser::firstOrNew([
            'portal_id' => $portal->id,
            'bitrix_user_id' => (int) $profile['ID'],
        ]);

        $user->fill([
            'name' => trim(($profile['NAME'] ?? '').' '.($profile['LAST_NAME'] ?? '')) ?: ($profile['EMAIL'] ?? null),
            'email' => $profile['EMAIL'] ?? null,
            'avatar' => $profile['PERSONAL_PHOTO'] ?? null,
            'position' => $profile['WORK_POSITION'] ?? null,
            'timezone' => $profile['TIME_ZONE'] ?? null,
            'is_admin' => (bool) ($response['is_admin'] ?? false),
            'access_token' => $tokens->accessToken,
            'token_expires_at' => $tokens->expiresAt,
            'last_login_at' => now(),
        ]);

        if ($tokens->refreshToken) {
            $user->refresh_token = $tokens->refreshToken;
        }

        $user->save();

        return $user;
    }

    /**
     * Домен может прийти как https://portal.bitrix24.ru/rest/ — приводим к хосту.
     */
    protected function normalizeDomain(string $domain): string
    {
        $host = parse_url($domain, PHP_URL_HOST);

        return $host ?: trim($domain, '/');
    }
}
