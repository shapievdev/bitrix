<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Services\Kanban\BoardBuilder;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Доступ к доскам через HTTP.
 *
 * Главное здесь — изоляция порталов: приложение станет тиражным, и
 * утечка данных между клиентами будет самой дорогой ошибкой.
 */
class BoardHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);
        Http::fake(['*' => Http::response(['result' => true])]);
    }

    protected function portal(string $memberId, string $domain): Portal
    {
        return Portal::create([
            'member_id' => $memberId,
            'domain' => $domain,
            'kind' => 'cloud',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
    }

    protected function user(Portal $portal): PortalUser
    {
        return PortalUser::create([
            'portal_id' => $portal->id,
            'bitrix_user_id' => 1,
            'name' => 'Иван Петров',
        ]);
    }

    /**
     * Открыть приложение как сотрудник портала.
     */
    protected function actingOnPortal(Portal $portal, ?PortalUser $user = null): static
    {
        return $this->withSession([
            'bitrix.portal_id' => $portal->id,
            'bitrix.user_id' => ($user ?? $this->user($portal))->id,
        ]);
    }

    protected function boardWithColumns(Portal $portal, string $name): Board
    {
        return PortalContext::run($portal, fn () => app(BoardBuilder::class)->create($name));
    }

    public function test_список_показывает_только_доски_своего_портала(): void
    {
        $mine = $this->portal('member-1', 'mine.bitrix24.ru');
        $theirs = $this->portal('member-2', 'theirs.bitrix24.ru');

        $this->boardWithColumns($mine, 'Моя доска');
        $this->boardWithColumns($theirs, 'Чужая доска');

        $response = $this->actingOnPortal($mine)->get('/app/boards');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Boards/Index')
            ->has('boards', 1)
            ->where('boards.0.name', 'Моя доска')
        );
    }

    public function test_чужая_доска_недоступна_по_прямой_ссылке(): void
    {
        $mine = $this->portal('member-1', 'mine.bitrix24.ru');
        $theirs = $this->portal('member-2', 'theirs.bitrix24.ru');

        $foreign = $this->boardWithColumns($theirs, 'Чужая доска');

        $this->actingOnPortal($mine)
            ->get("/app/boards/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_чужую_карточку_нельзя_переместить(): void
    {
        $mine = $this->portal('member-1', 'mine.bitrix24.ru');
        $theirs = $this->portal('member-2', 'theirs.bitrix24.ru');

        $foreignBoard = $this->boardWithColumns($theirs, 'Чужая доска');

        $foreignCard = PortalContext::run($theirs, fn () => $foreignBoard->cards()->create([
            'portal_id' => $theirs->id,
            'board_column_id' => $foreignBoard->columns->first()->id,
            'bitrix_task_id' => 1,
            'title' => 'Чужая задача',
            'position' => 0,
        ]));

        $target = $foreignBoard->columns->last();

        $this->actingOnPortal($mine)
            ->patch("/app/cards/{$foreignCard->id}/move", [
                'column_id' => $target->id,
                'position' => 0,
            ])
            ->assertNotFound();

        // Карточка не должна была сдвинуться ни на позицию.
        $this->assertSame(
            $foreignBoard->columns->first()->id,
            TaskCard::withoutGlobalScopes()->find($foreignCard->id)->board_column_id,
        );
    }

    public function test_создание_доски_сразу_даёт_рабочие_колонки(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');

        $this->actingOnPortal($portal)
            ->post('/app/boards', ['name' => 'Спринт 12'])
            ->assertRedirect();

        $board = Board::withoutGlobalScopes()->firstWhere('name', 'Спринт 12');

        $this->assertNotNull($board);
        $this->assertSame($portal->id, $board->portal_id);

        // Связи тоже под скоупом портала — читаем их в его контексте.
        $columns = PortalContext::run($portal, fn () => $board->columns()->get());

        $this->assertCount(4, $columns);
    }

    public function test_доска_без_названия_не_создаётся(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');

        $this->actingOnPortal($portal)
            ->post('/app/boards', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Board::withoutGlobalScopes()->count());
    }

    public function test_перемещение_карточки_меняет_колонку(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        $user = $this->user($portal);
        $board = $this->boardWithColumns($portal, 'Моя доска');

        $from = $board->columns->first();
        $to = $board->columns->last();

        $card = PortalContext::run($portal, fn () => $board->cards()->create([
            'portal_id' => $portal->id,
            'board_column_id' => $from->id,
            'bitrix_task_id' => 42,
            'title' => 'Задача',
            'position' => 0,
        ]));

        $this->actingOnPortal($portal, $user)
            ->patch("/app/cards/{$card->id}/move", [
                'column_id' => $to->id,
                'position' => 0,
            ])
            ->assertRedirect();

        $this->assertSame($to->id, $card->fresh()->board_column_id);
    }

    public function test_нельзя_перенести_карточку_в_колонку_другой_доски(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        $board = $this->boardWithColumns($portal, 'Первая');
        $other = $this->boardWithColumns($portal, 'Вторая');

        $card = PortalContext::run($portal, fn () => $board->cards()->create([
            'portal_id' => $portal->id,
            'board_column_id' => $board->columns->first()->id,
            'bitrix_task_id' => 42,
            'title' => 'Задача',
            'position' => 0,
        ]));

        $this->actingOnPortal($portal)
            ->patch("/app/cards/{$card->id}/move", [
                'column_id' => $other->columns->first()->id,
                'position' => 0,
            ])
            ->assertNotFound();
    }

    public function test_доска_отдаёт_колонки_с_карточками(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        $board = $this->boardWithColumns($portal, 'Моя доска');

        PortalContext::run($portal, fn () => $board->cards()->create([
            'portal_id' => $portal->id,
            'board_column_id' => $board->columns->first()->id,
            'bitrix_task_id' => 42,
            'title' => 'Задача',
            'position' => 0,
            'deadline' => now()->subDay(),
        ]));

        $this->actingOnPortal($portal)
            ->get("/app/boards/{$board->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Boards/Show')
                ->has('columns', 4)
                // Типовые подразделения плюс обязательная последняя дорожка
                // «Без подразделения» — задачи без отдела прятать нельзя.
                ->has('departments', 5)
                ->where('departments.4.id', null)
                ->where('departments.4.name', 'Без подразделения')
                ->has('cards', 1)
                ->where('cards.0.title', 'Задача')
                ->where('cards.0.isOverdue', true)
                ->where('cards.0.departmentId', null)
            );
    }
}
