<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Department;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Kanban\BoardBuilder;
use App\Services\Kanban\TaskSynchronizer;
use App\Services\Kanban\TaskVisibility;
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

    public function show(Request $request, Board $board, TaskVisibility $visibility): Response
    {
        $board->load('columns');

        $viewer = PortalContext::user();

        $all = Department::query()->orderBy('name')->get();

        // Выбранный узел определяет, что показывать в канбане: сам
        // департамент, конкретный отдел или всё сразу.
        $selected = $request->integer('department') ?: null;
        $selectedNode = $selected ? $all->firstWhere('id', $selected) : null;
        $scopeIds = $selectedNode?->subtreeIds($all);

        $filters = $this->filters($request);

        $cards = $board->cards()
            ->with(['priorityLevel', 'departments'])
            ->tap(fn ($query) => $visibility->apply($query, $viewer, $filters['mine']))
            ->when($scopeIds, fn ($query) => $query->whereHas(
                'departments',
                fn ($q) => $q->whereIn('departments.id', $scopeIds),
            ))
            ->tap(fn ($query) => $this->applyFilters($query, $filters))
            ->orderBy('position')
            ->get();

        // Справочник сотрудников собираем одним запросом: карточек на
        // экране сотни, и профиль на каждую означал бы N+1.
        $people = $this->peopleFor($cards);

        // Счётчики по всему дереву — они не должны зависеть от текущего
        // выбора, иначе панель слева схлопывается до одного пункта. Но от
        // видимости зависеть обязаны: иначе сотрудник видит в панели
        // «Финансовый департамент — 340», открывает и получает три задачи.
        $counts = $this->cardCounts($board, $visibility, $viewer, $filters['mine']);

        $primary = $all->where('is_primary', true)->values();
        $parentForUnits = $selectedNode?->is_primary
            ? $selectedNode
            : ($selectedNode?->parent_id ? $all->firstWhere('id', $selectedNode->parent_id) : null);

        return Inertia::render('Boards/Show', [
            'board' => [
                'id' => $board->id,
                'name' => $board->name,
                'syncedAt' => $board->synced_at?->diffForHumans(),
                // Счётчик «Все задачи» — тоже без завершённых и тоже
                // только по видимым.
                'total' => $board->cards()
                    ->tap(fn ($query) => $visibility->apply($query, $viewer, $filters['mine']))
                    ->whereHas('column', fn ($q) => $q->where('is_final', false))
                    ->count(),
            ],

            // Интерфейсу нужно знать, широкий ли обзор у смотрящего:
            // подпись «мои задачи» на доске из трёх карточек объясняет,
            // почему их три, и снимает половину вопросов.
            'viewer' => [
                'name' => $viewer?->name,
                'isAdmin' => $visibility->isUnrestricted($viewer),
                'headsDepartments' => $visibility->headDepartmentIds($viewer) !== [],
                // Рядовому сотруднику переключатель показывать незачем —
                // сужать ему нечего.
                'canNarrowToOwn' => $visibility->canNarrowToOwn($viewer),
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
                // Момент попадания в текущую колонку. Отдаём меткой
                // времени, а не готовой подписью: счётчик на доске должен
                // идти сам, не дожидаясь перезагрузки страницы.
                'enteredAt' => $card->enteredColumnAt()?->toIso8601String(),
                'priority' => $card->priorityLevel ? [
                    'name' => $card->priorityLevel->name,
                    'color' => $card->priorityLevel->color,
                ] : null,
                // На карточке показываем людей: исполнителя и
                // соисполнителей. Отделы вычисляются из них же и в панели
                // слева видны нагляднее.
                'people' => $this->cardPeople($card, $people),
            ])->values(),

            'priorities' => TaskPriority::query()->orderByDesc('weight')->get()
                ->map(fn (TaskPriority $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'color' => $p->color,
                ])->values(),

            'filters' => $filters,

            // Исполнители только те, чьи задачи вообще есть на доске —
            // список всех сотрудников портала здесь бесполезен.
            'responsibles' => $this->responsibles($board, $visibility, $viewer, $filters['mine']),
        ]);
    }

    /**
     * Исполнители задач доски.
     *
     * Считаем по видимым задачам: иначе фильтр предлагает выбрать
     * сотрудника, задач которого смотрящий всё равно не увидит, и
     * заодно раскрывает состав чужих отделов.
     *
     * @return array<int, array{id: int, name: string}>
     */
    protected function responsibles(
        Board $board,
        TaskVisibility $visibility,
        ?PortalUser $viewer,
        bool $onlyMine = false,
    ): array {
        $ids = $board->cards()->whereNotNull('responsible_id')
            ->tap(fn ($query) => $visibility->apply($query, $viewer, $onlyMine))
            ->distinct()
            ->pluck('responsible_id');

        return PortalUser::query()
            ->whereIn('bitrix_user_id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (PortalUser $u) => ['id' => $u->bitrix_user_id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /**
     * Сотрудники, участвующие в задачах доски.
     *
     * @param  Collection<int, TaskCard>  $cards
     * @return Collection<int, PortalUser>
     */
    protected function peopleFor(Collection $cards): Collection
    {
        $ids = $cards
            ->flatMap(fn (TaskCard $card) => array_merge(
                [$card->responsible_id],
                $card->accomplice_ids ?? [],
            ))
            ->filter()
            ->unique()
            ->all();

        return PortalUser::query()
            ->whereIn('bitrix_user_id', $ids)
            ->get()
            ->keyBy('bitrix_user_id');
    }

    /**
     * Участники одной задачи с их ролью.
     *
     * @param  Collection<int, PortalUser>  $people
     * @return array<int, array<string, mixed>>
     */
    protected function cardPeople(TaskCard $card, Collection $people): array
    {
        $result = [];

        $add = function (?int $bitrixId, string $role) use (&$result, $people) {
            if (! $bitrixId || isset($result[$bitrixId])) {
                return;
            }

            $user = $people->get($bitrixId);

            $result[$bitrixId] = [
                'id' => $bitrixId,
                // Сотрудника может не быть в нашей базе, если он ещё ни
                // разу не открывал приложение и не попадал в синхронизацию.
                'name' => $user?->name ?? "Сотрудник #{$bitrixId}",
                'avatar' => $user?->avatar,
                'role' => $role,
            ];
        };

        $add($card->responsible_id, 'responsible');

        foreach ($card->accomplice_ids ?? [] as $id) {
            $add((int) $id, 'accomplice');
        }

        return array_values($result);
    }

    /**
     * Сколько активных задач у каждого узла.
     *
     * Завершённые в счётчиках не участвуют: их количество растёт вечно и
     * быстро перестаёт что-либо значить — руководителю нужна текущая
     * нагрузка отдела, а не архив за всё время.
     *
     * @return array<int, int>
     */
    protected function cardCounts(
        Board $board,
        TaskVisibility $visibility,
        ?PortalUser $viewer,
        bool $onlyMine = false,
    ): array {
        // Видимые карточки — подзапросом, а не списком идентификаторов:
        // у администратора на активной доске их тысячи, и тащить их в
        // память ради счётчиков незачем.
        $visible = TaskCard::query()
            ->select('task_cards.id')
            ->where('board_id', $board->id)
            ->whereHas('column', fn ($q) => $q->where('is_final', false))
            ->tap(fn ($query) => $visibility->apply($query, $viewer, $onlyMine));

        return DB::table('department_task_card')
            ->whereIn('task_card_id', $visible)
            ->groupBy('department_id')
            ->selectRaw('department_id, count(distinct task_card_id) as total')
            ->pluck('total', 'department_id')
            ->all();
    }

    /**
     * Разобрать параметры фильтрации.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            // Сужение до собственного участия. Для рядового сотрудника
            // ничего не меняет — он и так видит только свои задачи.
            'mine' => $request->boolean('mine'),
            'priority' => $request->integer('priority') ?: null,
            'responsible' => $request->integer('responsible') ?: null,
            'deadline' => in_array($request->query('deadline'), ['overdue', 'with', 'without'], true)
                ? $request->query('deadline')
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        $query
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $term = $filters['q'];

                // Номер задачи ищем точным совпадением: «219» в названии
                // и задача #219 — разные намерения, и подстрочный поиск
                // по номеру выдал бы сотни лишних совпадений.
                $q->where(function ($inner) use ($term) {
                    $inner->where('title_normalized', 'like', '%'.mb_strtolower($term).'%');

                    if (ctype_digit(ltrim($term, '#'))) {
                        $inner->orWhere('bitrix_task_id', (int) ltrim($term, '#'));
                    }
                });
            })
            ->when($filters['priority'], fn ($q, $id) => $q->where('task_priority_id', $id))
            ->when($filters['responsible'], fn ($q, $id) => $q->where('responsible_id', $id))
            ->when($filters['deadline'] === 'overdue', fn ($q) => $q
                ->whereNotNull('deadline')
                ->whereNull('closed_at')
                ->where('deadline', '<', now()))
            ->when($filters['deadline'] === 'with', fn ($q) => $q->whereNotNull('deadline'))
            ->when($filters['deadline'] === 'without', fn ($q) => $q->whereNull('deadline'));
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
