<?php

namespace App\Models;

use Database\Factories\PortalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Портал Битрикс24, на котором установлено приложение.
 *
 * @property string $member_id
 * @property string $domain
 * @property string $kind
 * @property ?string $access_token
 * @property ?string $refresh_token
 * @property ?Carbon $token_expires_at
 * @property ?string $application_token
 * @property ?array $scope
 * @property bool $is_active
 */
class Portal extends Model
{
    /** @use HasFactory<PortalFactory> */
    use HasFactory;

    protected $fillable = [
        'member_id',
        'domain',
        'kind',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'application_token',
        'scope',
        'app_version',
        'lang',
        'is_active',
        'installed_at',
        'uninstalled_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'application_token',
    ];

    protected function casts(): array
    {
        return [
            // Токены — это ключи от чужого портала. В базе держим шифрованными,
            // чтобы дамп БД не был дампом доступов ко всем клиентам.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'application_token' => 'encrypted',
            'scope' => 'array',
            'token_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PortalUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(PortalUser::class);
    }

    /**
     * Базовый URL REST-методов портала.
     */
    public function restUrl(string $method): string
    {
        return "https://{$this->domain}/rest/{$method}.json";
    }

    /**
     * Куда идти за обновлением токена.
     *
     * Облако обновляет токены централизованно, коробка — сама у себя.
     */
    public function oauthTokenUrl(): string
    {
        if ($this->kind === 'onpremise') {
            return "https://{$this->domain}".config('bitrix24.oauth.onpremise_token_path');
        }

        return config('bitrix24.oauth.cloud_token_url');
    }

    /**
     * Токен истёк или истечёт в ближайшую минуту.
     *
     * Запас нужен, чтобы не отправить запрос с токеном, который протухнет,
     * пока запрос летит до Битрикса.
     */
    public function tokenExpired(): bool
    {
        if (! $this->access_token) {
            return true;
        }

        return $this->token_expires_at === null
            || $this->token_expires_at->isBefore(now()->addMinute());
    }
}
