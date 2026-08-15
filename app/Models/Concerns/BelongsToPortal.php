<?php

namespace App\Models\Concerns;

use App\Models\Portal;
use App\Models\Scopes\PortalScope;
use App\Support\PortalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Привязка модели к порталу.
 *
 * Подключайте к каждой доменной модели (доски, колонки, состояния задач).
 * Скоуп навешивается глобально, поэтому забыть отфильтровать по порталу
 * в контроллере невозможно — это и есть защита от утечки данных между
 * клиентами, когда приложение станет тиражным.
 */
trait BelongsToPortal
{
    public static function bootBelongsToPortal(): void
    {
        static::addGlobalScope(new PortalScope);

        static::creating(function ($model) {
            if ($model->portal_id === null) {
                $model->portal_id = PortalContext::portalId();
            }
        });
    }

    /** @return BelongsTo<Portal, $this> */
    public function portal(): BelongsTo
    {
        return $this->belongsTo(Portal::class);
    }

    /**
     * Снять фильтр по порталу — для консольных команд и фоновых задач,
     * которые сознательно ходят по всем порталам сразу.
     */
    public function scopeAcrossPortals(Builder $query): Builder
    {
        return $query->withoutGlobalScope(PortalScope::class);
    }
}
