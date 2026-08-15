<?php

namespace App\Console\Commands;

use App\Models\Board;
use App\Models\Portal;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Полная синхронизация досок со всеми порталами.
 *
 * События портала теряются: доставка не гарантирована, а при недоступном
 * приложении Битрикс просто перестаёт их слать. Регулярный полный обход —
 * страховка, которая приводит доски в соответствие с реальностью.
 */
class SyncBoards extends Command
{
    protected $signature = 'bitrix:sync-boards
        {--portal= : Синхронизировать только один портал (member_id или домен)}
        {--board= : Только одну доску по её ID}';

    protected $description = 'Подтянуть задачи Битрикс24 на все канбан-доски';

    public function handle(TaskSynchronizer $synchronizer): int
    {
        $portals = $this->portals();

        if ($portals->isEmpty()) {
            $this->components->warn('Активных порталов не найдено.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($portals as $portal) {
            $this->components->info("Портал {$portal->domain}");

            // Без контекста глобальный скоуп в консоли не фильтрует ничего,
            // и доски одного клиента синхронизировались бы под токеном другого.
            PortalContext::run($portal, function () use ($synchronizer, &$failed) {
                $boards = Board::query()
                    ->with('columns')
                    ->when($this->option('board'), fn ($q, $id) => $q->whereKey($id))
                    ->get();

                if ($boards->isEmpty()) {
                    $this->components->twoColumnDetail('  досок нет', '');

                    return;
                }

                foreach ($boards as $board) {
                    $failed += (int) ! $this->syncBoard($synchronizer, $board);
                }
            });
        }

        if ($failed > 0) {
            $this->components->error("Досок с ошибкой: {$failed}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function syncBoard(TaskSynchronizer $synchronizer, Board $board): bool
    {
        try {
            $stats = $synchronizer->syncBoard($board);

            $this->components->twoColumnDetail(
                "  {$board->name}",
                sprintf(
                    '<fg=green>+%d</> <fg=yellow>~%d</> <fg=red>-%d</>',
                    $stats['created'],
                    $stats['updated'],
                    $stats['removed'],
                ),
            );

            return true;
        } catch (Bitrix24Exception|\RuntimeException $e) {
            // Одна сломанная доска не должна ронять обход остальных.
            $this->components->twoColumnDetail(
                "  {$board->name}",
                '<fg=red>'.mb_substr($e->getMessage(), 0, 70).'</>',
            );

            return false;
        }
    }

    /**
     * @return Collection<int, Portal>
     */
    protected function portals(): Collection
    {
        return Portal::query()
            ->where('is_active', true)
            ->when($this->option('portal'), fn ($q, $value) => $q->where(
                fn ($inner) => $inner->where('member_id', $value)->orWhere('domain', $value)
            ))
            ->get();
    }
}
