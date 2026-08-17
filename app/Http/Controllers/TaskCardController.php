<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Kanban\CardMover;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Вкладка приложения внутри карточки задачи Битрикс24.
 *
 * Отдельный экран под одну задачу: сотрудник остаётся в штатном
 * интерфейсе, а подразделение и приоритет правит здесь же.
 */
class TaskCardController extends Controller
{
    public function show(int $taskId, TaskSynchronizer $synchronizer): Response
    {
        $card = TaskCard::query()
            ->with(['board', 'column', 'department', 'priorityLevel'])
            ->where('bitrix_task_id', $taskId)
            ->first();

        // Задачи может не быть ни на одной доске — например, она не
        // попадает под фильтр. Подтягиваем её точечно, чтобы вкладка не
        // встречала пользователя пустотой.
        if (! $card) {
            $synchronizer->syncTask($taskId);

            $card = TaskCard::query()
                ->with(['board', 'column', 'department', 'priorityLevel'])
                ->where('bitrix_task_id', $taskId)
                ->first();
        }

        return Inertia::render('Tasks/Show', [
            'taskId' => $taskId,

            'card' => $card ? [
                'id' => $card->id,
                'title' => $card->title,
                'boardName' => $card->board->name,
                'boardId' => $card->board_id,
                'columnId' => $card->board_column_id,
                'columnName' => $card->column->name,
                'departmentId' => $card->department_id,
                'departmentLocked' => $card->department_locked,
                'priorityId' => $card->task_priority_id,
                'deadline' => $card->deadline?->format('d.m.Y'),
                'isOverdue' => $card->isOverdue(),
            ] : null,

            'columns' => $card
                ? $card->board->columns()->orderBy('position')->get()
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color])
                : [],

            'departments' => Department::query()->orderBy('position')->get()
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'color' => $d->color]),

            'priorities' => TaskPriority::query()->orderByDesc('weight')->get()
                ->map(fn (TaskPriority $p) => ['id' => $p->id, 'name' => $p->name, 'color' => $p->color]),
        ]);
    }

    /**
     * Обновить подразделение, колонку или приоритет одной задачи.
     */
    public function update(Request $request, TaskCard $card, CardMover $mover): RedirectResponse
    {
        $validated = $request->validate([
            'column_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
        ]);

        if (array_key_exists('priority_id', $validated)) {
            $priority = $validated['priority_id']
                ? TaskPriority::query()->findOrFail($validated['priority_id'])
                : null;

            $card->forceFill(['task_priority_id' => $priority?->id])->save();

            if ($priority) {
                $mover->pushPriority($card, $priority);
            }
        }

        if (! empty($validated['column_id'])) {
            $column = $card->board->columns()->findOrFail($validated['column_id']);

            $mover->move(
                card: $card,
                target: $column,
                departmentId: array_key_exists('department_id', $validated)
                    ? $validated['department_id']
                    : $card->department_id,
                actor: PortalContext::user(),
            );
        }

        return back()->with('success', 'Сохранено.');
    }
}
