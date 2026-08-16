<?php

namespace App\Services\Kanban;

use App\Facades\Bitrix24;
use App\Models\Department;
use App\Models\Portal;
use App\Models\TaskPriority;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use Illuminate\Support\Facades\Log;

/**
 * Справочники портала: подразделения и приоритеты.
 *
 * Заводятся один раз при первой доске. Пустой экран с предложением
 * «сначала настройте справочники» — верный способ, чтобы приложением
 * никто не воспользовался.
 */
class PortalDictionaries
{
    /**
     * Создать наборы по умолчанию, если их ещё нет.
     */
    public function ensure(Portal $portal): void
    {
        if (TaskPriority::query()->count() === 0) {
            foreach (TaskPriority::defaults() as $priority) {
                TaskPriority::create([
                    'portal_id' => $portal->id,
                    'name' => $priority['name'],
                    'color' => $priority['color'],
                    'weight' => $priority['weight'],
                    'bitrix_priority' => $priority['bitrix'],
                    'is_default' => $priority['default'],
                ]);
            }
        }

        if (Department::query()->count() === 0) {
            $this->seedDepartments($portal);
        }
    }

    /**
     * Подтянуть подразделения из оргструктуры портала.
     *
     * Названия отделов у клиента уже есть — переписывать их руками
     * бессмысленно. Связь по bitrix_department_id заодно включает
     * автоподстановку дорожки по ответственному.
     *
     * @return int Сколько подразделений добавлено.
     */
    public function importFromBitrix(Portal $portal): int
    {
        try {
            $departments = Bitrix24::forPortal($portal)->call('department.get');
        } catch (Bitrix24Exception $e) {
            // Метод department.get требует права department, которых у
            // приложения может не быть. Это не повод ломать настройку —
            // подразделения всегда можно завести руками.
            Log::info('Канбан: оргструктура недоступна', [
                'portal' => $portal->domain,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if (! is_array($departments)) {
            return 0;
        }

        $existing = Department::query()->pluck('bitrix_department_id')->filter()->all();
        $position = (int) Department::query()->max('position');
        $added = 0;

        foreach ($departments as $department) {
            $bitrixId = (int) ($department['ID'] ?? 0);

            if ($bitrixId === 0 || in_array($bitrixId, $existing, true)) {
                continue;
            }

            Department::create([
                'portal_id' => $portal->id,
                'name' => $department['NAME'] ?? "Отдел #{$bitrixId}",
                'color' => $this->paletteColor($added),
                'position' => ++$position,
                'bitrix_department_id' => $bitrixId,
            ]);

            $added++;
        }

        return $added;
    }

    /**
     * Стартовый набор подразделений.
     *
     * Сначала пробуем оргструктуру портала; если прав на неё нет —
     * кладём типовые отделы, которые пользователь переименует под себя.
     */
    protected function seedDepartments(Portal $portal): void
    {
        if ($this->importFromBitrix($portal) > 0) {
            Department::query()->orderBy('position')->first()?->update(['is_default' => true]);

            return;
        }

        $defaults = ['Коммерческий отдел', 'Операционный отдел', 'Производство', 'Администрация'];

        foreach ($defaults as $position => $name) {
            Department::create([
                'portal_id' => $portal->id,
                'name' => $name,
                'color' => $this->paletteColor($position),
                'position' => $position,
                'is_default' => $position === 0,
            ]);
        }
    }

    protected function paletteColor(int $index): string
    {
        $palette = ['#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#ec4899', '#06b6d4', '#f97316'];

        return $palette[$index % count($palette)];
    }
}
