<?php

namespace App\Facades;

use App\Services\Bitrix24\Bitrix24Client;
use App\Services\Bitrix24\Bitrix24Manager;
use App\Services\Bitrix24\TokenManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Bitrix24Client forPortal(\App\Models\Portal $portal)
 * @method static Bitrix24Client forUser(\App\Models\PortalUser $user)
 * @method static Bitrix24Client withToken(\App\Models\Portal $portal, \App\Services\Bitrix24\TokenSet $tokens)
 * @method static Bitrix24Client current()
 * @method static TokenManager tokens()
 *
 * @see Bitrix24Manager
 */
class Bitrix24 extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Bitrix24Manager::class;
    }
}
