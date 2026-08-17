<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Department;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Kanban\BoardBuilder;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    /**
     * Единственная доска портала, создавая её при первом входе.
     *
     * Приложение должно открываться сразу на канбане: экран со списком
     * досок между входом и работой — лишний шаг.
     */
    public function current(BoardBuilder $builder): RedirectResponse
    {
        $board = Board::query()->orderBy('id')->first()
            ?? $builder->create('Задачи компании', author: PortalContext::user());

        return redirect()->route('app.boards.show', $board);
    }

    public function show(Request $request, Board $board): Response
    {
        $board->load('columns');

        $all = Department::query()->orderBy('name')->get();

        // Выбранный узел определяет, что показывать в канбане: сам
        // департамент, конкретный отдел или всё сразу.
        $selected = $request->integer('department') ?: null;
        $selectedNode = $selected ? $all->firstWhere('id', $selected) : null;
        $scopeIds = $selectedNode?->subtreeIds($all);

        $cards = $board->cards()
            ->with(['priorityLevel', 'departments'])
            ->when($scopeIds, fn ($query) => $query->whereHas(
                'departments',
                fn ($q) => $q->whereIn('departments.id', $scopeIds),
            ))
            ->orderBy('position')
            ->get();

        // Счётчики по всему дереву — они не должны зависеть от текущего
        // выбора, иначе панель слева схлопывается до одного пункта.
        $counts = $this->cardCounts($board, $all);

        $primary = $all->where('is_primary', true)->values();
        $parentForUnits = $selectedNode?->is_primary
            ? $selectedNode
            : ($selectedNode?->parent_id ? $all->firstWhere('id', $selectedNode->parent_id) : null);

        return Inertia::render('Boards/Show', [
            'board' => [
                'id' => $board->id,
                'name' => $board->name,
                'syncedAt' => $board->synced_at?->diffForHumans(),
                'total' => $board->cards()->count(),
            ],

            'departments' => $primary->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'color' => $d->color,
                'count' => $this->subtreeCount($d, $all, $counts),
            ])->values(),

            // Отделы выбранного департамента: прямые дети и их потомки,
            // с отступом, чтобы вложенность была видна.
            'units' => $parentForUnits
                ? $this->flattenUnits($parentForUnits, $all, $counts)
                : [],

            'selected' => [
                'id' => $selectedNode?->id,
                'name' => $selectedNode?->name,
                'departmentId' => $parentForUnits?->id,
            ],

            'columns' => $board->columns->map(fn (BoardColumn $column) => [
                'id' => $column->id,
                'name' => $column->name,
                'color' => $column->color,
                'wipLimit' => $column->wip_limit,
                'total' => $cards->where('board_column_id', $column->id)->count(),
            ])->values(),

            'cards' => $cards->map(fn (TaskCard $card) => [
                'id' => $card->id,
                'columnId' => $card->board_column_id,
                'position' => $card->position,
                'taskId' => $card->bitrix_task_id,
                'title' => $card->title,
                'deadline' => $card->deadline?->format('d.m.Y'),
                'isOverdue' => $card->isOverdue(),
                'priority' => $card->priorityLevel ? [
                    'name' => $card->priorityLevel->name,
                    'color' => $card->priorityLevel->color,
                ] : null,
                // Одна задача может идти через несколько отделов — на
                // карточке показываем все, иначе непонятно, почему она
                // видна в двух разных подразделениях.
                'departments' => $card->departments->map(fn (Department $d) => [
                    'name' => $d->name,
                    'color' => $d->color,
                    'source' => $d->pivot->source,
                ])->values(),
            ])->values(),

            'priorities' => TaskPriority::query()->orderByDesc('weight')->get()
                ->map(fn (TaskPriority $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'color' => $p->color,
                ])->values(),
        ]);
    }

    /**
     * Сколько задач у каждого узла напрямую.
     *
     * @return array<int, int>
     */
    protected function cardCounts(Board $board, Collection $all): array
    {
        return DB::table('department_task_card')
            ->join('task_cards', 'task_cards.id', '=', 'department_task_card.task_card_id')
            ->where('task_cards.board_id', $board->id)
            ->groupBy('department_task_card.department_id')
            ->selectRaw('department_task_card.department_id, count(distinct task_cards.id) as total')
            ->pluck('total', 'department_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $counts
     */
    protected function subtreeCount(Department $node, Collection $all, array $counts): int
    {
        $total = 0;

        foreach ($node->subtreeIds($all) as $id) {
            $total += $counts[$id] ?? 0;
        }

        return $total;
    }

    /**
     * Плоский список отделов департамента с уровнем вложенности.
     *
     * @param  array<int, int>  $counts
     * @return array<int, array<string, mixed>>
     */
    protected function flattenUnits(Department $parent, Collection $all, array $counts, int $depth = 0): array
    {
        $result = [];

        foreach ($all->where('parent_id', $parent->id)->sortBy('name') as $child) {
            $result[] = [
                'id' => $child->id,
                'name' => $child->name,
                'color' => $child->color,
                'depth' => $depth,
                'count' => $this->subtreeCount($child, $all, $counts),
            ];

            $result = array_merge($result, $this->flattenUnits($child, $all, $counts, $depth + 1));
        }

        return $result;
    }

    public function sync(Board $board, TaskSynchronizer $synchronizer): RedirectResponse
    {
        $stats = $synchronizer->syncBoard($board);

        return back()->with('success', sprintf(
            'Обновлено: добавлено %d, изменено %d, снято %d, записано в задачи %d.',
            $stats['created'],
            $stats['updated'],
            $stats['removed'],
            $stats['pushed'],
        ));
    }

    public function destroy(Board $board): RedirectResponse
    {
        $board->delete();

        return redirect()
            ->route('app.home')
            ->with('success', 'Доска удалена. Задачи в Битрикс24 не затронуты.');
    }
}
