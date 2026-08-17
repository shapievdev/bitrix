<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Быстрое создание задачи с доски и состав участников на карточке.
 */
class TaskCreationTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    protected PortalUser $user;

    /** Портал отказывает в создании задачи. */
    protected bool $addFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);

        Http::fake([
            '*/rest/tasks.task.add*' => fn () => $this->addFails
                ? Http::response(['error' => 'ACCESS_DENIED'], 403)
                : Http::response(['result' => ['task' => ['id' => '4242']]]),
            '*/rest/tasks.task.get*' => Http::response(['result' => ['task' => [
                'id' => '4242',
                'title' => 'Обновить прайс',
                'status' => '2',
                'priority' => '1',
                'responsibleId' => '101',
                'accomplices' => ['102', '103'],
            ]]]),
            '*' => Http::response(['result' => []]),
        ]);

        $this->portal = Portal::create([
            'member_id' => 'member-1',
            'domain' => 'mine.bitrix24.ru',
            'kind' => 'cloud',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        PortalContext::set($this->portal);

        $this->user = PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 101,
            'name' => 'Иван Петров',
            'access_token' => 'user-access',
            'refresh_token' => 'user-refresh',
            'token_expires_at' => now()->addHour(),
        ]);

        PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 102,
            'name' => 'Пётр Смирнов',
        ]);

        $this->board = Board::create(['portal_id' => $this->portal->id, 'name' => 'Доска']);
        $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'Новые',
            'position' => 0,
            'bitrix_status' => 2,
            'is_default' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function acting(): static
    {
        return $this->withSession([
            'bitrix.portal_id' => $this->portal->id,
            'bitrix.user_id' => $this->user->id,
        ]);
    }

    protected function create(string $title): TestResponse
    {
        $response = $this->acting()->post(route('app.tasks.store', $this->board->id), ['title' => $title]);

        // Мидлвара очищает контекст портала на выходе из запроса, а без
        // него глобальный скоуп моделей не вернёт ничего.
        PortalContext::set($this->portal);

        return $response;
    }

    public function test_быстрая_задача_создаётся_и_попадает_на_доску(): void
    {
        $this->create('Обновить прайс')->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'tasks.task.add')
            && $r['fields']['TITLE'] === 'Обновить прайс');

        // Обычная синхронизация новую задачу не нашла бы: она не привязана
        // к проекту и не подходит ни под один фильтр по группе.
        $card = TaskCard::firstWhere('bitrix_task_id', 4242);

        $this->assertNotNull($card);
        $this->assertSame('Обновить прайс', $card->title);
    }

    public function test_задача_ставится_от_имени_сотрудника(): void
    {
        $this->create('Задача');

        // Иначе постановщиком всех задач в портале станет приложение.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tasks.task.add')
            && $r['fields']['RESPONSIBLE_ID'] === 101
            && $r['fields']['CREATED_BY'] === 101);
    }

    public function test_теги_отделяются_от_названия(): void
    {
        $this->create('Обновить прайс #срочно #продажи');

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'tasks.task.add')) {
                return false;
            }

            return $r['fields']['TITLE'] === 'Обновить прайс'
                && $r['fields']['TAGS'] === ['срочно', 'продажи'];
        });
    }

    public function test_название_из_одних_тегов_отклоняется(): void
    {
        $this->create('#срочно')->assertSessionHas('error');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'tasks.task.add'));
    }

    public function test_пустое_название_не_проходит_проверку(): void
    {
        $this->acting()
            ->post(route('app.tasks.store', $this->board->id), ['title' => ''])
            ->assertSessionHasErrors('title');
    }

    public function test_на_карточке_видны_исполнитель_и_соисполнители(): void
    {
        $this->create('Обновить прайс');

        $this->acting()
            ->get(route('app.boards.show', $this->board->id))
            ->assertInertia(fn ($page) => $page
                ->has('cards.0.people', 3)
                ->where('cards.0.people.0.name', 'Иван Петров')
                ->where('cards.0.people.0.role', 'responsible')
                ->where('cards.0.people.1.name', 'Пётр Смирнов')
                ->where('cards.0.people.1.role', 'accomplice')
                // Сотрудника #103 в нашей базе нет — карточка всё равно
                // должна отрисоваться, а не потерять участника.
                ->where('cards.0.people.2.name', 'Сотрудник #103')
            );
    }

    public function test_задача_создаётся_даже_если_токен_сотрудника_протух(): void
    {
        // Токен есть, но обновить его не выйдет.
        $this->user->forceFill([
            'token_expires_at' => now()->subHour(),
            'refresh_token' => null,
        ])->save();

        $this->create('Задача')->assertRedirect();

        // Терять задачу из-за проблем с авторизацией одного человека
        // нельзя — ставим от имени приложения.
        $this->assertNotNull(TaskCard::firstWhere('bitrix_task_id', 4242));
    }

    public function test_сбой_портала_не_создаёт_карточку(): void
    {
        $this->addFails = true;

        $this->create('Задача')->assertSessionHas('error');

        $this->assertSame(0, TaskCard::count());
    }
}
