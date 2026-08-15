<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Services\Kanban\BoardBuilder;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    public function index(): Response
    {
        // Глобальный скоуп уже ограничил выборку текущим порталом —
        // фильтровать вручную не нужно и нельзя забыть.
        $boards = Board::query()
            ->withCount('cards')
            ->orderBy('name')
            ->get()
            ->map(fn (Board $board) => [
                'id' => $board->id,
                'name' => $board->name,
                'description' => $board->description,
                'cardsCount' => $board->cards_count,
                'syncedAt' => $board->synced_at?->diffForHumans(),
            ]);

        return Inertia::render('Boards/Index', ['boards' => $boards]);
    }

    public function show(Board $board): Response
    {
        $board->load([
            'columns',
            'columns.cards' => fn ($query) => $query->orderBy('position'),
        ]);

        return Inertia::render('Boards/Show', [
            'board' => [
                'id' => $board->id,
                'name' => $board->name,
                'syncedAt' => $board->synced_at?->diffForHumans(),
            ],
            'columns' => $board->columns->map(fn ($column) => [
                'id' => $column->id,
                'name' => $column->name,
                'color' => $column->color,
                'wipLimit' => $column->wip_limit,
                'isFinal' => $column->is_final,
                'overLimit' => $column->isOverWipLimit($column->cards->count()),
                'cards' => $column->cards->map(fn ($card) => [
                    'id' => $card->id,
                    'taskId' => $card->bitrix_task_id,
                    'title' => $card->title,
                    'responsibleId' => $card->responsible_id,
                    'priority' => $card->priority,
                    'deadline' => $card->deadline?->format('d.m.Y'),
                    'isOverdue' => $card->isOverdue(),
                ])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request, BoardBuilder $builder): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bitrix_group_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $board = $builder->create(
            name: $validated['name'],
            groupId: $validated['bitrix_group_id'] ?? null,
            author: PortalContext::user(),
        );

        return redirect()
            ->route('app.boards.show', $board)
            ->with('success', 'Доска создана. Запустите синхронизацию, чтобы подтянуть задачи.');
    }

    /**
     * Полная синхронизация по кнопке.
     *
     * Держим синхронной: пользователь нажал и ждёт результат, а доска на
     * несколько сотен задач укладывается в один-два батча.
     */
    public function sync(Board $board, TaskSynchronizer $synchronizer): RedirectResponse
    {
        $stats = $synchronizer->syncBoard($board);

        return back()->with('success', sprintf(
            'Синхронизация завершена: добавлено %d, обновлено %d, снято %d.',
            $stats['created'],
            $stats['updated'],
            $stats['removed'],
        ));
    }

    public function destroy(Board $board): RedirectResponse
    {
        $board->delete();

        return redirect()
            ->route('app.boards.index')
            ->with('success', 'Доска удалена. Задачи в Битрикс24 не затронуты.');
    }
}
