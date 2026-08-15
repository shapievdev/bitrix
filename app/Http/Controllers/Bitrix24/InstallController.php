<?php

namespace App\Http\Controllers\Bitrix24;

use App\Facades\Bitrix24;
use App\Http\Controllers\Controller;
use App\Models\Portal;
use App\Services\Bitrix24\PortalRegistrar;
use App\Services\Bitrix24\TokenSet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Установка приложения на портал.
 *
 * Битрикс дважды сообщает об установке: POST на «путь установки» (этот
 * контроллер, здесь же надо ответить страницей с BX24.installFinish) и
 * событием ONAPPINSTALL на обработчик событий — оно приносит
 * application_token. Оба пути ведут в PortalRegistrar.
 */
class InstallController extends Controller
{
    public function __construct(protected PortalRegistrar $registrar) {}

    public function __invoke(Request $request)
    {
        $params = $request->all();

        if (empty($params['AUTH_ID']) || empty($params['member_id'])) {
            Log::warning('Bitrix24: установка без данных авторизации', ['params' => array_keys($params)]);

            return response()->view('bitrix.install-failed', [
                'message' => 'Битрикс24 не передал данные авторизации. Проверьте настройки приложения.',
            ], 400);
        }

        $portal = $this->registrar->upsertPortal(
            TokenSet::fromPlacementRequest($params),
            ['app_version' => $params['version'] ?? null],
        );

        $this->registerHandlers($portal);

        Log::info('Bitrix24: приложение установлено', [
            'portal' => $portal->domain,
            'member_id' => $portal->member_id,
        ]);

        return response()->view('bitrix.install-finish');
    }

    /**
     * Подписаться на события и зарегистрировать места встраивания.
     *
     * Обе операции идемпотентны с оговоркой: повторный bind возвращает
     * ошибку «уже установлен», поэтому сначала снимаем старые привязки —
     * иначе при обновлении приложения останутся устаревшие адреса.
     */
    protected function registerHandlers(Portal $portal): void
    {
        $client = Bitrix24::forPortal($portal);
        $eventHandler = route('bitrix.event');

        $commands = [];

        foreach (config('bitrix24.events') as $index => $event) {
            $commands["unbind_event_{$index}"] = ['event.unbind', [
                'event' => $event,
                'handler' => $eventHandler,
            ]];
            $commands["bind_event_{$index}"] = ['event.bind', [
                'event' => $event,
                'handler' => $eventHandler,
            ]];
        }

        foreach (config('bitrix24.placements') as $index => $placement) {
            $handler = url($placement['handler']);

            $commands["unbind_placement_{$index}"] = ['placement.unbind', [
                'PLACEMENT' => $placement['code'],
                'HANDLER' => $handler,
            ]];
            $commands["bind_placement_{$index}"] = ['placement.bind', [
                'PLACEMENT' => $placement['code'],
                'HANDLER' => $handler,
                'TITLE' => $placement['title'],
                'DESCRIPTION' => $placement['description'] ?? '',
            ]];
        }

        if ($commands === []) {
            return;
        }

        // Ошибки отдельных команд логируются внутри batch: неудачный unbind
        // при первой установке — нормально и установку ломать не должен.
        $client->batch($commands);
    }
}
