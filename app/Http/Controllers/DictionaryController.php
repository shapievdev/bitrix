<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Department;
use App\Models\TaskPriority;
use App\Services\Kanban\PortalDictionaries;
use App\Support\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройка справочников доски: подразделения, приоритеты, колонки.
 */
class DictionaryController extends Controller
{
    public function index(Board $board): Response
    {
        $board->load('columns');

        return Inertia::render('Boards/Settings', [
            'board' => ['id' => $board->id, 'name' => $board->name],

            'departments' => Department::query()->orderBy('position')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'color' => $d->color,
                    'position' => $d->position,
                    'isDefault' => $d->is_default,
                    'bitrixId' => $d->bitrix_department_id,
                ]),

            'priorities' => TaskPriority::query()->orderByDesc('weight')->get()
                ->map(fn (TaskPriority $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'color' => $p->color,
                    'weight' => $p->weight,
                    'isDefault' => $p->is_default,
                    'bitrixPriority' => $p->bitrix_priority,
                ]),

            'columns' => $board->columns->map(fn (BoardColumn $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'color' => $c->color,
                'position' => $c->position,
                'bitrixStatus' => $c->bitrix_status,
                'wipLimit' => $c->wip_limit,
                'isDefault' => $c->is_default,
                'isFinal' => $c->is_final,
            ]),

            'bitrixStatuses' => collect(TaskStatus::cases())
                ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    // --- Подразделения ---------------------------------------------------

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:16'],
            'bitrix_department_id' => ['nullable', 'integer', 'min:1'],
        ]);

        Department::create($data + [
            'portal_id' => PortalContext::portalOrFail()->id,
            'position' => (int) Department::query()->max('position') + 1,
            'is_default' => Department::query()->count() === 0,
        ]);

        return back()->with('success', 'Подразделение добавлено.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:16'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'bitrix_department_id' => [
                'nullable', 'integer', 'min:1',
                Rule::unique('departments', 'bitrix_department_id')
                    ->where('portal_id', $department->portal_id)
                    ->ignore($department->id),
            ],
        ]);

        // Дорожка по умолчанию должна быть ровно одна: в неё падают
        // задачи, чей отдел определить не удалось.
        if ($request->boolean('is_default')) {
            Department::query()->where('id', '!=', $department->id)->update(['is_default' => false]);
        }

        $department->update($data);

        return back()->with('success', 'Подразделение обновлено.');
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        // Карточки не удаляем — они уедут в «Без подразделения»
        // (внешний ключ настроен на nullOnDelete).
        $department->delete();

        return back()->with('success', 'Подразделение удалено, задачи остались на доске.');
    }

    // --- Приоритеты ------------------------------------------------------

    public function storePriority(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:16'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'bitrix_priority' => ['nullable', 'integer', 'min:0', 'max:2'],
        ]);

        $data = $this->zeroInsteadOfNull($data, ['weight']);

        TaskPriority::create($data + ['portal_id' => PortalContext::portalOrFail()->id]);

        return back()->with('success', 'Приоритет добавлен.');
    }

    public function updatePriority(Request $request, TaskPriority $priority): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:16'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'bitrix_priority' => ['nullable', 'integer', 'min:0', 'max:2'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $data = $this->zeroInsteadOfNull($data, ['weight']);

        if ($request->boolean('is_default')) {
            TaskPriority::query()->where('id', '!=', $priority->id)->update(['is_default' => false]);
        }

        $priority->update($data);

        return back()->with('success', 'Приоритет обновлён.');
    }

    public function destroyPriority(TaskPriority $priority): RedirectResponse
    {
        $priority->delete();

        return back()->with('success', 'Приоритет удалён.');
    }

    // --- Колонки ---------------------------------------------------------

    public function storeColumn(Request $request, Board $board): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:16'],
            'bitrix_status' => ['nullable', 'integer', 'min:1', 'max:7'],
            'wip_limit' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $data = $this->zeroInsteadOfNull($data, ['wip_limit']);

        $board->columns()->create($data + [
            'portal_id' => $board->portal_id,
            'position' => (int) $board->columns()->max('position') + 1,
        ]);

        return back()->with('success', 'Колонка добавлена.');
    }

    /**
     * Переставить колонки в заданном порядке.
     *
     * Порядок приходит списком идентификаторов — так его задаёт
     * перетаскивание. Ввод позиции числом руками не выжил: цифры в поле
     * никак не связаны с тем, что человек видит на доске, и первый же
     * вопрос про них был «а зачем это вообще».
     */
    public function reorderColumns(Request $request, Board $board): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $own = $board->columns()->pluck('id')->all();
        $sent = array_map('intval', $data['ids']);

        // Принимаем только полный набор колонок доски. Неполный список
        // оставил бы дыры в позициях, а чужие идентификаторы — тихо
        // переставили бы колонки соседней доски.
        if (array_diff($own, $sent) !== [] || array_diff($sent, $own) !== []) {
            return back()->with('error', 'Порядок не совпадает с колонками доски — обновите страницу.');
        }

        DB::transaction(function () use ($board, $sent) {
            foreach ($sent as $position => $id) {
                $board->columns()->whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }

    public function updateColumn(Request $request, BoardColumn $column): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:16'],
            'bitrix_status' => ['nullable', 'integer', 'min:1', 'max:7'],
            'wip_limit' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_default' => ['nullable', 'boolean'],
            'is_final' => ['nullable', 'boolean'],
        ]);

        // Пустое поле приходит как null, а колонки в базе NOT NULL: без
        // этой замены очистка поля роняла запрос пятисоткой. Позиция
        // здесь не принимается вовсе — для неё есть reorderColumns.
        $data = $this->zeroInsteadOfNull($data, ['wip_limit']);

        if ($request->boolean('is_default')) {
            BoardColumn::query()
                ->where('board_id', $column->board_id)
                ->where('id', '!=', $column->id)
                ->update(['is_default' => false]);
        }

        $column->update($data);

        return back()->with('success', 'Колонка обновлена.');
    }

    public function destroyColumn(BoardColumn $column): RedirectResponse
    {
        if ($column->cards()->exists()) {
            return back()->with('error', 'В колонке есть задачи — сначала перенесите их.');
        }

        if ($column->board->columns()->count() <= 1) {
            return back()->with('error', 'Это последняя колонка: доска без колонок работать не будет.');
        }

        $column->delete();

        return back()->with('success', 'Колонка удалена.');
    }

    /**
     * Заменить null нулём у полей, которых в базе не может не быть.
     *
     * Очищенное поле формы приходит пустой строкой, мидлвара превращает
     * её в null, валидатор пропускает как nullable — и запись падает на
     * NOT NULL уже в базе, пятисоткой. Для веса и лимита ноль как раз и
     * означает «не задано», так что подмена честная.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function zeroInsteadOfNull(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                $data[$key] = 0;
            }
        }

        return $data;
    }

    /**
     * Подтянуть отделы из оргструктуры портала.
     */
    public function importDepartments(PortalDictionaries $dictionaries): RedirectResponse
    {
        $added = $dictionaries->importFromBitrix(PortalContext::portalOrFail());

        return back()->with(
            $added > 0 ? 'success' : 'error',
            $added > 0
                ? "Добавлено подразделений: {$added}."
                : 'Новых отделов не найдено. Возможно, приложению не выданы права на оргструктуру (department).',
        );
    }
}
