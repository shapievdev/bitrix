<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'
import { openTask, resizeFrame } from '../../bitrix'
import CardTile from '../../Components/CardTile.vue'

const props = defineProps({
    board: { type: Object, required: true },
    columns: { type: Array, required: true },
})

// Локальная копия: перетаскивание должно отрисовываться мгновенно, не
// дожидаясь ответа сервера. Сервер остаётся источником истины и при
// расхождении переписывает её на следующем ответе.
const columns = ref(props.columns.map((column) => ({ ...column, cards: [...column.cards] })))

watch(
    () => props.columns,
    (fresh) => {
        columns.value = fresh.map((column) => ({ ...column, cards: [...column.cards] }))
    },
)

const syncing = useForm({})
const totalCards = computed(() =>
    columns.value.reduce((sum, column) => sum + column.cards.length, 0),
)

function onDrop(column, event) {
    // added — карточка пришла из другой колонки, moved — сортировка внутри.
    const change = event.added ?? event.moved

    if (!change) return

    router.patch(
        route('app.cards.move', change.element.id),
        { column_id: column.id, position: change.newIndex },
        {
            preserveScroll: true,
            preserveState: true,
            // Ответ вернёт актуальную раскладку — если сервер решил иначе
            // (карточку уже двигал коллега), локальная копия поправится.
            onError: () => router.reload({ only: ['columns'] }),
        },
    )
}

function sync() {
    syncing.post(route('app.boards.sync', props.board.id), {
        preserveScroll: true,
        onFinish: () => resizeFrame(),
    })
}
</script>

<template>
    <div class="p-6">
        <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ board.name }}</h1>
                <p class="mt-0.5 text-sm text-slate-500">
                    {{ totalCards }} задач
                    <span v-if="board.syncedAt"> · обновлено {{ board.syncedAt }}</span>
                </p>
            </div>

            <button
                type="button"
                class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-50"
                :disabled="syncing.processing"
                @click="sync"
            >
                {{ syncing.processing ? 'Синхронизация…' : 'Обновить из Битрикс24' }}
            </button>
        </header>

        <div class="flex gap-4 overflow-x-auto pb-4">
            <section
                v-for="column in columns"
                :key="column.id"
                class="flex w-72 shrink-0 flex-col rounded-lg bg-slate-100"
            >
                <header class="flex items-center gap-2 px-3 py-2.5">
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :style="{ backgroundColor: column.color }"
                    />
                    <h2 class="truncate text-sm font-semibold">{{ column.name }}</h2>

                    <span
                        class="ml-auto rounded px-1.5 py-0.5 text-xs font-medium tabular-nums"
                        :class="column.overLimit
                            ? 'bg-red-100 text-red-700'
                            : 'bg-slate-200 text-slate-600'"
                        :title="column.wipLimit
                            ? `Предел незавершённой работы: ${column.wipLimit}`
                            : undefined"
                    >
                        {{ column.cards.length }}<template v-if="column.wipLimit">/{{ column.wipLimit }}</template>
                    </span>
                </header>

                <draggable
                    v-model="column.cards"
                    :group="`board-${board.id}`"
                    item-key="id"
                    class="flex min-h-24 flex-1 flex-col gap-2 px-2 pb-2"
                    ghost-class="opacity-40"
                    drag-class="rotate-1"
                    @change="onDrop(column, $event)"
                >
                    <template #item="{ element }">
                        <CardTile :card="element" @open="openTask(element.taskId)" />
                    </template>
                </draggable>

                <p
                    v-if="!column.cards.length"
                    class="px-3 pb-3 text-xs text-slate-400"
                >
                    Пусто
                </p>
            </section>
        </div>
    </div>
</template>
