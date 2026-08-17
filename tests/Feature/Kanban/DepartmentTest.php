<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\Department;
use App\Models\Portal;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Kanban\BoardBuilder;
use App\Services\Kanban\PortalDictionaries;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Оргструктура и привязка задач к отделам.
 *
 * Задача принадлежит отделу исполнителя и отделам всех соисполнителей —
 * то есть сразу нескольким. Приписывать её только исполнителю значит
 * спрятать её от тех, кто в ней реально участвует.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    /** @var array<int, array<string, mixed>> */
    protected array $tasks = [];

    /** @var array<int, array<string, mixed>> */
    protected array $users = [];

    /** Оргструктура портала: ID, NAME, PARENT. */
    protected array $structure = [
        ['ID' => '1', 'NAME' => 'BISMAR'],
        ['ID' => '302', 'NAME' => 'Исполнительный директор', 'PARENT' => '1'],
        ['ID' => '294', 'NAME' => 'Коммерческий департамент', 'PARENT' => '302'],
        ['ID' => '298', 'NAME' => 'Отдел маркетинга', 'PARENT' => '294'],
        ['ID' => '422', 'NAME' => 'Корпоративный отдел', 'PARENT' => '294'],
        ['ID' => '314', 'NAME' => 'Корп отдел B2B', 'PARENT' => '422'],
        ['ID' => '292', 'NAME' => 'IT Отдел', 'PARENT' => '302'],
        ['ID' => '288', 'NAME' => 'Служба безопасности', 'PARENT' => '1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);

        Http::fake([
            '*/rest/department.get*' => fn () => Http::response(['result' => $this->structure]),
            '*/rest/tasks.task.list*' => fn () => Http::response([
                'result' => ['tasks' => $this->tasks],
                'total' => count($this->tasks),
            ]),
            '*/rest/batch*' => fn ($request) => Http::response($this->batchResponse($request)),
            '*' => Http::response(['result' => []]),
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

        $this->board = app(BoardBuilder::class)->create('Задачи компании');
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function batchResponse($request): array
    {
        $result = [];

        foreach ($request['cmd'] ?? [] as $key => $command) {
            preg_match('/ID=(\d+)/', (string) $command, $m);
            $id = (int) ($m[1] ?? 0);

            if (isset($this->users[$id])) {
                $result[$key] = [$this->users[$id]];
            }
        }

        return ['result' => ['result' => $result, 'result_error' => []]];
    }

    protected function employee(int $id, array $departmentIds): void
    {
        $this->users[$id] = [
            'ID' => (string) $id,
            'NAME' => "Сотрудник {$id}",
            'LAST_NAME' => '',
            'UF_DEPARTMENT' => $departmentIds,
        ];
    }

    protected function task(int $id, string $title, int $responsibleId, array $accomplices = []): array
    {
        return [
            'id' => (string) $id,
            'title' => $title,
            'status' => '2',
            'priority' => '1',
            'responsibleId' => (string) $responsibleId,
            'createdBy' => '1',
            'accomplices' => array_map('strval', $accomplices),
        ];
    }

    protected function department(string $name): Department
    {
        return Department::query()->where('name', $name)->firstOrFail();
    }

    public function test_оргструктура_импортируется_деревом(): void
    {
        $this->assertSame(count($this->structure), Department::count());

        $commercial = $this->department('Коммерческий департамент');
        $marketing = $this->department('Отдел маркетинга');
        $b2b = $this->department('Корп отдел B2B');

        $this->assertSame($commercial->id, $marketing->parent_id);
        $this->assertSame($this->department('Корпоративный отдел')->id, $b2b->parent_id);
        $this->assertNull($this->department('BISMAR')->parent_id);
    }

    public function test_основные_департаменты_отмечены(): void
    {
        $primary = Department::query()->primary()->pluck('name')->sort()->values()->all();

        // В дереве портала они лежат на разной глубине: Коммерческий и IT
        // под «Исполнительным директором», Служба безопасности под корнем.
        $this->assertSame(
            ['IT Отдел', 'Коммерческий департамент', 'Служба безопасности'],
            $primary,
        );

        $this->assertFalse($this->department('Отдел маркетинга')->is_primary);
        $this->assertFalse($this->department('BISMAR')->is_primary);
    }

    public function test_повторный_импорт_не_плодит_узлы(): void
    {
        app(PortalDictionaries::class)->importFromBitrix($this->portal);

        $this->assertSame(count($this->structure), Department::count());
    }

    public function test_задача_привязывается_к_отделу_исполнителя(): void
    {
        $this->employee(101, [298]);
        $this->tasks = [$this->task(1, 'Баннеры', 101)];

        app(TaskSynchronizer::class)->syncBoard($this->board);

        $card = TaskCard::with('departments')->firstOrFail();

        $this->assertSame(['Отдел маркетинга'], $card->departments->pluck('name')->all());
        $this->assertSame('responsible', $card->departments->first()->pivot->source);
    }

    public function test_задача_попадает_во_все_отделы_соисполнителей(): void
    {
        $this->employee(101, [298]);   // маркетинг — исполнитель
        $this->employee(102, [292]);   // IT — соисполнитель
        $this->employee(103, [288]);   // безопасность — соисполнитель

        $this->tasks = [$this->task(1, 'Запуск лендинга', 101, [102, 103])];

        app(TaskSynchronizer::class)->syncBoard($this->board);

        $card = TaskCard::with('departments')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['Отдел маркетинга', 'IT Отдел', 'Служба безопасности'],
            $card->departments->pluck('name')->all(),
        );

        $sources = $card->departments->pluck('pivot.source', 'name');

        $this->assertSame('responsible', $sources['Отдел маркетинга']);
        $this->assertSame('accomplice', $sources['IT Отдел']);
    }

    public function test_роль_исполнителя_не_понижается_до_соисполнителя(): void
    {
        // Один и тот же отдел и у исполнителя, и у соисполнителя.
        $this->employee(101, [298]);
        $this->employee(102, [298]);

        $this->tasks = [$this->task(1, 'Задача', 101, [102])];

        app(TaskSynchronizer::class)->syncBoard($this->board);

        $card = TaskCard::with('departments')->firstOrFail();

        $this->assertCount(1, $card->departments);
        $this->assertSame('responsible', $card->departments->first()->pivot->source);
    }

    public function test_смена_состава_переписывает_привязки(): void
    {
        $this->employee(101, [298]);
        $this->employee(102, [292]);

        $this->tasks = [$this->task(1, 'Задача', 101, [102])];
        $synchronizer = app(TaskSynchronizer::class);
        $synchronizer->syncBoard($this->board);

        $this->assertCount(2, TaskCard::with('departments')->first()->departments);

        // Соисполнителя убрали — отдел должен отвалиться.
        $this->tasks = [$this->task(1, 'Задача', 101)];
        $synchronizer->syncBoard($this->board);

        $card = TaskCard::with('departments')->first();

        $this->assertSame(['Отдел маркетинга'], $card->departments->pluck('name')->all());
    }

    public function test_фильтр_по_департаменту_включает_его_отделы(): void
    {
        $all = Department::all();
        $commercial = $this->department('Коммерческий департамент');

        $subtree = Department::query()
            ->whereIn('id', $commercial->subtreeIds($all))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        // Выбрав департамент, руководитель ждёт увидеть и вложенные отделы,
        // включая второй уровень, а не пустой экран.
        $this->assertSame(
            ['Коммерческий департамент', 'Корп отдел B2B', 'Корпоративный отдел', 'Отдел маркетинга'],
            $subtree,
        );
    }

    public function test_задача_без_отдела_у_участников_ни_к_кому_не_привязана(): void
    {
        $this->employee(101, [999]);
        $this->tasks = [$this->task(1, 'Ничья', 101)];

        app(TaskSynchronizer::class)->syncBoard($this->board);

        $card = TaskCard::with('departments')->firstOrFail();

        // Задача остаётся на доске и видна в «Все задачи» — прятать её
        // нельзя, именно такие и теряются.
        $this->assertCount(0, $card->departments);
        $this->assertSame(1, TaskCard::count());
    }

    public function test_приоритеты_заводятся_вместе_с_доской(): void
    {
        $this->assertSame(4, TaskPriority::count());
        $this->assertSame('Обычный', TaskPriority::firstWhere('is_default', true)->name);
    }
}
