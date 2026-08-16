<?php

namespace App\Services\Kanban;

use App\Facades\Bitrix24;
use App\Models\BoardColumn;
use App\Models\CardTransition;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Перемещение карточек по доске.
 *
 * Отвечает за три вещи сразу, потому что порознь они рассыпаются:
 * порядок внутри колонок, запись истории и отражение статуса в Битриксе.
 */
class CardMover
{
    /**
     * Переместить карточку в ячейку «колонка × дорожка» на заданную позицию.
     *
     * Ячейка задаётся всегда целиком: на доске с дорожками одна колонка —
     * это несколько независимых стопок карточек, и порядок в каждой свой.
     *
     * @param  ?int  $departmentId  Дорожка подразделения; null — «Без подразделения».
     * @param  ?int  $position  Позиция в ячейке, начиная с 0. null — в конец.
     * @param  bool  $pushToBitrix  Отправить ли новый статус на портал.
     */
    public function move(
        TaskCard $card,
        BoardColumn $target,
        ?int $departmentId = null,
        ?int $position = null,
        ?PortalUser $actor = null,
        bool $pushToBitrix = true,
    ): TaskCard {
        if ($target->board_id !== $card->board_id) {
            throw new \InvalidArgumentException('Колонка принадлежит другой доске.');
        }

        $source = $card->column;
        $sourceDepartmentId = $card->department_id;
        $enteredAt = $card->enteredColumnAt();

        DB::transaction(function () use (
            $card, $target, $departmentId, $position, $actor, $source, $sourceDepartmentId, $enteredAt
        ) {
            // Блокируем карточки обеих ячеек: два одновременных
            // перетаскивания иначе выдадут двум карточкам одну позицию.
            $this->lockCells([
                [$source?->id, $sourceDepartmentId],
                [$target->id, $departmentId],
            ]);

            $this->detach($card);
            $this->insert($card, $target, $departmentId, $position);

            // Дорожку сменили руками — автоподстановка по отделу
            // ответственного больше не должна её перебивать.
            if ($actor !== null && $departmentId !== $sourceDepartmentId) {
                $card->forceFill(['department_locked' => true])->save();
            }

            // Перемещение внутри колонки историей не считаем — иначе отчёт
            // о времени на этапе будет обнуляться при любой сортировке.
            if ($source?->id !== $target->id) {
                CardTransition::create([
                    'portal_id' => $card->portal_id,
                    'task_card_id' => $card->id,
                    'from_column_id' => $source?->id,
                    'to_column_id' => $target->id,
                    'moved_by' => $actor?->id,
                    // Carbon отдаёт дробные секунды — колонка целочисленная.
                    'seconds_in_previous' => $enteredAt === null
                        ? null
                        : (int) abs($enteredAt->diffInSeconds(now())),
                ]);
            }
        });

        if ($pushToBitrix && $source?->id !== $target->id) {
            $this->pushStatus($card, $target, $actor);
        }

        return $card->refresh();
    }

    /**
     * Разместить карточку в колонке, соответствующей статусу из Битрикса.
     *
     * Обратное направление синхронизации. Если текущая колонка уже
     * отражает этот статус, карточку не трогаем: иначе задача, лежащая
     * в «На проверке» с тем же статусом, что и «В работе», прыгала бы
     * туда-обратно при каждом событии.
     */
    public function syncFromStatus(TaskCard $card, int $bitrixStatus, bool $force = false): TaskCard
    {
        $card->loadMissing('column', 'board.columns');

        if (! $force && $card->column?->bitrix_status === $bitrixStatus) {
            return $card;
        }

        $target = $card->board->columns->firstWhere('bitrix_status', $bitrixStatus);

        if (! $target || $target->id === $card->board_column_id) {
            return $card;
        }

        // Дорожку сохраняем: сменился статус, а не подразделение.
        // pushToBitrix отключён намеренно: статус пришёл оттуда, отправлять
        // его обратно — прямой путь к бесконечному циклу событий.
        return $this->move($card, $target, $card->department_id, pushToBitrix: false);
    }

