<?php

namespace App\Support;

use App\Models\Portal;
use App\Models\PortalUser;
use RuntimeException;

/**
 * Текущий портал и пользователь для запроса.
 *
 * Заполняется мидлварой ResolveBitrixPortal на входе в приложение и
 * вручную — в фоновых задачах (Bitrix24::forPortal / PortalContext::run).
 */
class PortalContext
{
    protected static ?Portal $portal = null;

    protected static ?PortalUser $user = null;

    public static function set(?Portal $portal, ?PortalUser $user = null): void
    {
        static::$portal = $portal;
        static::$user = $user;
    }

    public static function clear(): void
    {
        static::$portal = null;
        static::$user = null;
    }

    public static function portal(): ?Portal
    {
        return static::$portal;
    }

    public static function portalId(): ?int
    {
        return static::$portal?->id;
    }

    public static function user(): ?PortalUser
    {
        return static::$user;
    }

    public static function has(): bool
    {
        return static::$portal !== null;
    }

    /**
     * Портал или исключение — для кода, который без портала бессмыслен.
     */
    public static function portalOrFail(): Portal
    {
        return static::$portal ?? throw new RuntimeException(
            'Контекст портала не установлен. Запрос вне iframe Битрикс24 либо '
            .'фоновая задача без PortalContext::run().'
        );
    }

    /**
     * Выполнить замыкание в контексте конкретного портала.
     *
     * Нужно в очередях и командах, где глобальный скоуп иначе не сработает:
     *
     *     PortalContext::run($portal, fn () => Board::query()->get());
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Portal $portal, callable $callback, ?PortalUser $user = null): mixed
    {
        $previousPortal = static::$portal;
        $previousUser = static::$user;

        static::set($portal, $user);

        try {
            return $callback();
        } finally {
            static::set($previousPortal, $previousUser);
        }
    }
}
