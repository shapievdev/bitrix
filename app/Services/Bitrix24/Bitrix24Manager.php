<?php

namespace App\Services\Bitrix24;

use App\Models\Portal;
use App\Models\PortalUser;
use App\Support\PortalContext;
use RuntimeException;

class Bitrix24Manager
{
    public function __construct(
        protected TokenManager $tokens,
        protected PortalThrottle $throttle,
    ) {}

    /**
     * Клиент с токенами портала.
     *
     * Действия выполняются от имени приложения. Подходит для фоновой
     * синхронизации; в задачах автором будет отображаться приложение.
     */
    public function forPortal(Portal $portal): Bitrix24Client
    {
        return new Bitrix24Client($portal, null, $this->tokens, $this->throttle);
    }

    /**
     * Клиент с токенами конкретного пользователя.
     *
     * Нужен, когда важно авторство и права: Битрикс применит именно
     * права этого сотрудника, и он же будет автором изменений.
     */
    public function forUser(PortalUser $user): Bitrix24Client
    {
        $user->loadMissing('portal');

        return new Bitrix24Client($user->portal, $user, $this->tokens, $this->throttle);
    }

    /**
     * Клиент с только что полученным токеном, ещё не сохранённым в базе.
     *
     * Нужен ровно в одном месте — на входе из iframe, чтобы проверить
     * присланный AUTH_ID и вытащить профиль до создания пользователя.
     */
    public function withToken(Portal $portal, TokenSet $tokens): Bitrix24Client
    {
        return new Bitrix24Client($portal, null, $this->tokens, $this->throttle, $tokens);
    }

    /**
     * Клиент для текущего запроса: пользователь из iframe, если он есть.
     */
    public function current(): Bitrix24Client
    {
        if ($user = PortalContext::user()) {
            return $this->forUser($user);
        }

        if ($portal = PortalContext::portal()) {
            return $this->forPortal($portal);
        }

        throw new RuntimeException('Нет контекста портала: вызов Bitrix24::current() вне запроса из Битрикс24.');
    }

    public function tokens(): TokenManager
    {
        return $this->tokens;
    }
}
