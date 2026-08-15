<?php

namespace App\Services\Bitrix24\Exceptions;

/**
 * Refresh-токен больше не действителен.
 *
 * Практически всегда означает, что приложение удалили с портала или
 * переустановили. Портал следует пометить неактивным и не долбить REST.
 */
class TokenRefreshFailed extends Bitrix24Exception {}
