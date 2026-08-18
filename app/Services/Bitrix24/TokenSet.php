<?php

namespace App\Services\Bitrix24;

use Illuminate\Support\Carbon;

/**
 * Пара токенов с временем жизни.
 *
 * Битрикс присылает токены в трёх разных форматах — в POST при входе в
 * iframe (AUTH_ID/REFRESH_ID/AUTH_EXPIRES), в теле события (auth[...])
 * и в ответе oauth/token (access_token/expires_in). Разбор всех трёх
 * собран здесь, чтобы остальной код видел одну структуру.
 */
readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public Carbon $expiresAt,
        public ?string $memberId = null,
        public ?string $domain = null,
        public ?string $applicationToken = null,
        public ?array $scope = null,
    ) {}

    /**
     * Ответ oauth/token: access_token, refresh_token, expires_in, member_id...
     */
    public static function fromOAuthResponse(array $data): self
    {
        return new self(
            accessToken: $data['access_token'],
            refreshToken: $data['refresh_token'] ?? null,
            expiresAt: static::expiry($data['expires_in'] ?? null, $data['expires'] ?? null),
            memberId: $data['member_id'] ?? null,
            domain: $data['domain'] ?? $data['client_endpoint'] ?? null,
            scope: isset($data['scope']) ? array_filter(explode(',', $data['scope'])) : null,
        );
    }

    /**
     * POST-параметры входа в iframe: AUTH_ID, REFRESH_ID, AUTH_EXPIRES.
     *
     * application_token здесь взять неоткуда: APP_SID — идентификатор
     * конкретного открытия фрейма, он меняется при каждом входе и к
     * подписи событий отношения не имеет. Принимать его за
     * application_token нельзя — он затирает настоящий, и портал теряет
     * возможность доказать подлинность своих событий.
     */
    public static function fromPlacementRequest(array $data): self
    {
        return new self(
            accessToken: $data['AUTH_ID'],
            refreshToken: $data['REFRESH_ID'] ?? null,
            expiresAt: now()->addSeconds((int) ($data['AUTH_EXPIRES'] ?? 3600)),
            memberId: $data['member_id'] ?? null,
            domain: $data['DOMAIN'] ?? null,
        );
    }

    /**
     * Тело события: auth[access_token], auth[expires_in], auth[domain]...
     */
    public static function fromEventAuth(array $auth): self
    {
        return new self(
            accessToken: $auth['access_token'],
            refreshToken: $auth['refresh_token'] ?? null,
            expiresAt: static::expiry($auth['expires_in'] ?? null, $auth['expires'] ?? null),
            memberId: $auth['member_id'] ?? null,
            domain: $auth['domain'] ?? null,
            applicationToken: $auth['application_token'] ?? null,
            scope: isset($auth['scope']) ? array_filter(explode(',', $auth['scope'])) : null,
        );
    }

    /**
     * expires_in — секунды до истечения, expires — unix-время истечения.
     * Приходит то одно, то другое в зависимости от метода.
     */
    protected static function expiry(mixed $expiresIn, mixed $expiresAt): Carbon
    {
        if ($expiresAt !== null && (int) $expiresAt > 0) {
            return Carbon::createFromTimestamp((int) $expiresAt);
        }

        return now()->addSeconds((int) ($expiresIn ?: 3600));
    }
}