    /**
     * Отразить наш приоритет в штатном PRIORITY задачи.
     *
     * Ошибку REST не поднимаем по той же причине, что и со статусом:
     * приоритет у нас уже сохранён, а портал догонит при синхронизации.
     */
    public function pushPriority(TaskCard $card, TaskPriority $priority): void
    {
        if ($priority->bitrix_priority === null) {
            return;
        }

        try {
            Bitrix24::forPortal($card->portal)->call('tasks.task.update', [
                'taskId' => $card->bitrix_task_id,
                'fields' => ['PRIORITY' => $priority->bitrix_priority],
            ]);

            $card->forceFill(['priority' => $priority->bitrix_priority])->save();
        } catch (Bitrix24Exception $e) {
            Log::warning('Канбан: не удалось передать приоритет в Битрикс24', [
                'task' => $card->bitrix_task_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отразить новую колонку в штатном статусе задачи.
     *
     * Молча проглатываем ошибку REST: карточка на доске уже переехала, и
     * откатывать перемещение из-за недоступного портала хуже, чем
     * разъехавшийся статус — его починит следующая синхронизация.
     */
    protected function pushStatus(TaskCard $card, BoardColumn $target, ?PortalUser $actor): void
    {
        if ($target->bitrix_status === null || $target->bitrix_status === $card->bitrix_status) {
            return;
        }

        try {
            $client = $actor ? Bitrix24::forUser($actor) : Bitrix24::forPortal($card->portal);

            $client->call('tasks.task.update', [
                'taskId' => $card->bitrix_task_id,
                'fields' => ['STATUS' => $target->bitrix_status],
            ]);

            $card->forceFill(['bitrix_status' => $target->bitrix_status])->save();
        } catch (Bitrix24Exception $e) {
            Log::warning('Канбан: не удалось передать статус в Битрикс24', [
                'task' => $card->bitrix_task_id,
                'status' => $target->bitrix_status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ограничить выборку одной ячейкой «колонка × дорожка».
     *
     * Отдельным методом, потому что department_id бывает null, а
     * `where('department_id', null)` в SQL не совпадает ни с чем.
     */
    protected function inCell($query, ?int $columnId, ?int $departmentId)
    {
        return $query
            ->where('board_column_id', $columnId)
            ->when(
                $departmentId === null,
                fn ($q) => $q->whereNull('department_id'),
                fn ($q) => $q->where('department_id', $departmentId),
            );
    }

    /**
     * Закрыть дыру, оставшуюся от карточки в прежней ячейке.
     */
    protected function detach(TaskCard $card): void
    {
        $this->inCell(TaskCard::query(), $card->board_column_id, $card->department_id)
            ->where('position', '>', $card->position)
            ->decrement('position');
    }

    /**
     * Раздвинуть карточки и вписать нашу.
     */
    protected function insert(TaskCard $card, BoardColumn $target, ?int $departmentId, ?int $position): void
    {
        $count = $this->inCell(TaskCard::query(), $target->id, $departmentId)
            ->where('id', '!=', $card->id)
            ->count();

        $position = $position === null
            ? $count
            : max(0, min($position, $count));

        $this->inCell(TaskCard::query(), $target->id, $departmentId)
            ->where('id', '!=', $card->id)
            ->where('position', '>=', $position)
            ->increment('position');

        $card->forceFill([
            'board_column_id' => $target->id,
            'department_id' => $departmentId,
            'position' => $position,
        ])->save();
    }

    /**
     * @param  array<int, array{0: ?int, 1: ?int}>  $cells  Пары «колонка, дорожка»
     */
    protected function lockCells(array $cells): void
    {
        foreach ($cells as [$columnId, $departmentId]) {
            if ($columnId === null) {
                continue;
            }

            $this->inCell(TaskCard::query(), $columnId, $departmentId)
                ->lockForUpdate()
                ->pluck('id');
        }
    }
}
