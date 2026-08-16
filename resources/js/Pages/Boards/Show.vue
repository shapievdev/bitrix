<script setup>
import { computed, reactive, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'
import { openTask, resizeFrame } from '../../bitrix'
import CardTile from '../../Components/CardTile.vue'

const props = defineProps({
    board: { type: Object, required: true },
    columns: { type: Array, required: true },
    departments: { type: Array, required: true },
    cards: { type: Array, required: true },
    priorities: { type: Array, required: true },
})

// Ключ ячейки «колонка × дорожка». Дорожка «Без подразделения» — null,
// в ключе это строка "none": null в ключе объекта превратился бы в "null"
// и совпал бы с подразделением, у которого такое имя.
const cellKey = (columnId, departmentId) => `${columnId}:${departmentId ?? 'none'}`

// Локальная раскладка: перетаскивание должно отрисовываться мгновенно,
// не дожидаясь ответа сервера. Сервер остаётся источником истины и при
// расхождении переписывает её следующим ответом.
const cells = reactive({})

function rebuild(cards) {
    for (const key of Object.keys(cells)) delete cells[key]

    for (const column of props.columns) {
        for (const department of props.departments) {
            cells[cellKey(column.id, department.id)] = []
        }
    }

    for (const card of cards) {
        const key = cellKey(card.columnId, card.departmentId)
        if (cells[key]) cells[key].push(card)
    }
}

rebuild(props.cards)
watch(() => props.cards, rebuild)

const syncing = useForm({})
const total = computed(() => props.cards.length)

function onChange(columnId, departmentId, event) {
    const change = event.added ?? event.moved

    if (!change) return

    router.patch(
        route('app.cards.move', change.element.id),
        {
            column_id: columnId,
            department_id: departmentId,
            position: change.newIndex,
        },
        {
            preserveScroll: true,
            preserveState: true,
            // Ответ вернёт актуальную раскладку — если сервер решил иначе
            // (карточку уже двигал коллега), локальная копия поправится.
            onError: () => router.reload({ only: ['cards'] }),
        },
    )
}

function setPriority(card, priorityId) {
    router.patch(
        route('app.cards.priority', card.id),
        { priority_id: priorityId },
        { preserveScroll: true, preserveState: true },
    )
}

function laneCount(departmentId) {
    return props.cards.filter((c) => c.departmentId === departmentId).length
}

function sync() {
    syncing.post(route('app.boards.sync', props.board.id), {
        preserveScroll: true,
        onFinish: () => resizeFrame(),
    })
}
</script>

<template>
    <div class="p-4">
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold">{{ board.name }}</h1>
                <p class="mt-0.5 text-xs text-slate-500">
                    {{ total }} задач · {{ departments.length - 1 }} подразделений
                    <span v-if="board.syncedAt"> · обновлено {{ board.syncedAt }}</span>
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Link
                    :href="route('app.boards.settings', board.id)"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium transition hover:bg-slate-100"
                >
                    Настроить
                </Link>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-50"
                    :disabled="syncing.processing"
                    @click="sync"
                >
                    {{ syncing.processing ? 'Синхронизация…' : 'Обновить из Битрикс24' }}
                </button>
            </div>
        </header>

        <div class="overflow-x-auto pb-4">
            <div class="min-w-max">
                <!-- Шапка колонок: одна на всю доску, иначе статусы
                     повторялись бы над каждой дорожкой. -->
                <div class="sticky top-0 z-10 flex gap-3 bg-slate-50 pb-2">
                    <div class="w-40 shrink-0" />
                    <div
                        v-for="column in columns"
                        :key="column.id"
                        class="flex w-64 shrink-0 items-center gap-2 rounded-md bg-white px-3 py-2 ring-1 ring-slate-200"
                    >
                        <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: column.color }" />
                        <h2 class="truncate text-sm font-semibold">{{ column.name }}</h2>
                        <span class="ml-auto text-xs tabular-nums text-slate-400">
                            {{ column.total }}<template v-if="column.wipLimit">/{{ column.wipLimit }}</template>
                        </span>
                    </div>
                </div>

                <!-- Дорожки подразделений -->
                <div
                    v-for="department in departments"
                    :key="department.id ?? 'none'"
                    class="flex gap-3 border-t border-slate-200 py-2"
                >
                    <div class="w-40 shrink-0 pt-1">
                        <div class="flex items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: department.color }" />
                            <span class="truncate text-sm font-medium">{{ department.name }}</span>
                        </div>
                        <span class="ml-4.5 text-xs text-slate-400">{{ laneCount(department.id) }} задач</span>
                    </div>

                    <draggable
                        v-for="column in columns"
                        :key="column.id"
                        v-model="cells[cellKey(column.id, department.id)]"
                        :group="`board-${board.id}`"
                        item-key="id"
                        class="flex min-h-16 w-64 shrink-0 flex-col gap-2 rounded-md bg-slate-100 p-1.5"
                        ghost-class="opacity-40"
                        @change="onChange(column.id, department.id, $event)"
                    >
                        <template #item="{ element }">
                            <CardTile
                                :card="element"
                                :priorities="priorities"
                                @open="openTask(element.taskId)"
                                @priority="setPriority(element, $event)"
                            />
                        </template>
                    </draggable>
                </div>
            </div>
        </div>
    </div>
</template>
