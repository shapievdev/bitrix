<?php

namespace App\Services\Bitrix24;

use App\Facades\Bitrix24;
use App\Models\Portal;
use App\Models\TaskCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Пользовательские поля задач на портале.
 *
 * Своя доска показывает подразделение и приоритет только у нас. Чтобы те
 * же значения были видны в штатном интерфейсе Битрикс24 — в карточке
 * задачи, в фильтре списка и в роботах — их надо записать в поля самой
 * задачи. Это единственный способ: вмешаться в отрисовку штатного
 * канбана API не позволяет.
 */
class TaskUserFields
{
    /**
     * Поля, которые заводим на портале.
     *
     * @var array<string, array{name: string, label: string}>
     */
    protected const FIELDS = [
        'department' => ['name' => 'UF_TASKPLUS_DEPT', 'label' => 'Подразделение'],
        'priority' => ['name' => 'UF_TASKPLUS_PRIO', 'label' => 'Приоритет+'],
    ];

    /**
     * Коды полей на портале, создавая их при необходимости.
     *
     * @return array<string, string> ключ => FIELD_NAME
     */
    public function ensure(Portal $portal): array
    {
        if ($portal->task_user_fields) {
            return $portal->task_user_fields;
        }

        $client = Bitrix24::forPortal($portal);
        $existing = collect($client->call('task.item.userfield.getlist') ?: [])
            ->keyBy(fn ($field) => $field['FIELD_NAME'] ?? '');

        $codes = [];

        foreach (self::FIELDS as $key => $field) {
            if ($existing->has($field['name'])) {
                $codes[$key] = $field['name'];

                continue;
            }

            try {
                $client->call('task.item.userfield.add', [
                    [
                        'FIELD_NAME' => $field['name'],
                        'USER_TYPE_ID' => 'string',
                        'XML_ID' => $field['name'],
                        'EDIT_FORM_LABEL' => ['ru' => $field['label']],
                        'LIST_COLUMN_LABEL' => ['ru' => $field['label']],
                        'LIST_FILTER_LABEL' => ['ru' => $field['label']],
                    ],
                ]);

                $codes[$key] = $field['name'];
            } catch (Exceptions\Bitrix24Exception $e) {
                Log::warning('Битрикс24: не удалось создать поле задачи', [
                    'portal' => $portal->domain,
                    'field' => $field['name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($codes !== []) {
            $portal->forceFill(['task_user_fields' => $codes])->save();
        }

        return $codes;
    }

    /**
     * Значения, которые должны стоять у задачи.
     *
     * @return array<string, string>
     */
    public function valuesFor(TaskCard $card): array
    {
        $card->loadMissing('departments', 'priorityLevel');

        // Перечисляем все отделы задачи, а не только отдел исполнителя:
        // в штатном фильтре сотрудник должен находить и те задачи, где он
        // соисполнитель, иначе половина работы отдела не видна.
        $values = [
            'department' => $card->departments->pluck('name')->sort()->implode(', '),
            'priority' => $card->priorityLevel?->name ?? '',
        ];

        // Сортируем ключи: jsonb в Postgres хранит их в своём порядке, и
        // прочитанное из базы значение почти никогда не совпадёт с только
        // что собранным при строгом сравнении. Без этого «не изменилось»
        // не срабатывало бы никогда, и каждая синхронизация переписывала
        // бы все задачи подряд.
        ksort($values);

        return $values;
    }

    /**
     * Записать значения в задачи на портале.
     *
     * Пишем только те карточки, у которых значения разошлись с ранее
     * отправленными: иначе каждая синхронизация превращалась бы в сотни
     * вызовов REST на данные, которые не менялись.
     *
     * @param  iterable<TaskCard>  $cards
     * @return int Сколько задач обновлено.
     */
    public function push(Portal $portal, iterable $cards): int
    {
        $codes = $this->ensure($portal);

        if ($codes === []) {
            return 0;
        }

        $stale = Collection::make($cards)->filter(function (TaskCard $card) {
            $sent = $card->pushed_user_fields;

            if ($sent === null) {
                return true;
            }

            ksort($sent);

            return $this->valuesFor($card) !== $sent;
        });

        if ($stale->isEmpty()) {
            return 0;
        }

        $commands = [];

        foreach ($stale as $card) {
            $values = $this->valuesFor($card);
            $fields = [];

            foreach ($codes as $key => $code) {
                $fields[$code] = $values[$key] ?? '';
            }

            $commands["t{$card->id}"] = ['tasks.task.update', [
                'taskId' => $card->bitrix_task_id,
                'fields' => $fields,
            ]];
        }

        try {
            Bitrix24::forPortal($portal)->batch($commands);
        } catch (Exceptions\Bitrix24Exception $e) {
            Log::warning('Битрикс24: не удалось записать поля задач', [
                'portal' => $portal->domain,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        // Помечаем отправленным только после успешного вызова — иначе при
        // сбое значения молча разъедутся и больше не выровняются.
        foreach ($stale as $card) {
            $card->forceFill(['pushed_user_fields' => $this->valuesFor($card)])->save();
        }

        return $stale->count();
    }

    /**
     * Записать значения одной задачи — после ручной правки на доске.
     */
    public function pushOne(TaskCard $card): void
    {
        $this->push($card->portal, [$card]);
    }
}
