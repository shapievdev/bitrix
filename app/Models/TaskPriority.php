<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\TaskPriorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Уровень приоритета задачи.
 *
 * Штатных в Битриксе всего три, и на практике их не хватает. Свои
 * уровни живут здесь, а связь с PRIORITY нужна, чтобы приоритет был
 * виден и в самом портале.
 *
 * @property string $name
 * @property int $weight
 * @property ?int $bitrix_priority
 */
class TaskPriority extends Model
{
    use BelongsToPortal;

    /** @use HasFactory<TaskPriorityFactory> */
    use HasFactory;

    protected $fillable = [
        'portal_id',
        'name',
        'color',
        'weight',
        'bitrix_priority',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'weight' => 'integer',
            'bitrix_priority' => 'integer',
        ];
    }

    /** @return HasMany<TaskCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(TaskCard::class);
    }

    /**
     * Набор по умолчанию для нового портала.
     *
     * Первые три зеркалят штатные, «Критический» — тот самый уровень,
     * которого в Битриксе нет и ради которого всё затевается.
     *
     * @return array<int, array{name: string, color: string, weight: int, bitrix: ?int, default: bool}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Низкий', 'color' => '#94a3b8', 'weight' => 10, 'bitrix' => 0, 'default' => false],
            ['name' => 'Обычный', 'color' => '#3b82f6', 'weight' => 20, 'bitrix' => 1, 'default' => true],
            ['name' => 'Высокий', 'color' => '#f59e0b', 'weight' => 30, 'bitrix' => 2, 'default' => false],
            ['name' => 'Критический', 'color' => '#ef4444', 'weight' => 40, 'bitrix' => 2, 'default' => false],
        ];
    }
}
