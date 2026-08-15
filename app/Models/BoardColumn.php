<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\BoardColumnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Колонка доски — собственный статус задачи.
 *
 * @property string $name
 * @property int $position
 * @property ?int $bitrix_status
 * @property bool $is_default
 * @property bool $is_final
 * @property int $wip_limit
 */
class BoardColumn extends Model
{
    use BelongsToPortal;

    /** @use HasFactory<BoardColumnFactory> */
    use HasFactory;

    protected $fillable = [
        'portal_id',
        'board_id',
        'name',
        'color',
        'position',
        'bitrix_status',
        'is_default',
        'is_final',
        'wip_limit',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_final' => 'boolean',
            'bitrix_status' => 'integer',
            'position' => 'integer',
            'wip_limit' => 'integer',
        ];
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return HasMany<TaskCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(TaskCard::class)->orderBy('position');
    }

    /**
     * Достигнут ли предел незавершённой работы.
     *
     * Предупреждение, а не запрет: жёсткая блокировка перемещения бесит
     * сильнее, чем помогает, и её обходят созданием задач мимо доски.
     */
    public function isOverWipLimit(?int $cardCount = null): bool
    {
        if ($this->wip_limit === 0) {
            return false;
        }

        return ($cardCount ?? $this->cards()->count()) > $this->wip_limit;
    }
}
