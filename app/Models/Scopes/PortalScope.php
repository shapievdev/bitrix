<?php

namespace App\Models\Scopes;

use App\Support\PortalContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class PortalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $portalId = PortalContext::portalId();

        if ($portalId === null) {
            // Нет контекста — нет и данных. Исключений быть не должно:
            // послабление «в консоли не фильтруем» делало скоуп пустышкой
            // во всём наборе тестов, то есть ровно там, где утечку между
            // порталами и надо ловить.
            //
            // Консольным командам и очередям положено оборачиваться в
            // PortalContext::run(), а осознанный обход всех порталов
            // запрашивается явно через ->acrossPortals().
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('portal_id'), $portalId);
    }
}
