<?php

namespace App\Services\Kanban;

use App\Facades\Bitrix24;
use App\Models\Department;
use App\Models\Portal;
use App\Models\PortalUser;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Определение подразделения задачи по её ответственному.
 *
 * Отдел сотрудника лежит в оргструктуре портала (UF_DEPARTMENT), а наши
 * подразделения связаны с ним через bitrix_department_id. Сопоставление
 * держим в памяти на время синхронизации: доска на пару сотен задач
 * иначе выбьет лимит REST на одних только запросах профилей.
 */
class DepartmentResolver
{
    /** @var Collection<int, Department>|null Отдел Битрикса => наше подразделение */
    protected ?Collection $byBitrixId = null;

    protected ?Department $fallback = null;

    /** @var array<int, PortalUser|null> Кэш сотрудников по их ID в Битриксе */
    protected array $users = [];

    /**
     * Подготовить сопоставление и подтянуть недостающих сотрудников.
     *
     * Вызывается один раз перед обходом задач: все нужные профили
     * забираются пачкой, а не по одному на карточку.
     *
     * @param  array<int>  $responsibleIds
     */
    public function warmUp(Portal $portal, array $responsibleIds): void
    {
        $departments = Department::query()->get();

        $this->byBitrixId = $departments
            ->filter(fn (Department $d) => $d->bitrix_department_id !== null)
            ->keyBy('bitrix_department_id');

        $this->fallback = $departments->firstWhere('is_default', true);

        $ids = array_values(array_unique(array_filter($responsibleIds)));

        if ($ids === []) {
            return;
        }

        $known = PortalUser::query()
            ->where('portal_id', $portal->id)
            ->whereIn('bitrix_user_id', $ids)
            ->get()
            ->keyBy('bitrix_user_id');

        foreach ($known as $bitrixId => $user) {
            $this->users[$bitrixId] = $user;
        }

        // Сотрудники, которых мы ещё не видели, либо у которых отделы не
        // заполнены — их профили нужно забрать у портала.
        $missing = array_values(array_filter(
            $ids,
            fn (int $id) => ! isset($known[$id]) || $known[$id]->bitrix_department_ids === null,
        ));

        if ($missing !== []) {
            $this->fetchUsers($portal, $missing);
        }
    }

    /**
     * Подразделение для задачи с указанным ответственным.
     */
    public function forResponsible(?int $bitrixUserId): ?Department
    {
        if ($this->byBitrixId === null) {
            return null;
        }

        $user = $bitrixUserId ? ($this->users[$bitrixUserId] ?? null) : null;

        foreach ($user?->bitrix_department_ids ?? [] as $departmentId) {
            if ($match = $this->byBitrixId->get((int) $departmentId)) {
                return $match;
            }
        }

        // Отдел не определился — задача уходит в дорожку по умолчанию,
        // а если её нет, останется в «Без подразделения». Прятать такие
        // задачи нельзя: именно они и теряются на практике.
        return $this->fallback;
    }

    /**
     * Забрать профили сотрудников пачкой и запомнить их отделы.
     *
     * @param  array<int>  $bitrixUserIds
     */
    protected function fetchUsers(Portal $portal, array $bitrixUserIds): void
    {
        $commands = [];

        foreach ($bitrixUserIds as $id) {
            $commands["u{$id}"] = ['user.get', ['ID' => $id]];
        }

        try {
            $response = Bitrix24::forPortal($portal)->batch($commands);
        } catch (Bitrix24Exception $e) {
            // Без отделов доска всё равно соберётся — задачи просто уйдут
            // в дорожку по умолчанию. Ронять синхронизацию из-за этого нельзя.
            Log::warning('Канбан: не удалось получить отделы сотрудников', [
                'portal' => $portal->domain,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($response as $key => $result) {
            $profile = is_array($result) ? ($result[0] ?? null) : null;

            if (! is_array($profile) || empty($profile['ID'])) {
                continue;
            }

            $bitrixId = (int) $profile['ID'];

            $user = PortalUser::query()->updateOrCreate(
                ['portal_id' => $portal->id, 'bitrix_user_id' => $bitrixId],
                [
                    'name' => trim(($profile['NAME'] ?? '').' '.($profile['LAST_NAME'] ?? ''))
                        ?: ($profile['EMAIL'] ?? "Сотрудник #{$bitrixId}"),
                    'email' => $profile['EMAIL'] ?? null,
                    'avatar' => $profile['PERSONAL_PHOTO'] ?? null,
                    'position' => $profile['WORK_POSITION'] ?? null,
                    'bitrix_department_ids' => array_map('intval', $profile['UF_DEPARTMENT'] ?? []),
                ],
            );

            $this->users[$bitrixId] = $user;
        }
    }
}
