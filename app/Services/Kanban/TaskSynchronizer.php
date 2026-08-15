<?php

namespace App\Services\Kanban;

use App\Facades\Bitrix24;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\TaskCard;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Перенос задач портала на доску.
 *
 * Два режима: полный обход (по расписанию и при создании доски) и
 * точечное обновление одной задачи по событию портала.
 */
class TaskSynchronizer
{
    /**
     * Поля, которые запрашиваем у портала.
     *
     * Список явный, а не «всё»: tasks.task.list с полным набором полей
     * тянет комментарии и чек-листы, из-за чего страница на 50 задач
     * начинает отвечать секундами.
     */
    protected const FIELDS = [
        'ID', 'TITLE', 'STATUS', 'PRIORITY', 'RESPONSIBLE_ID',
        'CREATED_BY', 'DEADLINE', 'CLOSED_DATE', 'GROUP_ID',
        'CHANGED_DATE', 'TAGS',
    ];

    public function __construct(protected CardMover $mover) {}

    /**
     * Обойти все задачи, попадающие под фильтр доски.
     *
     * @return array{created: int, updated: int, removed: int}
     */
    public function syncBoard(Board $board): array
    {
        $board->loadMissing('columns');

        if ($board->columns->isEmpty()) {
            throw new \RuntimeException("Доска «{$board->name}» без колонок — синхронизировать некуда.");
        }

        $stats = ['created' => 0, 'updated' => 0, 'removed' => 0];
        $seen = [];

        $tasks = Bitrix24::forPortal($board->portal)->list('tasks.task.list', [
            'filter' => $board->taskFilter(),
            'select' => self::FIELDS,
            // Без этого Битрикс на каждой странице считает общее количество
            // заново — на больших проектах это заметно дороже самой выборки.
            'start' => 0,
        ], 'tasks');

        foreach ($tasks as $task) {
            $taskId = (int) ($task['id'] ?? $task['ID'] ?? 0);

            if ($taskId === 0) {
                continue;
            }

            $seen[] = $taskId;

            $card = $board->cards()->firstWhere('bitrix_task_id', $taskId);

            $card
                ? $stats['updated'] += (int) $this->updateCard($card, $task)
                : $stats['created'] += (int) (bool) $this->createCard($board, $task);
        }

        $stats['removed'] = $this->removeVanished($board, $seen);

        $board->forceFill(['synced_at' => now()])->save();

        return $stats;
    }

    /**
     * Обновить одну задачу — обработчик события ONTASKUPDATE.
     *
     * Задача может относиться сразу к нескольким доскам (общая и по
     * проекту), поэтому проходим по всем, где она уже есть, и по тем,
     * куда она теперь подходит по фильтру группы.
     */
    public function syncTask(int $taskId): int
    {
        try {
            $task = Bitrix24::current()->call('tasks.task.get', [
                'taskId' => $taskId,
                'select' => self::FIELDS,
            ]);
        } catch (Bitrix24Exception $e) {
            // Задачу могли удалить между событием и нашим запросом.
            Log::info('Канбан: задача недоступна', ['task' => $taskId, 'error' => $e->getMessage()]);

            $this->forgetTask($taskId);

            return 0;
        }

        $task = $task['task'] ?? $task;
        $touched = 0;

        foreach ($this->boardsFor($task) as $board) {
            $card = $board->cards()->firstWhere('bitrix_task_id', $taskId);

            if ($card) {
                $this->updateCard($card, $task);
            } else {
                $this->createCard($board, $task);
            }

            $touched++;
        }

        return $touched;
    }

    /**
     * Снять задачу со всех досок — обработчик ONTASKDELETE.
     */
    public function forgetTask(int $taskId): void
    {
        TaskCard::query()->where('bitrix_task_id', $taskId)->delete();
    }

