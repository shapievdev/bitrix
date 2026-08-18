<?php

namespace App\Http\Controllers\Bitrix24;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBitrixEvent;
use App\Models\Portal;
use App\Services\Bitrix24\PortalRegistrar;
use App\Services\Bitrix24\TokenSet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Приёмник исходящих событий Битрикс24.
 *
 * Адрес обработчика публичный, поэтому подлинность проверяется по
 * application_token: он выдаётся порталу при установке и известен
 * только Битриксу и нам. Без этой проверки кто угодно мог бы слать
 * сюда поддельные события.
 */
class EventController extends Controller
{
    public function __construct(protected PortalRegistrar $registrar) {}

    public function __invoke(Request $request)
    {
        $event = strtoupper((string) $request->input('event'));
        $auth = $request->input('auth', []);
        $data = $request->input('data', []);

        if ($event === '') {
            return response()->noContent();
        }

        // Установка — единственное событие, приходящее до того, как у нас
        // появился application_token. Именно оно его и приносит.
        if ($event === 'ONAPPINSTALL') {
            $portal = $this->registrar->upsertPortal(
                TokenSet::fromEventAuth($auth),
                ['app_version' => $data['VERSION'] ?? null],
            );

            Log::info('Bitrix24: получено ONAPPINSTALL', ['portal' => $portal->domain]);

            return response()->noContent();
        }

        $portal = Portal::query()
            ->where('member_id', $auth['member_id'] ?? '')
            ->first();

        if (! $portal || ! $this->tokenMatches($portal, $auth['application_token'] ?? null)) {
            // Отпечатки, а не сами токены: по ним видно, разошлись ли
            // значения или токена нет вовсе, но подобрать секрет нельзя.
            Log::warning('Bitrix24: событие с неверным application_token отклонено', [
                'event' => $event,
                'member_id' => $auth['member_id'] ?? null,
                'ip' => $request->ip(),
                'прислан' => $this->fingerprint($auth['application_token'] ?? null),
                'сохранён' => $this->fingerprint($portal?->application_token),
            ]);

            return response()->noContent(403);
        }

        if ($event === 'ONAPPUNINSTALL') {
            $portal->forceFill([
                'is_active' => false,
                'uninstalled_at' => now(),
                'access_token' => null,
                'refresh_token' => null,
            ])->save();

            Log::info('Bitrix24: приложение удалено с портала', ['portal' => $portal->domain]);

            return response()->noContent();
        }

        // Битрикс ждёт быстрый ответ и повторяет доставку при таймауте.
        // Разбор события уводим в очередь, чтобы не держать соединение.
        ProcessBitrixEvent::dispatch($portal->id, $event, $data);

        return response()->noContent();
    }

    /**
     * Короткий отпечаток токена для логов: сравнить можно, восстановить нет.
     */
    protected function fingerprint(?string $token): string
    {
        return $token ? hash('crc32b', $token) : 'нет';
    }

    protected function tokenMatches(Portal $portal, ?string $token): bool
    {
        if (! $token || ! $portal->application_token) {
            return false;
        }

        return hash_equals($portal->application_token, $token);
    }
}
