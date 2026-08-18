<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Models\TaskPriority;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Настройка доски: порядок колонок и числовые поля справочников.
 */
class BoardSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected PortalUser $user;

    protected Board $board;

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

        $this->user = PortalUser::create([
            'portal_id' => $this->portal->id,
            'bitrix_user_id' => 1,
            'name' => 'Администратор',
            'is_admin' => true,
        ]);

        $this->board = Board::create(['portal_id' => $this->portal->id, 'name' => 'Доска']);

        foreach (['Новые', 'В работе', 'Готово'] as $position => $name) {
            $this->board->columns()->create([
                'portal_id' => $this->portal->id,
                'name' => $name,
                'position' => $position,
                'color' => '#94a3b8',
                'wip_limit' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function act(): TestResponse|static
    {
        return $this->withSession([
            'bitrix.portal_id' => $this->portal->id,
            'bitrix.user_id' => $this->user->id,
        ]);
    }

    /**
     * Запрос сбрасывает контекст портала, а без него глобальный скоуп
     * возвращает пустоту — проверки читают базу в контексте явно.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inPortal(callable $callback): mixed
    {
        return PortalContext::run($this->portal, $callback);
    }

    /** @return array<int, string> */
    protected function order(): array
    {
        return $this->inPortal(
            fn () => $this->board->columns()->orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_перетаскивание_меняет_порядок_колонок(): void
    {
        $ids = $this->board->columns()->orderBy('position')->pluck('id')->all();

        // «Готово» переносим в начало.
        $this->act()
            ->patch(route('app.columns.reorder', $this->board->id), [
                'ids' => [$ids[2], $ids[0], $ids[1]],
            ])
            ->assertRedirect();

        $this->assertSame(['Готово', 'Новые', 'В работе'], $this->order());
        $this->assertSame([0, 1, 2], $this->inPortal(
            fn () => $this->board->columns()->orderBy('position')->pluck('position')->all(),
        ));
    }

    public function test_неполный_список_порядок_не_меняет(): void
    {
        $ids = $this->board->columns()->orderBy('position')->pluck('id')->all();

        // Пропущенная колонка оставила бы дыру в позициях.
        $this->act()->patch(route('app.columns.reorder', $this->board->id), [
            'ids' => [$ids[2], $ids[0]],
        ]);

        $this->assertSame(['Новые', 'В работе', 'Готово'], $this->order());
    }

    public function test_чужая_колонка_в_списке_порядок_не_меняет(): void
    {
        $ids = $this->board->columns()->orderBy('position')->pluck('id')->all();

        $other = Board::create(['portal_id' => $this->portal->id, 'name' => 'Другая']);
        $foreign = $other->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'Чужая',
            'position' => 0,
        ]);

        $this->act()->patch(route('app.columns.reorder', $this->board->id), [
            'ids' => [$ids[0], $ids[1], $ids[2], $foreign->id],
        ]);

        $this->assertSame(['Новые', 'В работе', 'Готово'], $this->order());
        $this->assertSame(0, $this->inPortal(fn () => $foreign->fresh()->position));
    }

    public function test_очищенный_лимит_не_роняет_запрос(): void
    {
        $column = $this->board->columns()->first();
        $column->forceFill(['wip_limit' => 10])->save();

        // Очищенное поле приходит пустой строкой и превращается в null.
        // Колонка в базе NOT NULL — раньше здесь была пятисотка.
        $this->act()
            ->patch(route('app.columns.update', $column->id), [
                'name' => $column->name,
                'color' => $column->color,
                'wip_limit' => '',
            ])
            ->assertRedirect();

        $this->assertSame(0, $this->inPortal(fn () => $column->fresh()->wip_limit));
    }

    public function test_очищенный_вес_приоритета_не_роняет_запрос(): void
    {
        $priority = TaskPriority::create([
            'portal_id' => $this->portal->id,
            'name' => 'Срочно',
            'color' => '#ef4444',
            'weight' => 50,
        ]);

        $this->act()
            ->patch(route('app.priorities.update', $priority->id), [
                'name' => 'Срочно',
                'color' => '#ef4444',
                'weight' => '',
            ])
            ->assertRedirect();

        $this->assertSame(0, $this->inPortal(fn () => $priority->fresh()->weight));
    }

    public function test_позиция_колонки_через_общее_обновление_не_принимается(): void
    {
        $column = $this->board->columns()->orderBy('position')->first();

        // Порядок правится только перетаскиванием: случайное поле position
        // в запросе не должно перекраивать доску.
        $this->act()->patch(route('app.columns.update', $column->id), [
            'name' => $column->name,
            'position' => 99,
        ]);

        $this->assertSame(0, $this->inPortal(fn () => $column->fresh()->position));
    }

    public function test_новая_колонка_встаёт_в_конец(): void
    {
        $this->act()->post(route('app.columns.store', $this->board->id), [
            'name' => 'Отложена',
        ]);

        $this->assertSame(['Новые', 'В работе', 'Готово', 'Отложена'], $this->order());
        $this->assertSame(0, $this->inPortal(
            fn () => BoardColumn::query()->where('name', 'Отложена')->first()->wip_limit,
        ));
    }
}
