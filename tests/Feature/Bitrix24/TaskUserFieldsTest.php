<?php

namespace Tests\Feature\Bitrix24;

use App\Models\Board;
use App\Models\Department;
use App\Models\Portal;
use App\Models\TaskCard;
use App\Models\TaskPriority;
use App\Services\Bitrix24\TaskUserFields;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Запись подразделения и приоритета в поля самой задачи.
 *
 * Это единственный способ показать наши значения в штатном интерфейсе
 * портала: вмешаться в отрисовку родного канбана API не позволяет.
 */
class TaskUserFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected Portal $portal;

    protected Board $board;

    /** @var array<int, array<string, string>> Поля, уже заведённые на портале */
    protected array $existingFields = [];

    /** Портал отвечает на batch ошибкой. */
    protected bool $batchFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bitrix24.throttle.enabled' => false]);

        Http::fake([
            '*/rest/task.item.userfield.getlist*' => fn () => Http::response(['result' => $this->existingFields]),
            '*/rest/task.item.userfield.add*' => Http::response(['result' => 1]),
            '*/rest/batch*' => fn () => $this->batchFails
                ? Http::response(['error' => 'QUERY_LIMIT_EXCEEDED'], 503)
                : Http::response(['result' => ['result' => [], 'result_error' => []]]),
            '*' => Http::response(['result' => true]),
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

        $this->board = Board::create(['portal_id' => $this->portal->id, 'name' => 'Доска']);
        $this->board->columns()->create([
            'portal_id' => $this->portal->id,
            'name' => 'В работе',
            'position' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        PortalContext::clear();

        parent::tearDown();
    }

    protected function card(?Department $department = null, ?TaskPriority $priority = null): TaskCard
    {
        $card = $this->board->cards()->create([
            'portal_id' => $this->portal->id,
            'board_column_id' => $this->board->columns->first()->id,
            'department_id' => $department?->id,
            'task_priority_id' => $priority?->id,
            'bitrix_task_id' => 777,
            'title' => 'Задача',
            'position' => 0,
        ]);

        if ($department) {
            $card->departments()->attach($department->id, [
                'portal_id' => $this->portal->id,
                'source' => 'responsible',
            ]);
        }

        return $card->fresh();
    }

    public function test_поля_заводятся_на_портале_и_запоминаются(): void
    {
        $codes = app(TaskUserFields::class)->ensure($this->portal);

        $this->assertEquals(
            ['department' => 'UF_TASKPLUS_DEPT', 'priority' => 'UF_TASKPLUS_PRIO'],
            $codes,
        );
        $this->assertEquals($codes, $this->portal->fresh()->task_user_fields);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'task.item.userfield.add'));
    }

    public function test_существующие_поля_повторно_не_создаются(): void
    {
        $this->existingFields = [
            ['FIELD_NAME' => 'UF_TASKPLUS_DEPT', 'USER_TYPE_ID' => 'string'],
            ['FIELD_NAME' => 'UF_TASKPLUS_PRIO', 'USER_TYPE_ID' => 'string'],
        ];

        app(TaskUserFields::class)->ensure($this->portal);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'task.item.userfield.add'));
    }

    public function test_коды_полей_берутся_из_базы_без_обращения_к_порталу(): void
    {
        $this->portal->forceFill(['task_user_fields' => ['department' => 'UF_A', 'priority' => 'UF_B']])->save();

        $codes = app(TaskUserFields::class)->ensure($this->portal);

        $this->assertEquals(['department' => 'UF_A', 'priority' => 'UF_B'], $codes);
        Http::assertNothingSent();
    }

    public function test_значения_уходят_в_задачу(): void
    {
        $department = Department::create([
            'portal_id' => $this->portal->id, 'name' => 'Коммерческий отдел', 'position' => 0,
        ]);
        $priority = TaskPriority::create([
            'portal_id' => $this->portal->id, 'name' => 'Критический', 'weight' => 40,
        ]);

        $card = $this->card($department, $priority);

        $pushed = app(TaskUserFields::class)->push($this->portal, [$card]);

        $this->assertSame(1, $pushed);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/rest/batch')) {
                return false;
            }

            $cmd = implode(' ', $request['cmd'] ?? []);

            return str_contains($cmd, 'UF_TASKPLUS_DEPT')
                && str_contains(urldecode($cmd), 'Коммерческий отдел')
                && str_contains(urldecode($cmd), 'Критический');
        });
    }

    public function test_неизменившиеся_карточки_повторно_не_отправляются(): void
    {
        $department = Department::create([
            'portal_id' => $this->portal->id, 'name' => 'Отдел', 'position' => 0,
        ]);
        $card = $this->card($department);

        $fields = app(TaskUserFields::class);

        $this->assertSame(1, $fields->push($this->portal, [$card]));
        // Второй проход не должен слать ничего: 276 задач иначе означали бы
        // 276 лишних вызовов REST при каждой синхронизации.
        $this->assertSame(0, $fields->push($this->portal, [$card->fresh()]));
    }

    public function test_смена_подразделения_снова_отправляет_значения(): void
    {
        $first = Department::create(['portal_id' => $this->portal->id, 'name' => 'Первый', 'position' => 0]);
        $second = Department::create(['portal_id' => $this->portal->id, 'name' => 'Второй', 'position' => 1]);

        $card = $this->card($first);
        $fields = app(TaskUserFields::class);
        $fields->push($this->portal, [$card]);

        $card->departments()->sync([
            $second->id => ['portal_id' => $this->portal->id, 'source' => 'responsible'],
        ]);

        $this->assertSame(1, $fields->push($this->portal, [$card->fresh()]));
    }

    public function test_сбой_портала_не_помечает_значения_отправленными(): void
    {
        $department = Department::create(['portal_id' => $this->portal->id, 'name' => 'Отдел', 'position' => 0]);
        $card = $this->card($department);

        $this->batchFails = true;

        $this->assertSame(0, app(TaskUserFields::class)->push($this->portal, [$card]));

        // Иначе значения молча разъедутся и больше никогда не выровняются.
        $this->assertNull($card->fresh()->pushed_user_fields);
    }
}
