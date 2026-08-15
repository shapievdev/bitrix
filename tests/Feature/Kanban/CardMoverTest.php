<?php

namespace Tests\Feature\Kanban;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\CardTransition;
use App\Models\Portal;
use App\Models\TaskCard;
use App\Services\Kanban\CardMover;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CardMoverTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    protected CardMover $mover;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);
        Http::fake(['*' => Http::response(['result' => true])]);

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

        $this->board = Board::create([
            'portal_id' => $this->portal->id,
            'name' => 'Разработка',
        ]);

        $this->mover = app(CardMover::class);
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function column(string $name, int $position, ?int $status = null): BoardColumn
    {
        return $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => $name,
            'position' => $position,
            'bitrix_status' => $status,
        ]);
    }

    protected function card(BoardColumn $column, string $title, int $position, int $taskId): TaskCard
    {
        return $this->board->cards()->create([
            'portal_id' => $this->portal->id,
            'board_column_id' => $column->id,
            'bitrix_task_id' => $taskId,
            'title' => $title,
            'position' => $position,
        ]);
    }

    /**
     * @return array<string, int> Заголовок карточки => позиция.
     */
    protected function layout(BoardColumn $column): array
    {
        return TaskCard::query()
            ->where('board_column_id', $column->id)
            ->orderBy('position')
            ->pluck('position', 'title')
            ->all();
    }

    public function test_перенос_в_другую_колонку_закрывает_дыру_в_прежней(): void
    {
        $todo = $this->column('Надо', 0);
        $doing = $this->column('Делаем', 1);

        $first = $this->card($todo, 'Первая', 0, 1);
        $this->card($todo, 'Вторая', 1, 2);
        $this->card($todo, 'Третья', 2, 3);

        $this->mover->move($first, $doing);

        // После ухода первой оставшиеся должны сомкнуться в 0 и 1,
        // иначе следующая вставка получит конфликт позиций.
        $this->assertSame(['Вторая' => 0, 'Третья' => 1], $this->layout($todo));
        $this->assertSame(['Первая' => 0], $this->layout($doing));
    }

    public function test_вставка_в_середину_раздвигает_остальные(): void
    {
        $todo = $this->column('Надо', 0);
        $doing = $this->column('Делаем', 1);

        $this->card($doing, 'Альфа', 0, 1);
        $this->card($doing, 'Бета', 1, 2);
        $moved = $this->card($todo, 'Гамма', 0, 3);

        $this->mover->move($moved, $doing, position: 1);

        $this->assertSame(
            ['Альфа' => 0, 'Гамма' => 1, 'Бета' => 2],
            $this->layout($doing),
        );
    }

    public function test_позиция_за_пределами_колонки_прижимается_к_концу(): void
    {
        $todo = $this->column('Надо', 0);
        $doing = $this->column('Делаем', 1);

        $this->card($doing, 'Альфа', 0, 1);
        $moved = $this->card($todo, 'Бета', 0, 2);

        $this->mover->move($moved, $doing, position: 99);

        $this->assertSame(['Альфа' => 0, 'Бета' => 1], $this->layout($doing));
    }

    public function test_переход_между_колонками_пишется_в_историю(): void
    {
        $todo = $this->column('Надо', 0);
        $doing = $this->column('Делаем', 1);

        $card = $this->card($todo, 'Первая', 0, 1);
        $card->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->mover->move($card, $doing);

        $transition = CardTransition::query()->firstOrFail();

        $this->assertSame($todo->id, $transition->from_column_id);
        $this->assertSame($doing->id, $transition->to_column_id);
        // Полчаса в предыдущей колонке — основа отчёта о времени этапа.
        $this->assertEqualsWithDelta(1800, $transition->seconds_in_previous, 5);
    }

    public function test_сортировка_внутри_колонки_историю_не_пишет(): void
    {
        $todo = $this->column('Надо', 0);

        $this->card($todo, 'Альфа', 0, 1);
        $second = $this->card($todo, 'Бета', 1, 2);

        $this->mover->move($second, $todo, position: 0);

        $this->assertSame(['Бета' => 0, 'Альфа' => 1], $this->layout($todo));
        // Иначе время на этапе обнулялось бы при любом перетаскивании.
        $this->assertSame(0, CardTransition::count());
    }

    public function test_перенос_отправляет_новый_статус_в_битрикс(): void
    {
        $todo = $this->column('Надо', 0, status: 2);
        $done = $this->column('Готово', 1, status: 5);

        $card = $this->card($todo, 'Первая', 0, 777);
        $card->forceFill(['bitrix_status' => 2])->save();

        $this->mover->move($card, $done);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tasks.task.update')
                && $request['taskId'] === 777
                && $request['fields']['STATUS'] === 5;
        });

        $this->assertSame(5, $card->fresh()->bitrix_status);
    }

    public function test_колонка_без_статуса_ничего_в_битрикс_не_шлёт(): void
    {
        $todo = $this->column('Надо', 0, status: 2);
        $review = $this->column('Ревью', 1); // своя колонка, статуса нет

        $card = $this->card($todo, 'Первая', 0, 777);

        $this->mover->move($card, $review);

        Http::assertNothingSent();
    }

    public function test_статус_из_битрикса_двигает_карточку(): void
    {
        $todo = $this->column('Надо', 0, status: 2);
        $done = $this->column('Готово', 1, status: 5);

        $card = $this->card($todo, 'Первая', 0, 777);

        $this->mover->syncFromStatus($card, 5);

        $this->assertSame($done->id, $card->fresh()->board_column_id);
        // Статус пришёл от портала — отправлять его обратно нельзя,
        // иначе события зациклятся.
        Http::assertNothingSent();
    }

    public function test_статус_совпадающий_с_текущей_колонкой_ничего_не_двигает(): void
    {
        $todo = $this->column('Надо', 0, status: 2);
        $this->column('Тоже ждёт', 1, status: 2);

        $card = $this->card($todo, 'Первая', 0, 777);

        $this->mover->syncFromStatus($card, 2);

        $this->assertSame($todo->id, $card->fresh()->board_column_id);
        $this->assertSame(0, CardTransition::count());
    }

    public function test_нельзя_перенести_в_колонку_чужой_доски(): void
    {
        $todo = $this->column('Надо', 0);

        $other = Board::create(['portal_id' => $this->portal->id, 'name' => 'Другая']);
        $foreign = $other->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'Чужая',
            'position' => 0,
        ]);

        $card = $this->card($todo, 'Первая', 0, 1);

        $this->expectException(\InvalidArgumentException::class);

        $this->mover->move($card, $foreign);
    }
}
