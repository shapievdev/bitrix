<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\Department;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Models\TaskCard;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Канбан через HTTP.
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
        Http::fake(['*' => Http::response(['result' => []])]);
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
        return PortalUser::firstOrCreate(
            ['portal_id' => $portal->id, 'bitrix_user_id' => 1],
            ['name' => 'Иван Петров'],
        );
    }

    protected function actingOnPortal(Portal $portal, ?PortalUser $user = null): static
    {
        return $this->withSession([
            'bitrix.portal_id' => $portal->id,
            'bitrix.user_id' => ($user ?? $this->user($portal))->id,
        ]);
    }

    /**
     * Доска с колонкой и двухуровневой оргструктурой.
     *
     * @return array{0: Board, 1: Department, 2: Department}
     */
    protected function scaffold(Portal $portal): array
    {
        return PortalContext::run($portal, function () use ($portal) {
            $board = Board::create(['portal_id' => $portal->id, 'name' => 'Задачи компании']);

            $board->columns()->create([
                'portal_id' => $portal->id,
                'name' => 'В работе',
                'position' => 0,
            ]);

            $department = Department::create([
                'portal_id' => $portal->id,
                'name' => 'Коммерческий департамент',
                'is_primary' => true,
            ]);

            $unit = Department::create([
                'portal_id' => $portal->id,
                'parent_id' => $department->id,
                'name' => 'Отдел маркетинга',
            ]);

            return [$board, $department, $unit];
        });
    }

    protected function card(Portal $portal, Board $board, string $title, ?Department $department = null): TaskCard
    {
        return PortalContext::run($portal, function () use ($portal, $board, $title, $department) {
            $card = $board->cards()->create([
                'portal_id' => $portal->id,
                'board_column_id' => $board->columns()->first()->id,
                'bitrix_task_id' => crc32($title) % 100000,
                'title' => $title,
                'position' => 0,
            ]);

            if ($department) {
                $card->departments()->attach($department->id, [
                    'portal_id' => $portal->id,
                    'source' => 'responsible',
                ]);
            }

            return $card;
        });
    }

    public function test_вход_ведёт_сразу_на_канбан(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        [$board] = $this->scaffold($portal);

        $this->actingOnPortal($portal)
            ->get('/app')
            ->assertRedirect(route('app.boards.show', $board->id));
    }

    public function test_канбан_отдаёт_департаменты_отделы_и_карточки(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        [$board, $department, $unit] = $this->scaffold($portal);
        $this->card($portal, $board, 'Задача маркетинга', $unit);

        $this->actingOnPortal($portal)
            ->get(route('app.boards.show', $board->id).'?department='.$department->id)
            ->assertInertia(fn ($page) => $page
                ->component('Boards/Show')
                // Слева только основные департаменты, отделы — во второй панели.
                ->has('departments', 1)
                ->where('departments.0.name', 'Коммерческий департамент')
                ->has('units', 1)
                ->where('units.0.name', 'Отдел маркетинга')
                ->has('cards', 1)
                ->where('cards.0.title', 'Задача маркетинга')
                ->where('cards.0.departments.0.name', 'Отдел маркетинга')
            );
    }

    public function test_выбор_департамента_показывает_задачи_вложенных_отделов(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        [$board, $department, $unit] = $this->scaffold($portal);

        $this->card($portal, $board, 'Задача отдела', $unit);
        $this->card($portal, $board, 'Задача департамента', $department);
        $this->card($portal, $board, 'Ничья задача');

        // Выбрав департамент, руководитель ждёт увидеть и вложенные отделы.
        $this->actingOnPortal($portal)
            ->get(route('app.boards.show', $board->id).'?department='.$department->id)
            ->assertInertia(fn ($page) => $page->has('cards', 2));

        // Без выбора — всё, включая задачи без отдела: именно такие теряются.
        $this->actingOnPortal($portal)
            ->get(route('app.boards.show', $board->id))
            ->assertInertia(fn ($page) => $page->has('cards', 3));
    }

    public function test_счётчик_департамента_включает_вложенные_отделы(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        [$board, $department, $unit] = $this->scaffold($portal);

        $this->card($portal, $board, 'Первая', $unit);
        $this->card($portal, $board, 'Вторая', $department);

        $this->actingOnPortal($portal)
            ->get(route('app.boards.show', $board->id))
            ->assertInertia(fn ($page) => $page->where('departments.0.count', 2));
    }

    public function test_чужая_доска_недоступна_по_прямой_ссылке(): void
    {
        $mine = $this->portal('member-1', 'mine.bitrix24.ru');
        $theirs = $this->portal('member-2', 'theirs.bitrix24.ru');

        [$foreign] = $this->scaffold($theirs);

        $this->actingOnPortal($mine)
            ->get(route('app.boards.show', $foreign->id))
            ->assertNotFound();
    }

    public function test_чужую_карточку_нельзя_переместить(): void
    {
        $mine = $this->portal('member-1', 'mine.bitrix24.ru');
        $theirs = $this->portal('member-2', 'theirs.bitrix24.ru');

        [$foreignBoard] = $this->scaffold($theirs);
        $foreignCard = $this->card($theirs, $foreignBoard, 'Чужая задача');

        $target = PortalContext::run($theirs, fn () => $foreignBoard->columns()->first());

        $this->actingOnPortal($mine)
            ->patch(route('app.cards.move', $foreignCard->id), [
                'column_id' => $target->id,
                'position' => 0,
            ])
            ->assertNotFound();
    }

    public function test_перемещение_карточки_меняет_колонку(): void
    {
        $portal = $this->portal('member-1', 'mine.bitrix24.ru');
        [$board] = $this->scaffold($portal);

        $to = PortalContext::run($portal, fn () => $board->columns()->create([
            'portal_id' => $portal->id,
            'name' => 'Готово',
            'position' => 1,
        ]));

        $card = $this->card($portal, $board, 'Задача');

        $this->actingOnPortal($portal)
            ->patch(route('app.cards.move', $card->id), ['column_id' => $to->id, 'position' => 0])
            ->assertRedirect();

        $this->assertSame($to->id, $card->fresh()->board_column_id);
    }
}
