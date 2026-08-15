<?php

namespace App\Http\Middleware;

use App\Support\PortalContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Версия ассетов: при расхождении Инерция сама перезагрузит страницу.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $portal = PortalContext::portal();
        $user = PortalContext::user();

        return array_merge(parent::share($request), [
            'portal' => $portal ? [
                'domain' => $portal->domain,
                'lang' => $portal->lang,
            ] : null,

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'bitrix_id' => $user->bitrix_user_id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'is_admin' => $user->is_admin,
                ] : null,
            ],

            // Куда именно встроено приложение — из карточки задачи придёт
            // её ID, из левого меню ничего. Фронтенд решает, что показать.
            'placement' => [
                'code' => $request->session()->get('bitrix.placement', 'DEFAULT'),
                'options' => $request->session()->get('bitrix.placement_options', []),
            ],

            // Резервный контекст на случай, если браузер режет сторонние
            // cookie и сессия во фрейме не доживёт до следующего запроса.
            'contextToken' => $portal
                ? ResolveBitrixPortal::issueToken($portal, $user)
                : null,

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
