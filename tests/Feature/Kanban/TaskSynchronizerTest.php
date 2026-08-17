<?php

namespace Tests\Feature\Kanban;

use App\Jobs\ProcessBitrixEvent;
use App\Models\Board;
use App\Models\Portal;
use App\Models\TaskCard;
use App\Services\Kanban\BoardBuilder;
use App\Services\Kanban\CardMover;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);

        Http::fake([
            '*/rest/tasks.task.list*' => fn () => Http::response([
                'result' => ['tasks' => $this->tasks],
                'total' => count($this->tasks),
            ]),
            '*/rest/tasks.task.get*' => fn () => $this->singleTask === null
                ? Http::response(['error' => 'TASK_NOT_FOUND'], 400)
                : Http::response(['result' => ['task' => $this->singleTask]]),
            // Заглушка на всё остальное обязательна: незаматченный запрос
            // Laravel выполняет по-настоящему и уходит в реальный портал.
            '*' => Http::response(['result' => []]),
        ]);

        $this->portal = Portal::create([
            'member_id' => 'member-123',
            'domain' => 'example.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'application_token' => 'app-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        PortalContext::set($this->portal);

        $this->board = app(BoardBuilder::class)->create('Разработка', groupId: 7);
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function task(int $id, string $title, int $status = 1, array $extra = []): array
    {
        return array_merge([
            'id' => (string) $id,
            'title' => $title,
            'status' => (string) $status,
            'responsibleId' => '10',
            'createdBy' => '1',
            'groupId' => '7',
            'priority' => '1',
        ], $extra);
    }

    /**
     * Задачи, которые «есть» на портале прямо сейчас.
     *
     * Держим в свойстве, а не перерегистрируем Http::fake: повторный
     * вызов fake() заглушки дополняет, а не заменяет, и отвечала бы
     * всегда самая первая.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $tasks = [];

    /** Ответ tasks.task.get; null — задачи на портале нет. */
    protected ?array $singleTask = null;

    protected function fakeList(array $tasks): void
    {
        $this->tasks = $tasks;
    }

    public function test_новая_доска_получает_колонки_под_штатные_статусы(): void
    {
        $columns = $this->board->columns;

        $this->assertCount(4, $columns);
        $this->assertSame(['Новые', 'В работе', 'На проверке', 'Готово'], $columns->pluck('name')->all());
        $this->assertTrue($columns->first()->is_default);
        $this->assertTrue($columns->last()->is_final);
    }

    public function test_синхронизация_раскладывает_задачи_по_колонкам_статусов(): void
    {
        $this->fakeList([
            $this->task(1, 'Свёрстать форму', status: 1),
            $this->task(2, 'Починить импорт', status: 3),
            $this->task(3, 'Выкатить релиз', status: 5),
        ]);

        $stats = app(TaskSynchronizer::class)->syncBoard($this->board);

        $this->assertSame(3, $stats['created']);

        $byTitle = TaskCard::with('column')->get()->keyBy('title');

        $this->assertSame('Новые', $byTitle['Свёрстать форму']->column->name);
        $this->assertSame('В работе', $byTitle['Починить импорт']->column->name);
        $this->assertSame('Готово', $byTitle['Выкатить релиз']->column->name);
    }

    public function test_повторная_синхронизация_не_плодит_карточки(): void
    {
        $this->fakeList([$this->task(1, 'Свёрстать форму')]);

        $synchronizer = app(TaskSynchronizer::class);
        $synchronizer->syncBoard($this->board);

        $this->fakeList([$this->task(1, 'Свёрстать форму заново')]);
        $stats = $synchronizer->syncBoard($this->board);

        $this->assertSame(1, TaskCard::count());
        $this->assertSame(1, $stats['updated']);
        $this->assertSame('Свёрстать форму заново', TaskCard::first()->title);
    }

    public function test_задача_выпавшая_из_фильтра_снимается_с_доски(): void
    {
        $this->fakeList([
            $this->task(1, 'Останется'),
            $this->task(2, 'Уйдёт'),
        ]);

        $synchronizer = app(TaskSynchronizer::class);
        $synchronizer->syncBoard($this->board);

        $this->fakeList([$this->task(1, 'Останется')]);
        $stats = $synchronizer->syncBoard($this->board);

        $this->assertSame(1, $stats['removed']);
        $this->assertSame(['Останется'], TaskCard::pluck('title')->all());
    }

    public function test_карточка_не_теряет_свою_колонку_при_обновлении(): void
    {
        $this->fakeList([$this->task(1, 'Задача', status: 3)]);

        $synchronizer = app(TaskSynchronizer::class);
        $synchronizer->syncBoard($this->board);

        // Пользователь увёл карточку в колонку без привязки к статусу —
        // ровно то, ради чего приложение и делается.
        $custom = $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'Ревью',
            'position' => 9,
        ]);

        $card = TaskCard::first();
        app(CardMover::class)->move($card, $custom, pushToBitrix: false);

        // Статус в Битриксе не менялся — синхронизация не должна утащить
        // карточку обратно в колонку статуса.
        $this->fakeList([$this->task(1, 'Задача переименована', status: 3)]);
        $synchronizer->syncBoard($this->board);

        $card->refresh();

        $this->assertSame($custom->id, $card->board_column_id);
        $this->assertSame('Задача переименована', $card->title);
    }

    public function test_смена_статуса_в_битриксе_двигает_карточку(): void
    {
        $this->fakeList([$this->task(1, 'Задача', status: 1)]);

        $synchronizer = app(TaskSynchronizer::class);
        $synchronizer->syncBoard($this->board);

        $this->fakeList([$this->task(1, 'Задача', status: 5)]);
        $synchronizer->syncBoard($this->board);

        $this->assertSame('Готово', TaskCard::first()->column->name);
    }

    public function test_событие_обновления_синхронизирует_одну_задачу(): void
    {
        $this->singleTask = $this->task(55, 'Из события', status: 3);

        (new ProcessBitrixEvent($this->portal->id, 'ONTASKUPDATE', [
            'FIELDS_AFTER' => ['ID' => '55'],
        ]))->handle(app(TaskSynchronizer::class));

        $card = TaskCard::firstWhere('bitrix_task_id', 55);

        $this->assertNotNull($card);
        $this->assertSame('Из события', $card->title);
        $this->assertSame('В работе', $card->column->name);
    }

    public function test_событие_удаления_снимает_карточку(): void
    {
        $this->fakeList([$this->task(1, 'Задача')]);
        app(TaskSynchronizer::class)->syncBoard($this->board);

        $this->assertSame(1, TaskCard::count());

        (new ProcessBitrixEvent($this->portal->id, 'ONTASKDELETE', [
            'FIELDS_BEFORE' => ['ID' => '1'],
        ]))->handle(app(TaskSynchronizer::class));

        $this->assertSame(0, TaskCard::count());
    }

    public function test_недоступная_задача_снимается_а_не_роняет_обработку(): void
    {
        $this->fakeList([$this->task(1, 'Задача')]);
        app(TaskSynchronizer::class)->syncBoard($this->board);

        // Задачу удалили между отправкой события и нашим запросом.
        $this->singleTask = null;

        (new ProcessBitrixEvent($this->portal->id, 'ONTASKUPDATE', [
            'FIELDS_AFTER' => ['ID' => '1'],
        ]))->handle(app(TaskSynchronizer::class));

        $this->assertSame(0, TaskCard::count());
    }

    public function test_доска_без_колонок_не_синхронизируется(): void
    {
        $empty = Board::create(['portal_id' => $this->portal->id, 'name' => 'Пустая']);

        $this->expectException(\RuntimeException::class);

        app(TaskSynchronizer::class)->syncBoard($empty);
    }
}
