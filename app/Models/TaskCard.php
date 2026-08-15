<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\TaskCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'bitrix_task_id',
        'position',
        'title',
        'responsible_id',
        'creator_id',
        'bitrix_status',
        'priority',
        'deadline',
        'closed_at',
        'fields',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'deadline' => 'datetime',
            'closed_at' => 'datetime',
            'synced_at' => 'datetime',
            'bitrix_status' => 'integer',
            'priority' => 'integer',
            'position' => 'integer',
        ];
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
     * Нужно для «времени в колонке»: если переходов ещё не было, точкой
     * отсчёта служит появление карточки на доске.
     */
    public function enteredColumnAt(): ?Carbon
    {
        return $this->transitions->first()?->created_at ?? $this->created_at;
    }
}
