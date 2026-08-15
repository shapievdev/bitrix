<?php

namespace App\Jobs;

use App\Models\Portal;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Разбор события портала вне HTTP-запроса.
 */
class ProcessBitrixEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Дольше держать блокировку смысла нет — задача короткая. */
    public int $uniqueFor = 60;

    public function __construct(
        public int $portalId,
        public string $event,
        public array $data,
    ) {}

    public function handle(TaskSynchronizer $synchronizer): void
    {
        $portal = Portal::find($this->portalId);

        if (! $portal || ! $portal->is_active) {
            return;
        }

        $taskId = $this->taskId();

        if ($taskId === 0) {
            Log::info('Канбан: событие без идентификатора задачи', [
                'event' => $this->event,
                'data' => $this->data,
            ]);

            return;
        }

        // Весь разбор — внутри контекста портала, иначе глобальный скоуп
        // моделей не будет знать, к какому клиенту относятся данные.
        PortalContext::run($portal, function () use ($synchronizer, $taskId, $portal) {
            match ($this->event) {
                'ONTASKADD', 'ONTASKUPDATE' => $synchronizer->syncTask($taskId),
                'ONTASKDELETE' => $synchronizer->forgetTask($taskId),
                default => Log::info('Bitrix24: событие без обработчика', [
                    'portal' => $portal->domain,
                    'event' => $this->event,
                ]),
            };
        });
    }

    /**
     * Идентификатор задачи из тела события.
     *
     * Битрикс кладёт его по-разному в зависимости от события: при
     * обновлении — в FIELDS_AFTER, при удалении остаётся только
     * FIELDS_BEFORE, а некоторые версии портала шлют плоский ID.
     */
    protected function taskId(): int
    {
        return (int) (
            $this->data['FIELDS_AFTER']['ID']
            ?? $this->data['FIELDS_BEFORE']['ID']
            ?? $this->data['ID']
            ?? 0
        );
    }

    /**
     * Уникальность по задаче: при частых правках в очереди не должно
     * копиться несколько заданий на одну и ту же задачу.
     */
    public function uniqueId(): string
    {
        return "{$this->portalId}:{$this->event}:{$this->taskId()}";
    }
}
