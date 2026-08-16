<?php

namespace App\Services\Kanban;

use App\Enums\TaskStatus;
use App\Models\Board;
use App\Models\PortalUser;
use App\Support\PortalContext;
use Illuminate\Support\Facades\DB;

/**
 * Создание досок с готовым набором колонок.
 *
 * Доска без колонок нерабочая, а требовать от пользователя настроить их
 * до первого запуска — верный способ получить пустой экран.
 */
class BoardBuilder
{
    public function __construct(protected PortalDictionaries $dictionaries) {}

    public function create(
        string $name,
        ?int $groupId = null,
        ?PortalUser $author = null,
        ?array $filter = null,
    ): Board {
        $portal = PortalContext::portalOrFail();

        // Справочники нужны до первой синхронизации: иначе задачи придут
        // без дорожек и без приоритетов, и раскладывать их придётся руками.
        $this->dictionaries->ensure($portal);

        return DB::transaction(function () use ($name, $groupId, $author, $filter, $portal) {
            $board = Board::create([
                'portal_id' => $portal->id,
                'name' => $name,
                'bitrix_group_id' => $groupId,
                'filter' => $filter,
                'created_by' => $author?->id,
            ]);

            $this->addDefaultColumns($board);

            return $board->load('columns');
        });
    }

    /**
     * Колонки по умолчанию — зеркало штатных статусов Битрикса.
     *
     * Дальше пользователь добавляет свои: смысл приложения в том, что
     * колонок может быть больше, чем статусов у портала.
     */
    public function addDefaultColumns(Board $board): void
    {
        foreach (TaskStatus::defaultColumns() as $position => $column) {
            $board->columns()->create([
                'portal_id' => $board->portal_id,
                'name' => $column['name'],
                'color' => $column['color'],
                'position' => $position,
                'bitrix_status' => $column['status']->value,
                'is_default' => $position === 0,
                'is_final' => $column['final'],
            ]);
        }
    }
}
