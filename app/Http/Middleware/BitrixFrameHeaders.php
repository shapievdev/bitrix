<?php

namespace App\Http\Middleware;

use App\Support\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Разрешение на встраивание в iframe портала.
 *
 * Приложение всегда открывается внутри Битрикс24, поэтому запрет на
 * фрейминг надо снять — но не для всех, а только для доменов порталов.
 * X-Frame-Options удаляется намеренно: он не понимает списки доменов и
 * в связке с CSP только мешает.
 */
class BitrixFrameHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->remove('X-Frame-Options');

        $ancestors = config('bitrix24.frame_ancestors');

        // Домен текущего портала может быть собственным (app.company.ru),
        // а не *.bitrix24.ru — добавляем его отдельно.
        if ($portal = PortalContext::portal()) {
            $ancestors[] = 'https://'.$portal->domain;
        }

        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors '.implode(' ', array_unique($ancestors)),
        );

        return $response;
    }
}
