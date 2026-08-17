<?php

namespace App\Services\Kanban;

use App\Facades\Bitrix24;
use App\Models\Department;
use App\Models\Portal;
use App\Models\TaskPriority;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use Illuminate\Support\Facades\Log;

/**
 * Справочники портала: оргструктура и приоритеты.
 */
class PortalDictionaries
{
    /**
     * Департаменты верхнего уровня навигации.
     *
     * В дереве портала они лежат на разной глубине: большинство под
     * «Исполнительным директором», а Служба безопасности и Тендерный
     * отдел — прямо под корнем. Вычислить их по родителю нельзя, поэтому
     * перечисляем по названию.
     */
    protected const PRIMARY = [
        'Коммерческий департамент',
        'Отдел Категорийного менеджмента',
        'Операционный департамент',
        'Финансовый департамент',
        'HR департамент',
        'IT Отдел',
        'Административно-хозяйственная часть',
        'Гипермаркет',
        'Тендерный отдел',
        'Служба безопасности',
    ];

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
            $this->importFromBitrix($portal);
        }
    }

    /**
     * Забрать оргструктуру портала вместе с иерархией.
     *
     * Импорт идемпотентный: узлы находятся по bitrix_department_id, так
     * что повторный запуск подхватывает новые отделы и не плодит копии.
     *
     * @return int Сколько узлов добавлено.
     */
    public function importFromBitrix(Portal $portal): int
    {
        try {
            $raw = Bitrix24::forPortal($portal)->call('department.get');
        } catch (Bitrix24Exception $e) {
            Log::info('Канбан: оргструктура недоступна', [
                'portal' => $portal->domain,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if (! is_array($raw) || $raw === []) {
            return 0;
        }

        $added = $this->upsertNodes($portal, $raw);
        $this->linkParents($portal);
        $this->markPrimary();

        return $added;
    }

    /**
     * Создать или обновить узлы по данным портала.
     *
     * @param  array<int, array<string, mixed>>  $raw
     */
    protected function upsertNodes(Portal $portal, array $raw): int
    {
        $existing = Department::query()
            ->whereNotNull('bitrix_department_id')
            ->get()
            ->keyBy('bitrix_department_id');

        $position = 0;
        $added = 0;

        foreach ($raw as $node) {
            $bitrixId = (int) ($node['ID'] ?? 0);

            if ($bitrixId === 0) {
                continue;
            }

            $attributes = [
                'name' => trim((string) ($node['NAME'] ?? "Отдел #{$bitrixId}")),
                'bitrix_parent_id' => ($node['PARENT'] ?? null) ? (int) $node['PARENT'] : null,
                'position' => $position++,
            ];

            if ($current = $existing->get($bitrixId)) {
                $current->update($attributes);

                continue;
            }

            Department::create($attributes + [
                'portal_id' => $portal->id,
                'bitrix_department_id' => $bitrixId,
                'color' => $this->paletteColor($added),
            ]);

            $added++;
        }

        return $added;
    }

    /**
     * Проставить родство внутри нашей таблицы.
     *
     * Отдельным проходом: на момент создания узла его родитель может быть
     * ещё не создан, и связать их сразу нельзя.
     */
    protected function linkParents(Portal $portal): void
    {
        $byBitrixId = Department::query()
            ->whereNotNull('bitrix_department_id')
            ->get()
            ->keyBy('bitrix_department_id');

        foreach ($byBitrixId as $department) {
            $parent = $department->bitrix_parent_id
                ? $byBitrixId->get($department->bitrix_parent_id)
                : null;

            if ($department->parent_id !== $parent?->id) {
                $department->forceFill(['parent_id' => $parent?->id])->save();
            }
        }
    }

    /**
     * Отметить департаменты верхнего уровня навигации.
     */
    protected function markPrimary(): void
    {
        $normalize = fn (string $name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
        $wanted = array_map($normalize, self::PRIMARY);

        foreach (Department::query()->get() as $department) {
            $isPrimary = in_array($normalize($department->name), $wanted, true);

            if ($department->is_primary !== $isPrimary) {
                $department->forceFill(['is_primary' => $isPrimary])->save();
            }
        }
    }

    protected function paletteColor(int $index): string
    {
        $palette = ['#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#ec4899', '#06b6d4', '#f97316', '#84cc16'];

        return $palette[$index % count($palette)];
    }
}
