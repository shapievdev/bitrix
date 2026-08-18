<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\TaskCardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Задача портала на доске.
 *
 * Поля задачи здесь — копия для отрисовки, источник истины остаётся в
 * Битриксе. Наше собственное — только колонка и порядок внутри неё.
 *
 * @property int $bitrix_task_id
 * @property int $position
 * @property string $title
 * @property ?int $bitrix_status
 */
class TaskCard extends Model
{
    use BelongsToPortal;

    /** @use HasFactory<TaskCardFactory> */
    use HasFactory;

    protected $fillable = [
        'portal_id',
        'board_id',
        'board_column_id',
        'department_id',
        'department_locked',
        'task_priority_id',
        'bitrix_task_id',
        'position',
        'title',
        'title_normalized',
        'responsible_id',
        'accomplice_ids',
        'auditor_ids',
        'creator_id',
        'bitrix_status',
        'priority',
        'deadline',
        'closed_at',
        'entered_column_at',
        'fields',
        'pushed_user_fields',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'accomplice_ids' => 'array',
            'auditor_ids' => 'array',
            'pushed_user_fields' => 'array',
            'deadline' => 'datetime',
            'closed_at' => 'datetime',
            'entered_column_at' => 'datetime',
            'synced_at' => 'datetime',
            'bitrix_status' => 'integer',
            'priority' => 'integer',
            'position' => 'integer',
            'department_locked' => 'boolean',
        ];
    }

    /**
     * Основной отдел — тот, где работает исполнитель.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Все отделы задачи: исполнителя и всех соисполнителей.
     *
     * Одна задача часто идёт через несколько отделов, и приписывать её
     * только исполнителю — значит спрятать её от тех, кто в ней реально
     * участвует.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_task_card')
            ->withPivot('source')
            ->withTimestamps();
    }

    /** @return BelongsTo<TaskPriority, $this> */
    public function priorityLevel(): BelongsTo
    {
        return $this->belongsTo(TaskPriority::class, 'task_priority_id');
    }

    protected static function booted(): void
    {
        // Нормализованную копию держим в актуальном состоянии сами:
        // складывать регистр кириллицы средствами базы нельзя, её локаль
        // может быть какой угодно.
        static::saving(function (TaskCard $card) {
            if ($card->isDirty('title')) {
                $card->title_normalized = mb_strtolower((string) $card->title);
            }
        });
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsTo<BoardColumn, $this> */
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    /** @return HasMany<CardTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(CardTransition::class)->latest('created_at');
    }

    /**
     * Срок вышел, а задача не закрыта.
     */
    public function isOverdue(): bool
    {
        return $this->deadline !== null
            && $this->closed_at === null
            && $this->deadline->isPast();
    }

    /**
     * Когда карточка попала в текущую колонку.
     *
     * Значение хранится в самой карточке: доска показывает время на этапе
     * для каждой из сотен карточек сразу, и поднимать ради одной даты
     * историю переходов на каждую — лишние запросы. История остаётся
     * источником для отчётов, здесь нужен только последний рубеж.
     *
     * Переходов может не быть вовсе — тогда точка отсчёта это появление
     * карточки на доске.
     */
    public function enteredColumnAt(): ?Carbon
    {
        return $this->entered_column_at ?? $this->created_at;
    }

    /**
     * Задачи, в которых сотрудник участвует в любой роли.
     *
     * Исполнитель, соисполнитель, наблюдатель или постановщик: во всех
     * четырёх случаях задача его касается и прятать её нельзя.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeParticipatedBy(Builder $query, int $bitrixUserId): Builder
    {
        return $query->where(function (Builder $inner) use ($bitrixUserId) {
            $inner
                ->where('responsible_id', $bitrixUserId)
                ->orWhere('creator_id', $bitrixUserId)
                ->orWhereJsonContains('accomplice_ids', $bitrixUserId)
                ->orWhereJsonContains('auditor_ids', $bitrixUserId);
        });
    }
}
