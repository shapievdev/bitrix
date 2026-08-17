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
     * Переместить карточку в колонку на заданную позицию.
     *
     * Отделы карточки на порядок не влияют: задача может принадлежать
     * сразу нескольким, и раскладывать её на независимые стопки было бы
     * нечем — она одна.
     *
     * @param  ?int  $position  Позиция в колонке, начиная с 0. null — в конец.
     * @param  bool  $pushToBitrix  Отправить ли новый статус на портал.
     */
    public function move(
        TaskCard $card,
        BoardColumn $target,
        ?int $position = null,
        ?PortalUser $actor = null,
        bool $pushToBitrix = true,
    ): TaskCard {
        if ($target->board_id !== $card->board_id) {
            throw new \InvalidArgumentException('Колонка принадлежит другой доске.');
        }

        $source = $card->column;
        $enteredAt = $card->enteredColumnAt();

        DB::transaction(function () use ($card, $target, $position, $actor, $source, $enteredAt) {
            // Блокируем карточки обеих колонок: два одновременных
            // перетаскивания иначе выдадут двум карточкам одну позицию.
            $this->lockColumns(array_unique(array_filter([$source?->id, $target->id])));

            $this->detach($card);
            $this->insert($card, $target, $position);

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

        // pushToBitrix отключён намеренно: статус пришёл оттуда, отправлять
        // его обратно — прямой путь к бесконечному циклу событий.
        return $this->move($card, $target, pushToBitrix: false);
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
     * Закрыть дыру, оставшуюся от карточки в прежней колонке.
     */
    protected function detach(TaskCard $card): void
    {
        TaskCard::query()
            ->where('board_column_id', $card->board_column_id)
            ->where('position', '>', $card->position)
            ->decrement('position');
    }

    /**
     * Раздвинуть карточки и вписать нашу.
     */
    protected function insert(TaskCard $card, BoardColumn $target, ?int $position): void
    {
        $count = TaskCard::query()
            ->where('board_column_id', $target->id)
            ->where('id', '!=', $card->id)
            ->count();

        $position = $position === null ? $count : max(0, min($position, $count));

        TaskCard::query()
            ->where('board_column_id', $target->id)
            ->where('id', '!=', $card->id)
            ->where('position', '>=', $position)
            ->increment('position');

        $card->forceFill([
            'board_column_id' => $target->id,
            'position' => $position,
        ])->save();
    }

    /**
     * @param  array<int>  $columnIds
     */
    protected function lockColumns(array $columnIds): void
    {
        if ($columnIds === []) {
            return;
        }

        TaskCard::query()
            ->whereIn('board_column_id', $columnIds)
            ->lockForUpdate()
            ->pluck('id');
    }
}
