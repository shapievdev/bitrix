<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'
import AppLayout from '../../Layouts/AppLayout.vue'
import { openTask, openTaskForm, resizeFrame } from '../../bitrix'
import CardTile from '../../Components/CardTile.vue'

const props = defineProps({
    board: { type: Object, required: true },
    departments: { type: Array, required: true },
    units: { type: Array, required: true },
    selected: { type: Object, required: true },
    columns: { type: Array, required: true },
    cards: { type: Array, required: true },
    priorities: { type: Array, required: true },
    filters: { type: Object, required: true },
    responsibles: { type: Array, required: true },
})

// Локальная раскладка по колонкам: перетаскивание отрисовывается сразу,
// не дожидаясь ответа сервера. Источник истины остаётся на сервере и при
// расхождении переписывает её следующим ответом.
const stacks = reactive({})

function rebuild(cards) {
    for (const key of Object.keys(stacks)) delete stacks[key]
    for (const column of props.columns) stacks[column.id] = []
    for (const card of cards) stacks[card.columnId]?.push(card)
}

rebuild(props.cards)
watch(() => props.cards, rebuild)

const syncing = useForm({})

const search = ref(props.filters.q ?? '')
let searchTimer = null

const hasFilters = computed(() =>
    Boolean(props.filters.q || props.filters.priority || props.filters.responsible || props.filters.deadline),
)

/**
 * Перезапросить доску, сохранив текущий выбор отдела и фильтры.
 */
function reload(overrides = {}) {
    const query = {
        department: props.selected.id ?? undefined,
        q: search.value || undefined,
        priority: props.filters.priority ?? undefined,
        responsible: props.filters.responsible ?? undefined,
        deadline: props.filters.deadline ?? undefined,
        ...overrides,
    }

    // Пустые значения в адрес не пишем — иначе ссылка обрастает мусором
    // вида ?priority=&deadline= и её неудобно передавать коллеге.
    for (const key of Object.keys(query)) {
        if (query[key] === undefined || query[key] === null || query[key] === '') delete query[key]
    }

    router.get(route('app.boards.show', props.board.id), query, {
        preserveState: false,
        preserveScroll: true,
        onFinish: () => resizeFrame(),
    })
}

function select(departmentId) {
    reload({ department: departmentId ?? undefined })
}

// Ввод не должен дёргать сервер на каждую букву.
watch(search, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => reload({ q: search.value || undefined }), 400)
})

// Быстрое создание задачи под первой колонкой — как в канбане Битрикса.
const creating = ref(false)
const newTask = useForm({ title: '' })

function openQuickCreate() {
    creating.value = true
}

function submitQuickCreate() {
    if (!newTask.title.trim()) {
        creating.value = false

        return
    }

    newTask.post(route('app.tasks.store', props.board.id), {
        preserveScroll: true,
        onSuccess: () => {
            newTask.reset()
            // Поле остаётся открытым: задачи обычно заводят пачкой.
        },
        onFinish: () => resizeFrame(),
    })
}

/**
 * Полная форма задачи — штатная, в самом портале.
 *
 * Свою повторять смысла нет: в родной есть чек-листы, файлы, наблюдатели
 * и всё остальное, чего в быстром создании быть не может.
 */
function openFullForm() {
    openTaskForm()
}

function resetFilters() {
    search.value = ''
    reload({ q: undefined, priority: undefined, responsible: undefined, deadline: undefined })
}

