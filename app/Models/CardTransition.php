<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\CardTransitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Перемещение карточки между колонками.
 *
 * Основа отчётов: сколько задача провела на каждом этапе и где копится
 * очередь. Штатный Битрикс такой истории по своим статусам не даёт.
 *
 * @property ?int $seconds_in_previous
 */
class CardTransition extends Model
{
    use BelongsToPortal;

    /** @use HasFactory<CardTransitionFactory> */
    use HasFactory;

    /** Запись неизменяемая — updated_at не нужен. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'portal_id',
        'task_card_id',
        'from_column_id',
        'to_column_id',
        'moved_by',
        'seconds_in_previous',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'seconds_in_previous' => 'integer',
        ];
    }

    /** @return BelongsTo<TaskCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(TaskCard::class, 'task_card_id');
    }

    /** @return BelongsTo<BoardColumn, $this> */
    public function fromColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'from_column_id');
    }

    /** @return BelongsTo<BoardColumn, $this> */
    public function toColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'to_column_id');
    }

    /** @return BelongsTo<PortalUser, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'moved_by');
    }
}
