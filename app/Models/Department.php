<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPortal;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Подразделение — дорожка на доске.
 *
 * Список свой, но каждое подразделение можно связать с отделом
 * оргструктуры портала: тогда задача попадает в дорожку автоматически,
 * по отделу ответственного.
 *
 * @property string $name
 * @property ?int $bitrix_department_id
 * @property bool $is_default
 */
class Department extends Model
{
    use BelongsToPortal;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected $fillable = [
        'portal_id',
        'name',
        'color',
        'position',
        'bitrix_department_id',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'position' => 'integer',
            'bitrix_department_id' => 'integer',
        ];
    }

    /** @return HasMany<TaskCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(TaskCard::class);
    }
}
