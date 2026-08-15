<?php

namespace App\Http\Middleware;

use App\Models\Portal;
use App\Models\PortalUser;
use App\Support\PortalContext;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * Восстановление портала и пользователя для текущего запроса.
 *
 * Основной источник — сессия, заполненная EntryController. Резервный —
 * подписанный токен в заголовке: приложение живёт в iframe стороннего
 * домена, и если браузер режет сторонние cookie, сессии просто не будет.
 * Токен кладётся в страницу при входе и досылается фронтендом.
 */
class ResolveBitrixPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        [$portalId, $userId] = $this->fromSession($request);

        if ($portalId === null) {
            [$portalId, $userId] = $this->fromHeaderToken($request);
        }

        if ($portalId === null) {
            return $this->reject($request);
        }

        $portal = Portal::query()->where('is_active', true)->find($portalId);

        if (! $portal) {
            return $this->reject($request);
        }

        $user = $userId
            ? PortalUser::query()->where('portal_id', $portal->id)->find($userId)
            : null;

        PortalContext::set($portal, $user);

        try {
            return $next($request);
        } finally {
            PortalContext::clear();
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    protected function fromSession(Request $request): array
    {
        return [
            $request->session()->get('bitrix.portal_id'),
            $request->session()->get('bitrix.user_id'),
        ];
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    protected function fromHeaderToken(Request $request): array
    {
        $token = $request->header('X-Bitrix-Context');

        if (! $token) {
            return [null, null];
        }

        try {
            $payload = Crypt::decrypt($token);
        } catch (DecryptException) {
            return [null, null];
        }

        if (! is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return [null, null];
        }

        return [$payload['portal_id'] ?? null, $payload['user_id'] ?? null];
    }

    /**
     * Токен контекста для передачи на фронтенд.
     */
    public static function issueToken(Portal $portal, ?PortalUser $user, int $ttlHours = 12): string
    {
        return Crypt::encrypt([
            'portal_id' => $portal->id,
            'user_id' => $user?->id,
            'exp' => time() + $ttlHours * 3600,
        ]);
    }

    protected function reject(Request $request): Response
    {
        if ($request->header('X-Inertia')) {
            // Инерция не умеет показывать 403 внутри фрейма осмысленно —
            // отправляем на полный перезаход, портал выдаст новые токены.
            return response('', 409)->header('X-Inertia-Location', url('/'));
        }

        return response()->view('bitrix.install-failed', [
            'message' => 'Сессия с порталом потеряна. Закройте и откройте приложение в Битрикс24 заново.',
        ], 403);
    }
}