    /**
     * Доски, на которых задача должна присутствовать.
     *
     * @return Collection<int, Board>
     */
    protected function boardsFor(array $task): Collection
    {
        $groupId = (int) ($task['groupId'] ?? $task['GROUP_ID'] ?? 0);
        $taskId = (int) ($task['id'] ?? $task['ID'] ?? 0);

        return Board::query()
            ->with('columns')
            ->where(function ($query) use ($groupId, $taskId) {
                // Доски проекта, к которому относится задача.
                $query->when($groupId > 0, fn ($q) => $q->orWhere('bitrix_group_id', $groupId));

                // Доски без привязки к проекту — туда задача попадает
                // только если уже есть карточка: вычислять соответствие
                // произвольному фильтру у себя мы не беремся, это работа
                // портала, и делает он её при полной синхронизации.
                $query->orWhereHas('cards', fn ($q) => $q->where('bitrix_task_id', $taskId));
            })
            ->get();
    }

    protected function createCard(Board $board, array $task): ?TaskCard
    {
        $column = $this->columnFor($board, $task);

        if (! $column) {
            return null;
        }

        $attributes = $this->attributesFrom($task);

        return $board->cards()->create($attributes + [
            'portal_id' => $board->portal_id,
            'board_column_id' => $column->id,
            'bitrix_task_id' => (int) ($task['id'] ?? $task['ID']),
            'position' => $board->cards()->where('board_column_id', $column->id)->count(),
        ]);
    }

    /**
     * @return bool Было ли что-то изменено.
     */
    protected function updateCard(TaskCard $card, array $task): bool
    {
        $attributes = $this->attributesFrom($task);
        $previousStatus = $card->bitrix_status;

        $card->fill($attributes);
        $changed = $card->isDirty();
        $card->save();

        // Двигаем карточку только когда статус в Битриксе именно сменился.
        //
        // Сверять текущую колонку со статусом нельзя: карточка, которую
        // увели в собственную колонку без привязки к статусу — а ради этого
        // приложение и делается, — при каждой синхронизации возвращалась бы
        // обратно в колонку своего штатного статуса.
        $newStatus = $attributes['bitrix_status'] ?? null;

        if ($newStatus !== null && $newStatus !== $previousStatus) {
            $this->mover->syncFromStatus($card, $newStatus, force: true);
        }

        return $changed;
    }

    /**
     * Колонка для новой карточки: по статусу задачи, иначе входная.
     */
    protected function columnFor(Board $board, array $task): ?BoardColumn
    {
        $status = (int) ($task['status'] ?? $task['STATUS'] ?? 0);

        return $board->columns->firstWhere('bitrix_status', $status)
            ?? $board->defaultColumn();
    }

    /**
     * Привести поля задачи к колонкам карточки.
     *
     * tasks.task.* отдаёт camelCase, событийные поля и старое task.item.*
     * — UPPER_CASE. Поддерживаем оба, чтобы источник данных не протекал
     * в остальной код.
     */
    protected function attributesFrom(array $task): array
    {
        $get = fn (string $camel, string $upper) => $task[$camel] ?? $task[$upper] ?? null;

        return [
            'title' => (string) ($get('title', 'TITLE') ?? 'Без названия'),
            'responsible_id' => $this->toId($get('responsibleId', 'RESPONSIBLE_ID')),
            'creator_id' => $this->toId($get('createdBy', 'CREATED_BY')),
            'bitrix_status' => $this->toId($get('status', 'STATUS')),
            'priority' => $this->toId($get('priority', 'PRIORITY')),
            'deadline' => $this->toDate($get('deadline', 'DEADLINE')),
            'closed_at' => $this->toDate($get('closedDate', 'CLOSED_DATE')),
            'fields' => [
                'tags' => $get('tags', 'TAGS') ?: [],
                'group_id' => $this->toId($get('groupId', 'GROUP_ID')),
            ],
            'synced_at' => now(),
        ];
    }

    protected function toId(mixed $value): ?int
    {
        return $value === null || $value === '' || $value === '0' ? null : (int) $value;
    }

    protected function toDate(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Убрать карточки задач, переставших подходить под фильтр.
     *
     * @param  array<int>  $seen
     */
    protected function removeVanished(Board $board, array $seen): int
    {
        return $board->cards()
            ->when($seen !== [], fn ($q) => $q->whereNotIn('bitrix_task_id', $seen))
            ->delete();
    }
}
