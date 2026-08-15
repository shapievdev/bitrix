<?php

namespace App\Services\Bitrix24;

use Illuminate\Support\Facades\Cache;

/**
 * Ограничитель частоты запросов, отдельный для каждого портала.
 *
 * Битрикс считает лимит на портал, поэтому глобальный ограничитель
 * душил бы клиентов друг из-за друга. Реализован через блокировку в
 * кэше: очередь выстраивается корректно и при нескольких воркерах.
 */
class PortalThrottle
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $key, callable $callback): mixed
    {
        if (! config('bitrix24.throttle.enabled')) {
            return $callback();
        }

        $rps = max(0.1, (float) config('bitrix24.throttle.requests_per_second'));
        $interval = 1 / $rps;

        $lock = Cache::lock("bitrix24:throttle:lock:{$key}", 30);
        $lock->block(30);

        try {
            $stampKey = "bitrix24:throttle:last:{$key}";
            $last = (float) Cache::get($stampKey, 0);
            $wait = ($last + $interval) - microtime(true);

            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }

            Cache::put($stampKey, microtime(true), now()->addMinute());
        } finally {
            $lock->release();
        }

        return $callback();
    }
}
