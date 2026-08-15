<?php

namespace App\Models;

use Database\Factories\PortalUserFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * Пользователь портала.
 *
 * Реализует Authenticatable, чтобы работали стандартные Laravel-механизмы
 * (auth()->user(), Gate, политики), хотя логин происходит не по паролю,
 * а по рукопожатию с Битриксом при открытии iframe.
 *
 * @property int $portal_id
 * @property int $bitrix_user_id
 * @property bool $is_admin
 */
class PortalUser extends Model implements Authenticatable
{
    use Authorizable;
    /** @use HasFactory<PortalUserFactory> */
    use HasFactory;

    use \Illuminate\Auth\Authenticatable;

    protected $fillable = [
        'portal_id',
        'bitrix_user_id',
        'name',
        'email',
        'avatar',
        'position',
        'timezone',
        'is_admin',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'last_login_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /** @return BelongsTo<Portal, $this> */
    public function portal(): BelongsTo
    {
        return $this->belongsTo(Portal::class);
    }

    public function tokenExpired(): bool
    {
        if (! $this->access_token) {
            return true;
        }

        return $this->token_expires_at === null
            || $this->token_expires_at->isBefore(now()->addMinute());
    }
}
