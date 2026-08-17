<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\Department;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Kanban\BoardBuilder;
use App\Services\Kanban\CardMover;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Дорожки подразделений, автоподстановка отдела и приоритеты.
 */
class SwimlaneTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    /** @var array<int, array<string, mixed>> */
    protected array $tasks = [];

    /** @var array<int, array<string, mixed>> Профили сотрудников портала */
    protected array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);

        Http::fake([
            '*/rest/tasks.task.list*' => fn () => Http::response([
                'result' => ['tasks' => $this->tasks],
                'total' => count($this->tasks),
            ]),
            '*/rest/batch*' => fn ($request) => Http::response($this->batchResponse($request)),
            '*' => Http::response(['result' => true]),
        ]);

        $this->portal = Portal::create([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        PortalContext::set($this->portal);

        $this->board = app(BoardBuilder::class)->create('Компания');
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    /**
     * Отвечаем на batch с user.get по сотрудникам.
     */
    protected function batchResponse($request): array
    {
        $cmd = $request['cmd'] ?? [];
        $result = [];

        foreach ($cmd as $key => $command) {
            preg_match('/ID=(\d+)/', (string) $command, $m);
            $id = (int) ($m[1] ?? 0);

            if (isset($this->users[$id])) {
                $result[$key] = [$this->users[$id]];
            }
        }

        return ['result' => ['result' => $result, 'result_error' => []]];
    }

    protected function employee(int $id, string $name, array $departmentIds): void
    {
        $this->users[$id] = [
            'ID' => (string) $id,
            'NAME' => $name,
            'LAST_NAME' => '',
            'UF_DEPARTMENT' => $departmentIds,
        ];
    }

    protected function task(int $id, string $title, int $responsibleId, int $priority = 1): array
    {
        return [
            'id' => (string) $id,
            'title' => $title,
            'status' => '2',
            'responsibleId' => (string) $responsibleId,
            'createdBy' => '1',
            'priority' => (string) $priority,
        ];
    }

    public function test_новый_портал_получает_подразделения_и_приоритеты(): void
    {
        // Оргструктура недоступна (нет прав department) — значит должен
        // лечь типовой набор, иначе доска откроется пустой.
        $this->assertGreaterThan(0, Department::count());
        $this->assertSame(4, TaskPriority::count());
        $this->assertSame('Обычный', TaskPriority::firstWhere('is_default', true)->name);
        $this->assertTrue(Department::firstWhere('is_default', true)->exists);
    }

    public function test_задача_попадает_в_дорожку_по_отделу_ответственного(): void
    {
        $commercial = Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'Коммерческий отдел',
            'position' => 10,
            'bitrix_department_id' => 5,
        ]);
        $operations = Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'Операционный отдел',
            'position' => 11,
            'bitrix_department_id' => 7,
        ]);

        $this->employee(101, 'Продавец', [5]);
        $this->employee(102, 'Логист', [7]);

        $this->tasks = [
            $this->task(1, 'Продажа', 101),
            $this->task(2, 'Отгрузка', 102),
        ];

        app(TaskSynchronizer::class)->syncBoard($this->board);

        $byTitle = TaskCard::all()->keyBy('title');

        $this->assertSame($commercial->id, $byTitle['Продажа']->department_id);
        $this->assertSame($operations->id, $byTitle['Отгрузка']->department_id);
    }

    public function test_неопознанный_отдел_уходит_в_дорожку_по_умолчанию(): void
    {
        $fallback = Department::firstWhere('is_default', true);

        $this->employee(103, 'Новичок', [999]);
        $this->tasks = [$this->task(1, 'Ничья задача', 103)];

        app(TaskSynchronizer::class)->syncBoard($this->board);

        $this->assertSame($fallback->id, TaskCard::first()->department_id);
    }

    public function test_отделы_сотрудников_запрашиваются_одной_пачкой(): void
    {
        Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'Коммерческий',
            'position' => 10,
            'bitrix_department_id' => 5,
        ]);

        foreach (range(101, 110) as $id) {
            $this->employee($id, "Сотрудник {$id}", [5]);
        }

        $this->tasks = collect(range(1, 10))
            ->map(fn ($i) => $this->task($i, "Задача {$i}", 100 + $i))
            ->all();

        app(TaskSynchronizer::class)->syncBoard($this->board);

        // Десять задач — один batch на профили, а не десять запросов:
        // иначе лимит REST выбивается на первой же сотне карточек.
        $batches = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/rest/batch')
                && str_contains(implode(' ', $pair[0]['cmd'] ?? []), 'user.get'))
            ->count();

        $this->assertSame(1, $batches);
        $this->assertSame(10, TaskCard::whereNotNull('department_id')->count());
    }

    public function test_дорожка_выставленная_руками_не_перебивается_синхронизацией(): void
    {
        $commercial = Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'Коммерческий',
            'position' => 10,
            'bitrix_department_id' => 5,
        ]);
        $special = Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'Спецпроекты',
            'position' => 11,
        ]);

        $this->employee(101, 'Продавец', [5]);
        $this->tasks = [$this->task(1, 'Задача', 101)];

        $synchronizer = app(TaskSynchronizer::class);
        $synchronizer->syncBoard($this->board);

        $card = TaskCard::first();
        $this->assertSame($commercial->id, $card->department_id);

        // Руководитель увёл задачу в другую дорожку.
        $actor = PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 1,
            'name' => 'Руководитель',
        ]);

        app(CardMover::class)->move(
            card: $card,
            target: $card->column,
            departmentId: $special->id,
            actor: $actor,
            pushToBitrix: false,
        );

        $this->assertTrue($card->fresh()->department_locked);

        $synchronizer->syncBoard($this->board);

        $this->assertSame($special->id, $card->fresh()->department_id);
    }

    public function test_порядок_карточек_независим_в_каждой_ячейке(): void
    {
        $first = Department::create(['portal_id' => $this->portal->id, 'name' => 'Первый', 'position' => 1]);
        $second = Department::create(['portal_id' => $this->portal->id, 'name' => 'Второй', 'position' => 2]);
        $column = $this->board->columns->first();

        $make = fn (string $title, Department $d, int $pos) => $this->board->cards()->create([
            'portal_id' => $this->portal->id,
            'board_column_id' => $column->id,
            'department_id' => $d->id,
            'bitrix_task_id' => crc32($title) % 100000,
            'title' => $title,
            'position' => $pos,
        ]);

        $a1 = $make('А1', $first, 0);
        $make('А2', $first, 1);
        $make('Б1', $second, 0);

        // Уводим карточку из первой дорожки во вторую, в начало.
        app(CardMover::class)->move(
            card: $a1,
            target: $column,
            departmentId: $second->id,
            position: 0,
            pushToBitrix: false,
        );

        $lane = fn (Department $d) => TaskCard::query()
            ->where('department_id', $d->id)
            ->orderBy('position')
            ->pluck('position', 'title')
            ->all();

        // В покинутой дорожке дыра закрылась, в новой карточки раздвинулись.
        $this->assertSame(['А2' => 0], $lane($first));
        $this->assertSame(['А1' => 0, 'Б1' => 1], $lane($second));
    }

    public function test_приоритет_подставляется_из_битрикса(): void
    {
        $this->employee(101, 'Сотрудник', []);
        $this->tasks = [
            $this->task(1, 'Низкий', 101, priority: 0),
            $this->task(2, 'Высокий', 101, priority: 2),
        ];

        app(TaskSynchronizer::class)->syncBoard($this->board);

        $byTitle = TaskCard::with('priorityLevel')->get()->keyBy('title');

        $this->assertSame('Низкий', $byTitle['Низкий']->priorityLevel->name);
        // На PRIORITY=2 заведены и «Высокий», и «Критический» — при импорте
        // берём младший по весу, повышение остаётся ручным.
        $this->assertSame('Высокий', $byTitle['Высокий']->priorityLevel->name);
    }

    public function test_свой_приоритет_не_перетирается_синхронизацией(): void
    {
        $this->employee(101, 'Сотрудник', []);
        $this->tasks = [$this->task(1, 'Задача', 101, priority: 1)];

        $synchronizer = app(TaskSynchronizer::class);
        $synchronizer->syncBoard($this->board);

        $critical = TaskPriority::firstWhere('name', 'Критический');
        $card = TaskCard::first();
        $card->forceFill(['task_priority_id' => $critical->id])->save();

        $synchronizer->syncBoard($this->board);

        // Своих уровней больше, чем штатных: обратное отображение
        // «высокий → критический» неоднозначно, поэтому наш выбор главнее.
        $this->assertSame($critical->id, $card->fresh()->task_priority_id);
    }

    public function test_смена_приоритета_уходит_в_битрикс(): void
    {
        $this->employee(101, 'Сотрудник', []);
        $this->tasks = [$this->task(1, 'Задача', 101, priority: 0)];
        app(TaskSynchronizer::class)->syncBoard($this->board);

        $card = TaskCard::first();
        $high = TaskPriority::firstWhere('name', 'Высокий');

        app(CardMover::class)->pushPriority($card, $high);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'tasks.task.update')
            && ($request['fields']['PRIORITY'] ?? null) === 2);
    }

    public function test_удаление_подразделения_не_теряет_задачи(): void
    {
        $department = Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'Временный',
            'position' => 5,
        ]);

        $this->board->cards()->create([
            'portal_id' => $this->portal->id,
            'board_column_id' => $this->board->columns->first()->id,
            'department_id' => $department->id,
            'bitrix_task_id' => 1,
            'title' => 'Задача',
            'position' => 0,
        ]);

        $department->delete();

        // Задача должна остаться на доске — в дорожке «Без подразделения».
        $this->assertSame(1, TaskCard::count());
        $this->assertNull(TaskCard::first()->department_id);
    }
}
