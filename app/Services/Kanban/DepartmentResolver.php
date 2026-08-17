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
 * Определение отделов задачи по её участникам.
 *
 * Задача принадлежит отделу исполнителя и отделам всех соисполнителей —
 * то есть сразу нескольким. Приписывать её только исполнителю значит
 * спрятать её от тех, кто в ней реально участвует.
 */
class DepartmentResolver
{
    /** @var Collection<int, Department>|null Отдел Битрикса => наш узел */
    protected ?Collection $byBitrixId = null;

    /** @var array<int, PortalUser> Кэш сотрудников по их ID в Битриксе */
    protected array $users = [];

    /**
     * Подготовить сопоставление и подтянуть недостающих сотрудников.
     *
     * @param  array<int>  $bitrixUserIds  Все исполнители и соисполнители обхода
     */
    public function warmUp(Portal $portal, array $bitrixUserIds): void
    {
        $this->byBitrixId = Department::query()
            ->whereNotNull('bitrix_department_id')
            ->get()
            ->keyBy('bitrix_department_id');

        $ids = array_values(array_unique(array_filter($bitrixUserIds)));

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

        $missing = array_values(array_filter(
            $ids,
            fn (int $id) => ! isset($known[$id]) || $known[$id]->bitrix_department_ids === null,
        ));

        if ($missing !== []) {
            $this->fetchUsers($portal, $missing);
        }
    }

    /**
     * Отделы задачи с указанием, кем сотрудник в ней является.
     *
     * @param  array<int>  $accompliceIds
     * @return array<int, string> id отдела => источник (responsible|accomplice)
     */
    public function forTask(?int $responsibleId, array $accompliceIds = []): array
    {
        $result = [];

        foreach ($this->departmentsOf($responsibleId) as $id) {
            $result[$id] = 'responsible';
        }

        foreach ($accompliceIds as $userId) {
            foreach ($this->departmentsOf((int) $userId) as $id) {
                // Исполнитель важнее: если сотрудник и исполнитель, и
                // соисполнитель в одном отделе, роль не понижаем.
                $result[$id] ??= 'accomplice';
            }
        }

        return $result;
    }

    /**
     * Основной отдел задачи — отдел исполнителя.
     */
    public function primaryFor(?int $responsibleId): ?int
    {
        return $this->departmentsOf($responsibleId)[0] ?? null;
    }

    /**
     * Наши узлы, соответствующие отделам сотрудника.
     *
     * @return array<int>
     */
    protected function departmentsOf(?int $bitrixUserId): array
    {
        if ($this->byBitrixId === null || ! $bitrixUserId) {
            return [];
        }

        $user = $this->users[$bitrixUserId] ?? null;
        $ids = [];

        foreach ($user?->bitrix_department_ids ?? [] as $departmentId) {
            if ($match = $this->byBitrixId->get((int) $departmentId)) {
                $ids[] = $match->id;
            }
        }

        return array_values(array_unique($ids));
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
            // Без отделов доска соберётся, просто задачи не разложатся.
            // Ронять синхронизацию из-за этого нельзя.
            Log::warning('Канбан: не удалось получить отделы сотрудников', [
                'portal' => $portal->domain,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($response as $result) {
            $profile = is_array($result) ? ($result[0] ?? null) : null;

            if (! is_array($profile) || empty($profile['ID'])) {
                continue;
            }

            $bitrixId = (int) $profile['ID'];

            $this->users[$bitrixId] = PortalUser::query()->updateOrCreate(
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
        }
    }
}
