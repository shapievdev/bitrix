<?php

namespace Tests\Feature\Kanban;

use App\Enums\TaskStatus;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Department;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Поиск, фильтры и счётчики боковой панели.
 */
class FiltersTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    protected BoardColumn $active;

    protected BoardColumn $done;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);
        Http::fake(['*' => Http::response(['result' => []])]);

        $this->portal = Portal::create([
            'member_id' => 'member-1',
            'domain' => 'mine.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'access',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        PortalContext::set($this->portal);

        $this->board = Board::create(['portal_id' => $this->portal->id, 'name' => 'Доска']);

        $this->active = $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'В работе',
            'position' => 0,
            'bitrix_status' => TaskStatus::InProgress->value,
        ]);

        $this->done = $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'Завершены',
            'position' => 1,
            'bitrix_status' => TaskStatus::Completed->value,
            'is_final' => true,
        ]);

        $this->department = Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'IT Отдел',
            'is_primary' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function card(string $title, array $attributes = [], ?BoardColumn $column = null): TaskCard
    {
        $card = $this->board->cards()->create(array_merge([
            'portal_id' => $this->portal->id,
            'board_column_id' => ($column ?? $this->active)->id,
            'bitrix_task_id' => crc32($title) % 100000,
            'title' => $title,
            'position' => 0,
        ], $attributes));

        $card->departments()->attach($this->department->id, [
            'portal_id' => $this->portal->id,
            'source' => 'responsible',
        ]);

        return $card;
    }

    protected function open(array $query = []): TestResponse
    {
        // Администратор: проверяются фильтры, а не права. Видимость
        // рядового сотрудника разобрана в VisibilityTest.
        $user = PortalUser::firstOrCreate(
            ['portal_id' => $this->portal->id, 'bitrix_user_id' => 1],
            ['name' => 'Иван Петров', 'is_admin' => true],
        );

        return $this
            ->withSession(['bitrix.portal_id' => $this->portal->id, 'bitrix.user_id' => $user->id])
            ->get(route('app.boards.show', $this->board->id).'?'.http_build_query($query));
    }

    public function test_счётчики_не_учитывают_завершённые(): void
    {
        $this->card('Активная');
        $this->card('Закрытая', column: $this->done);

        // Количество завершённых растёт вечно и перестаёт что-либо значить —
        // в панели нужна текущая нагрузка отдела, а не архив.
        $this->open()->assertInertia(fn ($page) => $page
            ->where('departments.0.count', 1)
            ->where('board.total', 1)
            // При этом сами завершённые с доски не пропадают.
            ->has('cards', 2)
        );
    }

    public function test_поиск_по_названию(): void
    {
        $this->card('Обновить прайс');
        $this->card('Закупка канцтоваров');

        $this->open(['q' => 'прайс'])->assertInertia(fn ($page) => $page
            ->has('cards', 1)
            ->where('cards.0.title', 'Обновить прайс')
        );
    }

    public function test_поиск_нечувствителен_к_регистру(): void
    {
        $this->card('Обновить ПРАЙС на сайте');

        $this->open(['q' => 'прайс'])->assertInertia(fn ($page) => $page->has('cards', 1));
    }

    public function test_поиск_по_номеру_задачи(): void
    {
        $card = $this->card('Первая');
        $this->card('Вторая');

        // Номер ищем точным совпадением: подстрочный поиск по числу выдал бы
        // сотни лишних задач.
        $this->open(['q' => '#'.$card->bitrix_task_id])
            ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.title', 'Первая'));
    }

    public function test_фильтр_по_приоритету(): void
    {
        $critical = TaskPriority::create([
            'portal_id' => $this->portal->id, 'name' => 'Критический', 'weight' => 40,
        ]);

        $this->card('Срочная', ['task_priority_id' => $critical->id]);
        $this->card('Обычная');

        $this->open(['priority' => $critical->id])
            ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.title', 'Срочная'));
    }

    public function test_фильтр_по_исполнителю(): void
    {
        $this->card('Моя', ['responsible_id' => 101]);
        $this->card('Чужая', ['responsible_id' => 102]);

        $this->open(['responsible' => 101])
            ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.title', 'Моя'));
    }

    public function test_фильтр_по_соисполнителю(): void
    {
        $this->card('С помощником', ['responsible_id' => 1, 'accomplice_ids' => [101, 102]]);
        $this->card('С другим помощником', ['responsible_id' => 1, 'accomplice_ids' => [103]]);
        $this->card('Одиночная', ['responsible_id' => 101]);

        // Исполнитель в соисполнители не попадает: это разные роли, и
        // фильтр отвечает на вопрос «где человек помогает».
        $this->open(['accomplice' => 101])
            ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.title', 'С помощником'));
    }

    public function test_список_соисполнителей_только_с_доски(): void
    {
        PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 101,
            'name' => 'Пётр Смирнов',
        ]);
        PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 500,
            'name' => 'Никто Ничейный',
        ]);

        // Один и тот же человек в двух задачах — в списке один раз.
        $this->card('Первая', ['accomplice_ids' => [101]]);
        $this->card('Вторая', ['accomplice_ids' => [101]]);
        $this->card('Без помощников');

        $this->open()->assertInertia(fn ($page) => $page
            ->has('accomplices', 1)
            ->where('accomplices.0.name', 'Пётр Смирнов')
        );
    }

    public function test_фильтр_просроченных(): void
    {
        $this->card('Просрочена', ['deadline' => now()->subDay()]);
        $this->card('В срок', ['deadline' => now()->addWeek()]);
        $this->card('Без срока');

        // Закрытая задача просроченной не считается, даже если срок прошёл.
        $this->card('Закрытая', ['deadline' => now()->subDay(), 'closed_at' => now()], $this->done);

        $this->open(['deadline' => 'overdue'])
            ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.title', 'Просрочена'));
    }

    public function test_фильтр_без_срока(): void
    {
        $this->card('Без срока');
        $this->card('Со сроком', ['deadline' => now()->addWeek()]);

        $this->open(['deadline' => 'without'])
            ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.title', 'Без срока'));
    }

    public function test_фильтры_складываются_с_выбором_отдела(): void
    {
        $other = Department::create([
            'portal_id' => $this->portal->id, 'name' => 'Другой', 'is_primary' => true,
        ]);

        $this->card('Наша срочная', ['responsible_id' => 101]);

        $foreign = $this->board->cards()->create([
            'portal_id' => $this->portal->id,
            'board_column_id' => $this->active->id,
            'bitrix_task_id' => 999,
            'title' => 'Чужая срочная',
            'position' => 1,
            'responsible_id' => 101,
        ]);
        $foreign->departments()->attach($other->id, [
            'portal_id' => $this->portal->id,
            'source' => 'responsible',
        ]);

        $this->open(['department' => $this->department->id, 'responsible' => 101])
            ->assertInertia(fn ($page) => $page->has('cards', 1)->where('cards.0.title', 'Наша срочная'));
    }

    public function test_список_исполнителей_только_с_доски(): void
    {
        PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 101,
            'name' => 'Пётр Смирнов',
        ]);
        PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 500,
            'name' => 'Никто Ничейный',
        ]);

        $this->card('Задача', ['responsible_id' => 101]);

        // Список всех сотрудников портала в фильтре бесполезен.
        $this->open()->assertInertia(fn ($page) => $page
            ->has('responsibles', 1)
            ->where('responsibles.0.name', 'Пётр Смирнов')
        );
    }
}
