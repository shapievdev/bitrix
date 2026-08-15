<?php

namespace App\Http\Controllers;

use App\Models\BoardColumn;
use App\Models\TaskCard;
use App\Services\Kanban\CardMover;
use App\Support\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function move(Request $request, TaskCard $card, CardMover $mover): RedirectResponse
    {
        $validated = $request->validate([
            'column_id' => ['required', 'integer'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        // Колонку ищем через модель, а не по board_id из запроса: глобальный
        // скоуп отсечёт чужой портал, а проверка доски — чужую доску.
        $column = BoardColumn::query()
            ->where('board_id', $card->board_id)
            ->findOrFail($validated['column_id']);

        $mover->move(
            card: $card,
            target: $column,
            position: $validated['position'] ?? null,
            actor: PortalContext::user(),
        );

        return back();
    }
}