function onChange(columnId, event) {
    const change = event.added ?? event.moved

    if (!change) return

    router.patch(
        route('app.cards.move', change.element.id),
        { column_id: columnId, position: change.newIndex },
        {
            preserveScroll: true,
            preserveState: true,
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

function sync() {
    syncing.post(route('app.boards.sync', props.board.id), {
        preserveScroll: true,
        onFinish: () => resizeFrame(),
    })
}
</script>

<template>
    <AppLayout>
        <div class="flex h-[calc(100vh-3rem)] min-h-125">
            <!-- Департаменты -->
            <aside class="flex w-56 shrink-0 flex-col border-r border-slate-200 bg-white">
                <header class="flex items-center justify-between px-3 py-2.5">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Департаменты
                    </h2>
                    <button
                        type="button"
                        class="text-xs text-slate-400 transition hover:text-slate-900 disabled:opacity-40"
                        :disabled="syncing.processing"
                        title="Обновить задачи из Битрикс24"
                        @click="sync"
                    >
                        {{ syncing.processing ? '…' : '↻' }}
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto pb-2">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition"
                        :class="!selected.id ? 'bg-slate-900 text-white' : 'hover:bg-slate-100'"
                        @click="select(null)"
                    >
                        <span class="flex-1">Все задачи</span>
                        <span class="text-xs tabular-nums opacity-60">{{ board.total }}</span>
                    </button>

                    <button
                        v-for="d in departments"
                        :key="d.id"
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition"
                        :class="selected.departmentId === d.id ? 'bg-slate-100 font-medium' : 'hover:bg-slate-50'"
                        @click="select(d.id)"
                    >
                        <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: d.color }" />
                        <span class="flex-1 leading-tight">{{ d.name }}</span>
                        <span class="text-xs tabular-nums text-slate-400">{{ d.count }}</span>
                    </button>
                </div>
            </aside>

            <!-- Отделы выбранного департамента -->
            <aside class="flex w-52 shrink-0 flex-col border-r border-slate-200 bg-slate-50/60">
                <header class="px-3 py-2.5">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Отделы
                    </h2>
                </header>

                <div v-if="units.length" class="flex-1 overflow-y-auto pb-2">
                    <button
                        v-if="selected.departmentId"
                        type="button"
                        class="w-full px-3 py-1.5 text-left text-sm transition"
                        :class="selected.id === selected.departmentId ? 'bg-slate-200 font-medium' : 'hover:bg-slate-100'"
                        @click="select(selected.departmentId)"
                    >
                        Весь департамент
                    </button>

                    <button
                        v-for="u in units"
                        :key="u.id"
                        type="button"
                        class="flex w-full items-center gap-2 py-1.5 pr-3 text-left text-sm transition"
                        :class="selected.id === u.id ? 'bg-slate-200 font-medium' : 'hover:bg-slate-100'"
                        :style="{ paddingLeft: `${12 + u.depth * 12}px` }"
                        @click="select(u.id)"
                    >
                        <span class="flex-1 leading-tight">{{ u.name }}</span>
                        <span class="text-xs tabular-nums text-slate-400">{{ u.count }}</span>
                    </button>
                </div>

                <p v-else class="px-3 text-xs leading-relaxed text-slate-400">
                    Выберите департамент слева — здесь появятся его отделы.
                </p>
            </aside>

            <!-- Канбан -->
            <section class="flex min-w-0 flex-1 flex-col bg-slate-50">
                <header class="flex flex-wrap items-center gap-2 px-4 py-2.5">
                    <h1 class="text-sm font-semibold">
                        {{ selected.name ?? 'Все задачи' }}
                    </h1>
                    <span class="text-xs text-slate-400">
                        {{ cards.length }} задач<span v-if="board.syncedAt"> · {{ board.syncedAt }}</span>
                    </span>

                    <div class="ml-auto flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            class="rounded-sm bg-[#2fc7f7] px-3 py-1 text-xs font-semibold text-white transition hover:brightness-95"
                            @click="openFullForm"
                        >
                            + Создать задачу
                        </button>

                        <input
                            v-model="search"
                            type="search"
                            placeholder="Поиск по названию или #номеру"
                            class="w-56 rounded-sm border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:outline-none"
                        >

                        <select
                            class="rounded-sm border border-slate-300 px-1.5 py-1 text-xs"
                            :value="filters.priority ?? ''"
                            @change="reload({ priority: $event.target.value || undefined })"
                        >
                            <option value="">Приоритет: любой</option>
                            <option v-for="p in priorities" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>

                        <select
                            class="max-w-44 rounded-sm border border-slate-300 px-1.5 py-1 text-xs"
                            :value="filters.responsible ?? ''"
                            @change="reload({ responsible: $event.target.value || undefined })"
                        >
                            <option value="">Исполнитель: любой</option>
                            <option v-for="r in responsibles" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>

                        <select
                            class="rounded-sm border border-slate-300 px-1.5 py-1 text-xs"
                            :value="filters.deadline ?? ''"
                            @change="reload({ deadline: $event.target.value || undefined })"
                        >
                            <option value="">Срок: любой</option>
                            <option value="overdue">Просроченные</option>
                            <option value="with">Со сроком</option>
                            <option value="without">Без срока</option>
                        </select>

                        <button
                            v-if="hasFilters"
                            type="button"
                            class="rounded-sm px-2 py-1 text-xs text-slate-500 transition hover:bg-slate-200"
                            @click="resetFilters"
                        >
                            Сбросить
                        </button>

                        <Link
                            :href="route('app.boards.settings', board.id)"
                            class="px-1.5 text-xs text-slate-400 transition hover:text-slate-900"
                        >
                            Настроить
                        </Link>
                    </div>
                </header>

                <div class="flex flex-1 gap-4 overflow-x-auto px-4 pb-4">
                    <div
                        v-for="(column, index) in columns"
                        :key="column.id"
                        class="flex w-70 shrink-0 flex-col"
                        :class="index < columns.length - 1 ? 'border-r border-dashed border-slate-300 pr-4' : ''"
                    >
                        <!-- Шапка-плашка как в канбане Битрикса: цвет колонки
                             заливает всю ширину, счётчик справа. -->
                        <header
                            class="flex items-center gap-2 rounded-sm px-3 py-2 text-white"
                            :style="{ backgroundColor: column.color }"
                        >
                            <h3 class="truncate text-[13px] font-semibold">{{ column.name }}</h3>
                            <span class="ml-auto text-xs tabular-nums opacity-80">
                                {{ column.total }}<template v-if="column.wipLimit">/{{ column.wipLimit }}</template>
                            </span>
                        </header>

                        <div v-if="index === 0" class="pt-2">
                            <button
                                v-if="!creating"
                                type="button"
                                class="w-full rounded-sm border border-dashed border-slate-300 py-1.5 text-xs font-medium text-slate-500 transition hover:border-slate-400 hover:text-slate-700"
                                @click="openQuickCreate"
                            >
                                + Быстрая задача
                            </button>

                            <form v-else @submit.prevent="submitQuickCreate">
                                <textarea
                                    v-model="newTask.title"
                                    rows="2"
                                    autofocus
                                    placeholder="Название #тег"
                                    class="w-full resize-none rounded-sm border border-[#2fc7f7] p-2 text-[13px] focus:outline-none"
                                    @keydown.enter.exact.prevent="submitQuickCreate"
                                    @keydown.esc="creating = false"
                                />
                                <p class="px-0.5 pt-1 text-[11px] italic text-slate-400">
                                    Нажмите <span class="not-italic">↵ Enter</span> чтобы создать
                                </p>
                                <p v-if="newTask.errors.title" class="px-0.5 text-[11px] text-red-600">
                                    {{ newTask.errors.title }}
                                </p>
                            </form>
                        </div>

                        <draggable
                            v-model="stacks[column.id]"
                            :group="`board-${board.id}`"
                            item-key="id"
                            class="flex flex-1 flex-col gap-2 overflow-y-auto pt-2"
                            ghost-class="opacity-40"
                            @change="onChange(column.id, $event)"
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
            </section>
        </div>
    </AppLayout>
</template>
