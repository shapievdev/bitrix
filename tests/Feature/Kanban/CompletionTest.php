<?php

namespace Tests\Feature\Kanban;

use App\Enums\TaskStatus;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Portal;
use App\Models\TaskCard;
use App\Services\Kanban\CardMover;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Завершение задачи в обе стороны.
 *
 * Перенос в «Готово» должен завершать задачу на портале, а завершение на
 * портале — уводить карточку в «Готово». Иначе доска и портал начинают
 * расходиться в том единственном, что важно всем: закончена работа или нет.
 */
class CompletionTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    protected BoardColumn $inProgress;

    protected BoardColumn $done;

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

        $this->board = Board::create(['portal_id' => $this->portal->id, 'name' => 'Доска']);

        $this->inProgress = $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'В работе',
            'position' => 0,
            'bitrix_status' => TaskStatus::InProgress->value,
        ]);

        $this->done = $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'Готово',
            'position' => 1,
            'bitrix_status' => TaskStatus::Completed->value,
            'is_final' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function card(?BoardColumn $column = null, int $status = TaskStatus::InProgress->value): TaskCard
    {
        return $this->board->cards()->create([
            'portal_id' => $this->portal->id,
            'board_column_id' => ($column ?? $this->inProgress)->id,
            'bitrix_task_id' => 777,
            'title' => 'Задача',
            'position' => 0,
            'bitrix_status' => $status,
        ]);
    }

    public function test_перенос_в_готово_завершает_задачу_на_портале(): void
    {
        $card = $this->card();

        app(CardMover::class)->move($card, $this->done);

        // Именно tasks.task.complete, а не запись STATUS: на завершении
        // портал держит свою логику — снимает с контроля, проставляет дату
        // закрытия и уведомляет постановщика.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tasks.task.complete')
            && $r['taskId'] === 777);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'tasks.task.update'));

        $fresh = $card->fresh();

        $this->assertSame(TaskStatus::Completed->value, $fresh->bitrix_status);
        $this->assertNotNull($fresh->closed_at);
    }

    public function test_вынос_из_готово_возвращает_задачу_в_работу(): void
    {
        $card = $this->card($this->done, TaskStatus::Completed->value);
        $card->forceFill(['closed_at' => now()])->save();

        app(CardMover::class)->move($card, $this->inProgress);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'tasks.task.renew'));
        $this->assertNull($card->fresh()->closed_at);
    }

    public function test_завершение_на_портале_уводит_карточку_в_готово(): void
    {
        $card = $this->card();

        app(CardMover::class)->syncFromStatus($card, TaskStatus::Completed->value, force: true);

        $this->assertSame($this->done->id, $card->fresh()->board_column_id);

        // Статус пришёл с портала — отправлять его обратно нельзя,
        // иначе события зациклятся.
        Http::assertNothingSent();
    }

    public function test_отклонённая_задача_тоже_уходит_в_готово(): void
    {
        $card = $this->card();

        // Колонки под «Отклонена» нет, но работа закончена — карточка
        // обязана оказаться в финальной колонке, а не зависнуть в работе.
        app(CardMover::class)->syncFromStatus($card, TaskStatus::Declined->value, force: true);

        $this->assertSame($this->done->id, $card->fresh()->board_column_id);
    }

    public function test_перенос_между_обычными_колонками_шлёт_обычный_статус(): void
    {
        $pending = $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'Ждёт',
            'position' => 2,
            'bitrix_status' => TaskStatus::Pending->value,
        ]);

        app(CardMover::class)->move($this->card(), $pending);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'tasks.task.update')
            && ($r['fields']['STATUS'] ?? null) === TaskStatus::Pending->value);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'tasks.task.complete'));
    }
}
