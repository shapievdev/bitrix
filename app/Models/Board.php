<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\BoardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Канбан-доска: набор колонок и попавшие на неё задачи портала.
 *
 * @property string $name
 * @property ?int $bitrix_group_id
 * @property ?array $filter
 */
class Board extends Model
{
    use BelongsToPortal;

    /** @use HasFactory<BoardFactory> */
    use HasFactory;

    protected $fillable = [
        'portal_id',
        'name',
        'description',
        'bitrix_group_id',
        'filter',
        'created_by',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /** @return HasMany<BoardColumn, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    /** @return HasMany<TaskCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(TaskCard::class);
    }

    /** @return BelongsTo<PortalUser, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class, 'created_by');
    }

    /** @return HasManyThrough<CardTransition, TaskCard, $this> */
    public function transitions(): HasManyThrough
    {
        return $this->hasManyThrough(CardTransition::class, TaskCard::class);
    }

    /**
     * Колонка, в которую попадают новые задачи.
     *
     * Если признак нигде не проставлен, берём первую — доска без входной
     * колонки бесполезна, а падать при синхронизации из-за настройки нельзя.
     */
    public function defaultColumn(): ?BoardColumn
    {
        return $this->columns->firstWhere('is_default', true)
            ?? $this->columns->first();
    }

    /**
     * Фильтр задач для tasks.task.list.
     *
     * К заданному вручную добавляется группа, если доска привязана к проекту.
     */
    public function taskFilter(): array
    {
        $filter = $this->filter ?? [];

        if ($this->bitrix_group_id) {
            $filter['GROUP_ID'] = $this->bitrix_group_id;
        }

        return $filter;
    }
}
