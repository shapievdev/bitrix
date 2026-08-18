<?php

namespace App\Services\Kanban;

use App\Models\Department;
use App\Models\PortalUser;
use App\Models\TaskCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Кто какие задачи видит на доске.
 *
 * Три уровня, от широкого к узкому:
 *
 *  - администратор портала видит всё;
 *  - руководитель подразделения — задачи своего подразделения и всех
 *    вложенных в него;
 *  - остальные — только те задачи, где они сами участвуют в любой роли.
 *
 * Собрано в одном классе намеренно: правило видимости обязано быть
 * одинаковым в канбане, в счётчиках слева, в списке исполнителей и при
 * открытии карточки. Разъехавшись, оно превращается в утечку — задача не
 * видна в списке, но открывается по прямой ссылке.
 */
class TaskVisibility
{
    /**
     * Видит ли сотрудник вообще все задачи портала.
     */
    public function isUnrestricted(?PortalUser $user): bool
    {
        return (bool) $user?->is_admin;
    }

    /**
     * Ограничить выборку задачами, доступными сотруднику.
     *
     * @param  Builder<TaskCard>  $query
     * @return Builder<TaskCard>
     */
    public function apply(Builder $query, ?PortalUser $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return $query;
        }

        // Без пользователя не показываем ничего: это либо фоновая задача,
        // забравшая доску мимо контекста, либо ошибка в мидлваре. Пустая
        // доска здесь безопаснее, чем чужие задачи.
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $departmentIds = $this->headDepartmentIds($user);

        return $query->where(function (Builder $inner) use ($user, $departmentIds) {
            $inner->participatedBy($user->bitrix_user_id);

            if ($departmentIds !== []) {
                $inner->orWhereHas(
                    'departments',
                    fn (Builder $q) => $q->whereIn('departments.id', $departmentIds),
                );
            }
        });
    }

    /**
     * Проверить доступ к одной карточке.
     *
     * Отдельный метод, а не выборка на одну запись: при открытии задачи
     * карточка уже загружена, и второй запрос в базу ради проверки лишний.
     */
    public function allows(?PortalUser $user, TaskCard $card): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        if (! $user) {
            return false;
        }

        $id = $user->bitrix_user_id;

        $participates = $card->responsible_id === $id
            || $card->creator_id === $id
            || in_array($id, array_map('intval', $card->accomplice_ids ?? []), true)
            || in_array($id, array_map('intval', $card->auditor_ids ?? []), true);

        if ($participates) {
            return true;
        }

        $departmentIds = $this->headDepartmentIds($user);

        if ($departmentIds === []) {
            return false;
        }

        $card->loadMissing('departments');

        return $card->departments->pluck('id')->intersect($departmentIds)->isNotEmpty();
    }

    /**
     * Подразделения, которыми сотрудник руководит, вместе с вложенными.
     *
     * Руководитель департамента отвечает и за отделы внутри него, поэтому
     * берём всё поддерево, а не только сам узел.
     *
     * @return array<int>
     */
    public function headDepartmentIds(?PortalUser $user): array
    {
        if (! $user) {
            return [];
        }

        /** @var Collection<int, Department> $all */
        $all = Department::query()->get();

        $own = $all->where('head_id', $user->bitrix_user_id);

        if ($own->isEmpty()) {
            return [];
        }

        return $own
            ->flatMap(fn (Department $department) => $department->subtreeIds($all))
            ->unique()
            ->values()
            ->all();
    }
}