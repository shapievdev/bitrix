<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Department;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Кто какие задачи видит.
 *
 * Три уровня: администратор видит всё, руководитель — своё подразделение
 * вместе с вложенными, остальные — только задачи со своим участием.
 */
class VisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    protected BoardColumn $column;

    protected Department $department;

    protected Department $unit;

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

        $this->column = $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'В работе',
            'position' => 0,
        ]);

        $this->department = Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'IT Отдел',
            'is_primary' => true,
        ]);

        $this->unit = Department::create([
            'portal_id' => $this->portal->id,
            'parent_id' => $this->department->id,
            'name' => 'Разработка',
        ]);
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function person(int $bitrixId, string $name, bool $admin = false): PortalUser
    {
        return PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => $bitrixId,
            'name' => $name,
            'is_admin' => $admin,
        ]);
    }

    protected function card(string $title, array $attributes = [], ?Department $department = null): TaskCard
    {
        $card = $this->board->cards()->create(array_merge([
            'portal_id' => $this->portal->id,
            'board_column_id' => $this->column->id,
            'bitrix_task_id' => crc32($title) % 100000,
            'title' => $title,
            'position' => 0,
        ], $attributes));

        $card->departments()->attach(($department ?? $this->department)->id, [
            'portal_id' => $this->portal->id,
            'source' => 'responsible',
        ]);

        return $card;
    }

    protected function openAs(PortalUser $user, array $query = []): TestResponse
    {
        $url = route('app.boards.show', $this->board->id)
            .($query === [] ? '' : '?'.http_build_query($query));

        return $this
            ->withSession(['bitrix.portal_id' => $this->portal->id, 'bitrix.user_id' => $user->id])
            ->get($url);
    }

    /**
     * @return array<int, string>
     */
    protected function titlesFrom(TestResponse $response): array
    {
        return collect($response->viewData('page')['props']['cards'])
            ->pluck('title')
            ->all();
    }

    public function test_сотрудник_видит_только_задачи_со_своим_участием(): void
    {
        $worker = $this->person(10, 'Исполнитель');

        $this->card('Моя как исполнителя', ['responsible_id' => 10]);
        $this->card('Моя как соисполнителя', ['accomplice_ids' => [10, 11]]);
        $this->card('Моя как наблюдателя', ['auditor_ids' => [10]]);
        $this->card('Моя как постановщика', ['creator_id' => 10]);
        $this->card('Чужая', ['responsible_id' => 99, 'creator_id' => 98]);

        $titles = $this->titlesFrom($this->openAs($worker));

        sort($titles);

        $this->assertSame([
            'Моя как исполнителя',
            'Моя как наблюдателя',
            'Моя как постановщика',
            'Моя как соисполнителя',
        ], $titles);
    }

    public function test_администратор_видит_все_задачи(): void
    {
        $admin = $this->person(1, 'Администратор', admin: true);

        $this->card('Своя', ['responsible_id' => 1]);
        $this->card('Чужая', ['responsible_id' => 99]);

        $this->assertCount(2, $this->titlesFrom($this->openAs($admin)));
    }

    public function test_руководитель_видит_задачи_своего_подразделения(): void
    {
        $head = $this->person(20, 'Руководитель');
        $this->department->forceFill(['head_id' => 20])->save();

        $this->card('Задача отдела', ['responsible_id' => 99]);
        $this->card('Задача чужого отдела', ['responsible_id' => 98], $this->otherDepartment());

        $this->assertSame(['Задача отдела'], $this->titlesFrom($this->openAs($head)));
    }

    public function test_руководитель_видит_и_вложенные_отделы(): void
    {
        $head = $this->person(20, 'Руководитель');
        $this->department->forceFill(['head_id' => 20])->save();

        $this->card('Задача вложенного отдела', ['responsible_id' => 99], $this->unit);

        $this->assertSame(['Задача вложенного отдела'], $this->titlesFrom($this->openAs($head)));
    }

    public function test_руководитель_вложенного_отдела_не_видит_весь_департамент(): void
    {
        $head = $this->person(30, 'Руководитель разработки');
        $this->unit->forceFill(['head_id' => 30])->save();

        $this->card('Задача департамента', ['responsible_id' => 99]);
        $this->card('Задача разработки', ['responsible_id' => 98], $this->unit);

        $this->assertSame(['Задача разработки'], $this->titlesFrom($this->openAs($head)));
    }

    public function test_счётчики_и_список_исполнителей_тоже_ограничены(): void
    {
        $worker = $this->person(10, 'Исполнитель');
        $this->person(99, 'Чужой сотрудник');

        $this->card('Моя', ['responsible_id' => 10]);
        $this->card('Чужая', ['responsible_id' => 99]);

        $props = $this->openAs($worker)->viewData('page')['props'];

        $this->assertSame(1, $props['board']['total']);
        $this->assertSame(1, $props['departments'][0]['count']);

        // В фильтре исполнителей чужих быть не должно — иначе он выдаёт
        // состав отделов, задач которых сотрудник не видит.
        $this->assertSame([10], collect($props['responsibles'])->pluck('id')->all());
    }

    public function test_мои_задачи_сужают_выборку_администратора(): void
    {
        $admin = $this->person(1, 'Администратор', admin: true);

        $this->card('Я исполнитель', ['responsible_id' => 1]);
        $this->card('Я соисполнитель', ['accomplice_ids' => [1]]);
        $this->card('Я наблюдатель', ['auditor_ids' => [1]]);
        $this->card('Я постановщик', ['creator_id' => 1]);
        $this->card('Совсем чужая', ['responsible_id' => 99, 'creator_id' => 98]);

        // Без галочки администратор видит всё.
        $this->assertCount(5, $this->titlesFrom($this->openAs($admin)));

        $titles = $this->titlesFrom($this->openAs($admin, ['mine' => 1]));
        sort($titles);

        $this->assertSame([
            'Я исполнитель',
            'Я наблюдатель',
            'Я постановщик',
            'Я соисполнитель',
        ], $titles);
    }

    public function test_мои_задачи_сужают_и_счётчики(): void
    {
        $admin = $this->person(1, 'Администратор', admin: true);

        $this->card('Моя', ['responsible_id' => 1]);
        $this->card('Чужая', ['responsible_id' => 99]);

        $props = $this->openAs($admin, ['mine' => 1])->viewData('page')['props'];

        $this->assertSame(1, $props['board']['total']);
        $this->assertSame(1, $props['departments'][0]['count']);
        $this->assertSame([1], collect($props['responsibles'])->pluck('id')->all());
    }

    public function test_руководителю_переключатель_доступен_а_рядовому_нет(): void
    {
        $worker = $this->person(10, 'Исполнитель');
        $head = $this->person(20, 'Руководитель');
        $this->department->forceFill(['head_id' => 20])->save();

        $this->assertFalse(
            $this->openAs($worker)->viewData('page')['props']['viewer']['canNarrowToOwn'],
        );
        $this->assertTrue(
            $this->openAs($head)->viewData('page')['props']['viewer']['canNarrowToOwn'],
        );
    }

    public function test_рядовому_сотруднику_галочка_ничего_не_расширяет(): void
    {
        $worker = $this->person(10, 'Исполнитель');

        $this->card('Моя', ['responsible_id' => 10]);
        $this->card('Чужая', ['responsible_id' => 99]);

        // Параметр в адресе не должен работать как лазейка в обратную
        // сторону: сужение остаётся сужением.
        $this->assertSame(['Моя'], $this->titlesFrom($this->openAs($worker, ['mine' => 1])));
    }

    public function test_задача_в_нескольких_отделах_считается_один_раз(): void
    {
        $admin = $this->person(1, 'Администратор', admin: true);

        $second = Department::create([
            'portal_id' => $this->portal->id,
            'parent_id' => $this->department->id,
            'name' => 'Поддержка',
        ]);

        // Одна задача с участниками из разных отделов привязана сразу к
        // департаменту и к двум его отделам. Раньше счётчик складывал
        // числа по узлам и показывал три задачи вместо одной.
        $card = $this->card('Общая на весь департамент', ['responsible_id' => 99]);
        $card->departments()->attach([$this->unit->id, $second->id], [
            'portal_id' => $this->portal->id,
            'source' => 'accomplice',
        ]);

        $props = $this->openAs($admin)->viewData('page')['props'];

        $this->assertSame(1, $props['departments'][0]['count']);
        $this->assertSame(1, $props['board']['total']);
    }

    public function test_счётчик_отдела_совпадает_с_доской_после_клика(): void
    {
        $admin = $this->person(1, 'Администратор', admin: true);

        $card = $this->card('Через два отдела', ['responsible_id' => 99]);
        $card->departments()->attach($this->unit->id, [
            'portal_id' => $this->portal->id,
            'source' => 'accomplice',
        ]);

        $this->card('Только в департаменте', ['responsible_id' => 98]);

        $props = $this->openAs($admin)->viewData('page')['props'];
        $counter = $props['departments'][0]['count'];

        // Кликаем по тому же узлу и сравниваем с тем, что реально легло
        // на доску: расхождение здесь и было исходной жалобой.
        $onBoard = $this->titlesFrom($this->openAs($admin, ['department' => $this->department->id]));

        $this->assertSame(2, $counter);
        $this->assertCount($counter, $onBoard);
    }

    public function test_чужую_задачу_нельзя_переместить(): void
    {
        $worker = $this->person(10, 'Исполнитель');
        $foreign = $this->card('Чужая', ['responsible_id' => 99]);

        $this
            ->withSession(['bitrix.portal_id' => $this->portal->id, 'bitrix.user_id' => $worker->id])
            ->patch(route('app.cards.move', $foreign->id), [
                'column_id' => $this->column->id,
                'position' => 0,
            ])
            ->assertForbidden();
    }

    public function test_чужую_задачу_нельзя_открыть_во_вкладке(): void
    {
        $worker = $this->person(10, 'Исполнитель');
        $foreign = $this->card('Чужая', ['responsible_id' => 99]);

        $response = $this
            ->withSession(['bitrix.portal_id' => $this->portal->id, 'bitrix.user_id' => $worker->id])
            ->get(route('app.tasks.show', $foreign->bitrix_task_id));

        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['forbidden']);
        $this->assertNull($props['card']);
    }

    protected function otherDepartment(): Department
    {
        return Department::create([
            'portal_id' => $this->portal->id,
            'name' => 'Финансовый департамент',
            'is_primary' => true,
        ]);
    }
}
