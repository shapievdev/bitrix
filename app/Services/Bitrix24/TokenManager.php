<?php

namespace App\Services\Bitrix24;

use App\Models\Portal;
use App\Models\PortalUser;
use App\Services\Bitrix24\Exceptions\TokenRefreshFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenManager
{
    /**
     * Обновить токен портала.
     *
     * Обёрнуто в блокировку: без неё параллельные воркеры одновременно
     * дёрнут refresh, и все, кроме одного, получат уже недействительный
     * refresh_token — Битрикс инвалидирует его при каждом обмене.
     *
     * @param  bool  $force  Обновлять, даже если по нашим часам токен ещё жив.
     *                       Нужно, когда expired_token пришёл от самого портала:
     *                       его мнение о сроке жизни главнее нашего.
     */
    public function refreshPortal(Portal $portal, bool $force = false): Portal
    {
        $lock = Cache::lock("bitrix24:refresh:portal:{$portal->id}", 30);
        $lock->block(30);

        $tokenBefore = $portal->access_token;

        try {
            $portal->refresh();

            // Пока ждали блокировку, токен мог обновить другой процесс —
            // тогда обновлять повторно не нужно даже при force.
            if ($force ? $portal->access_token !== $tokenBefore : ! $portal->tokenExpired()) {
                return $portal;
            }

            $tokens = $this->exchangeRefreshToken($portal, $portal->refresh_token);

            $portal->forceFill([
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $portal->refresh_token,
                'token_expires_at' => $tokens->expiresAt,
            ])->save();

            return $portal;
        } finally {
            $lock->release();
        }
    }

    public function refreshUser(PortalUser $user, bool $force = false): PortalUser
    {
        $lock = Cache::lock("bitrix24:refresh:user:{$user->id}", 30);
        $lock->block(30);

        $tokenBefore = $user->access_token;

        try {
            $user->refresh();

            if ($force ? $user->access_token !== $tokenBefore : ! $user->tokenExpired()) {
                return $user;
            }

            $tokens = $this->exchangeRefreshToken($user->portal, $user->refresh_token);

            $user->forceFill([
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $user->refresh_token,
                'token_expires_at' => $tokens->expiresAt,
            ])->save();

            return $user;
        } finally {
            $lock->release();
        }
    }

    protected function exchangeRefreshToken(Portal $portal, ?string $refreshToken): TokenSet
    {
        if (! $refreshToken) {
            throw new TokenRefreshFailed(
                "Портал {$portal->domain}: нет refresh-токена, требуется переустановка приложения."
            );
        }

        $response = Http::asForm()
            ->timeout(config('bitrix24.http.timeout'))
            ->connectTimeout(config('bitrix24.http.connect_timeout'))
            ->get($portal->oauthTokenUrl(), [
                'grant_type' => 'refresh_token',
                'client_id' => config('bitrix24.client_id'),
                'client_secret' => config('bitrix24.client_secret'),
                'refresh_token' => $refreshToken,
            ]);

        $data = $response->json() ?? [];

        if ($response->failed() || ! isset($data['access_token'])) {
            $error = $data['error'] ?? 'http_'.$response->status();

            Log::warning('Bitrix24: не удалось обновить токен', [
                'portal' => $portal->domain,
                'error' => $error,
                'description' => $data['error_description'] ?? null,
            ]);

            // invalid_grant — приложение снято с портала. Дальнейшие попытки
            // бессмысленны, отмечаем портал неактивным.
            if (in_array($error, ['invalid_grant', 'invalid_token', 'NO_AUTH_FOUND'], true)) {
                $portal->forceFill(['is_active' => false])->save();
            }

            throw new TokenRefreshFailed(
                "Портал {$portal->domain}: обновление токена не удалось ({$error}).",
                errorCode: $error,
                context: $data,
            );
        }

        return TokenSet::fromOAuthResponse($data);
    }
}
