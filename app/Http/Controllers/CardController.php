<?php

namespace App\Http\Controllers;

use App\Models\BoardColumn;
use App\Models\Department;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Bitrix24\TaskUserFields;
use App\Services\Kanban\CardMover;
use App\Support\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function move(
        Request $request,
        TaskCard $card,
        CardMover $mover,
        TaskUserFields $userFields,
    ): RedirectResponse {
        $validated = $request->validate([
            'column_id' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        // Колонку ищем через модель, а не по board_id из запроса: глобальный
        // скоуп отсечёт чужой портал, а проверка доски — чужую доску.
        $column = BoardColumn::query()
            ->where('board_id', $card->board_id)
            ->findOrFail($validated['column_id']);

        // null — дорожка «Без подразделения», это допустимая ячейка.
        $departmentId = $validated['department_id'] ?? null;

        if ($departmentId !== null) {
            $departmentId = Department::query()->findOrFail($departmentId)->id;
        }

        $mover->move(
            card: $card,
            target: $column,
            departmentId: $departmentId,
            position: $validated['position'] ?? null,
            actor: PortalContext::user(),
        );

        // Подразделение должно тут же появиться в самой задаче, а не ждать
        // ближайшей синхронизации: иначе штатный фильтр показывает старое.
        $userFields->pushOne($card->fresh());

        return back();
    }

    /**
     * Сменить приоритет карточки.
     *
     * Наш приоритет — источник истины: своих уровней больше, чем штатных,
     * поэтому в Битрикс уходит только связанный с уровнем PRIORITY, если
     * связь задана.
     */
    public function priority(
        Request $request,
        TaskCard $card,
        CardMover $mover,
        TaskUserFields $userFields,
    ): RedirectResponse {
        $validated = $request->validate([
            'priority_id' => ['nullable', 'integer'],
        ]);

        $priority = $validated['priority_id']
            ? TaskPriority::query()->findOrFail($validated['priority_id'])
            : null;

        $card->forceFill(['task_priority_id' => $priority?->id])->save();

        if ($priority?->bitrix_priority !== null) {
            $mover->pushPriority($card, $priority);
        }

        $userFields->pushOne($card->fresh());

        return back();
    }
}
