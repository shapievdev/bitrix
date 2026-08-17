<script setup>
import { router } from '@inertiajs/vue3'
import { syncFrameHeight } from '../../bitrix'

const props = defineProps({
    taskId: { type: Number, required: true },
    card: { type: Object, default: null },
    columns: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
})

// Вкладка живёт во фрейме карточки задачи — высоту надо подгонять
// вручную, своей полосы прокрутки у него нет.
syncFrameHeight()

function save(payload) {
    router.patch(route('app.tasks.update', props.card.id), payload, {
        preserveScroll: true,
        onFinish: () => syncFrameHeight(),
    })
}
</script>

<template>
    <div class="p-4">
        <div v-if="!card" class="rounded-lg border border-dashed border-slate-300 p-6 text-center">
            <p class="text-sm text-slate-500">
                Задача #{{ taskId }} не попала ни на одну доску.
            </p>
            <p class="mt-1 text-xs text-slate-400">
                Проверьте фильтр доски — возможно, задача под него не подходит.
            </p>
        </div>

        <div v-else class="space-y-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400">Доска</p>
                <p class="text-sm font-medium">{{ card.boardName }}</p>
            </div>

            <div>
                <p class="mb-1.5 text-xs uppercase tracking-wide text-slate-400">Этап</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="c in columns"
                        :key="c.id"
                        type="button"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                        :style="c.id === card.columnId
                            ? { backgroundColor: c.color, color: '#fff' }
                            : { backgroundColor: c.color + '22', color: c.color }"
                        @click="save({ column_id: c.id })"
                    >
                        {{ c.name }}
                    </button>
                </div>
            </div>

            <div>
                <p class="mb-1.5 text-xs uppercase tracking-wide text-slate-400">Приоритет</p>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="p in priorities"
                        :key="p.id"
                        type="button"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition"
                        :style="p.id === card.priorityId
                            ? { backgroundColor: p.color, color: '#fff' }
                            : { backgroundColor: p.color + '22', color: p.color }"
                        @click="save({ priority_id: p.id })"
                    >
                        {{ p.name }}
                    </button>
                </div>
            </div>

            <div>
                <p class="mb-1.5 text-xs uppercase tracking-wide text-slate-400">
                    Подразделение
                    <span v-if="card.departmentLocked" class="ml-1 normal-case text-slate-400">
                        · задано вручную
                    </span>
                </p>
                <select
                    class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-slate-500 focus:outline-none"
                    :value="card.departmentId ?? ''"
                    @change="save({ column_id: card.columnId, department_id: $event.target.value || null })"
                >
                    <option value="">Без подразделения</option>
                    <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
                <p class="mt-1 text-xs text-slate-400">
                    По умолчанию подставляется по отделу ответственного. Выбор вручную
                    закрепляется — синхронизация его не перебьёт.
                </p>
            </div>
        </div>
    </div>
</template>
