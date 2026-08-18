<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Узел оргструктуры: департамент или отдел внутри него.
 *
 * Одна таблица на оба уровня, потому что в портале это одно дерево, а
 * глубина вложенности не ограничена: у «Корпоративного отдела» есть свои
 * подотделы. Верхний уровень навигации помечен признаком is_primary — в
 * дереве портала эти узлы лежат на разной глубине, и вычислить их по
 * родителю невозможно.
 *
 * @property string $name
 * @property ?int $parent_id
 * @property ?int $bitrix_department_id
 * @property bool $is_primary
 */
class Department extends Model
{
    use BelongsToPortal;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected $fillable = [
        'portal_id',
        'parent_id',
        'name',
        'color',
        'position',
        'bitrix_department_id',
        'bitrix_parent_id',
        'head_id',
        'is_default',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_primary' => 'boolean',
            'position' => 'integer',
            'bitrix_department_id' => 'integer',
            'bitrix_parent_id' => 'integer',
            'head_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /** @return HasMany<Department, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id')->orderBy('name');
    }

    /**
     * Задачи отдела: и там, где сотрудник исполнитель, и там, где он
     * соисполнитель.
     *
     * @return BelongsToMany<TaskCard, $this>
     */
    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(TaskCard::class, 'department_task_card')
            ->withPivot('source')
            ->withTimestamps();
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * Идентификаторы этого узла и всех вложенных.
     *
     * Фильтр по департаменту обязан включать его отделы: выбрав
     * «Коммерческий департамент», руководитель ждёт увидеть и маркетинг,
     * и дистрибуцию, а не пустой экран.
     *
     * @param  Collection<int, Department>  $all  Все узлы портала, чтобы не ходить в базу рекурсивно
     * @return array<int>
     */
    public function subtreeIds(Collection $all): array
    {
        $ids = [$this->id];
        $queue = [$this->id];

        $byParent = $all->groupBy('parent_id');

        while ($queue !== []) {
            $current = array_pop($queue);

            foreach ($byParent->get($current, []) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
