<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'
import AppLayout from '../../Layouts/AppLayout.vue'
import { openTask, openTaskForm, resizeFrame } from '../../bitrix'
import CardTile from '../../Components/CardTile.vue'
import FilterSelect from '../../Components/FilterSelect.vue'

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
    viewer: { type: Object, required: true },
})

// Что за набор задач человек вообще видит — объясняет, почему на доске
// три карточки, а не триста.
const scopeHint = computed(() => {
    if (props.viewer.isAdmin) return 'все задачи портала'
    if (props.viewer.headsDepartments) return 'задачи вашего подразделения'

    return 'задачи с вашим участием'
})

const priorityOptions = computed(() =>
    props.priorities.map((p) => ({ value: p.id, label: p.name, color: p.color })),
)

const responsibleOptions = computed(() =>
    props.responsibles.map((r) => ({ value: r.id, label: r.name })),
)

const deadlineOptions = [
    { value: 'overdue', label: 'Просроченные', color: '#ef4444' },
    { value: 'with', label: 'Со сроком' },
    { value: 'without', label: 'Без срока' },
]

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
            <aside class="flex w-60 shrink-0 flex-col border-r border-slate-200 bg-white">
                <header class="flex items-center gap-2 px-4 pb-2 pt-3.5">
                    <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Департаменты
                    </h2>
                    <button
                        type="button"
                        class="ml-auto flex size-6 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40"
                        :disabled="syncing.processing"
                        title="Обновить задачи из Битрикс24"
                        @click="sync"
                    >
                        <svg
                            class="size-3.5"
                            :class="syncing.processing ? 'animate-spin' : ''"
                            viewBox="0 0 14 14"
                            fill="none"
                        >
                            <path
                                d="M12 7a5 5 0 1 1-1.6-3.66M12 1.5V4.5H9"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </button>
                </header>

                <div class="flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
                    <button
                        type="button"
                        class="group flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] transition"
                        :class="!selected.id
                            ? 'bg-slate-900 font-medium text-white'
                            : 'text-slate-600 hover:bg-slate-100'"
                        @click="select(null)"
                    >
                        <svg class="size-3.5 shrink-0 opacity-70" viewBox="0 0 14 14" fill="none">
                            <rect x="1.5" y="2" width="11" height="3" rx="1" stroke="currentColor" stroke-width="1.3" />
                            <rect x="1.5" y="9" width="11" height="3" rx="1" stroke="currentColor" stroke-width="1.3" />
                        </svg>
                        <span class="flex-1">Все задачи</span>
                        <span
                            class="rounded-full px-1.5 py-0.5 text-[11px] tabular-nums"
                            :class="!selected.id ? 'bg-white/15' : 'bg-slate-100 text-slate-500'"
                        >
                            {{ board.total }}
                        </span>
                    </button>

                    <button
                        v-for="d in departments"
                        :key="d.id"
                        type="button"
                        class="group relative flex w-full items-center gap-2.5 rounded-lg py-2 pl-2.5 pr-2 text-left text-[13px] transition"
                        :class="selected.departmentId === d.id
                            ? 'bg-slate-100 font-medium text-slate-900'
                            : 'text-slate-600 hover:bg-slate-50'"
                        @click="select(d.id)"
                    >
                        <!-- Цвет департамента: точка у неактивного, полоса у
                             выбранного — так видно текущий раздел, не
                             перекрашивая всю строку. -->
                        <span
                            v-if="selected.departmentId === d.id"
                            class="absolute inset-y-1.5 left-0 w-[3px] rounded-r-full"
                            :style="{ backgroundColor: d.color }"
                        />
                        <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: d.color }" />

                        <span class="flex-1 leading-tight">{{ d.name }}</span>

                        <span
                            v-if="d.count"
                            class="shrink-0 text-[11px] tabular-nums text-slate-400"
                        >
                            {{ d.count }}
                        </span>
                    </button>
                </div>
            </aside>

            <!-- Отделы выбранного департамента -->
            <aside class="flex w-56 shrink-0 flex-col border-r border-slate-200 bg-slate-50">
                <header class="px-4 pb-2 pt-3.5">
                    <h2 class="truncate text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Отделы
                    </h2>
                </header>

                <div v-if="units.length" class="flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
                    <button
                        v-if="selected.departmentId"
                        type="button"
                        class="flex w-full items-center rounded-lg px-2.5 py-2 text-left text-[13px] transition"
                        :class="selected.id === selected.departmentId
                            ? 'bg-white font-medium text-slate-900 shadow-xs'
                            : 'text-slate-500 hover:bg-white/70'"
                        @click="select(selected.departmentId)"
                    >
                        Весь департамент
                    </button>

                    <button
                        v-for="u in units"
                        :key="u.id"
                        type="button"
                        class="relative flex w-full items-center gap-2 rounded-lg py-2 pr-2 text-left text-[13px] transition"
                        :class="selected.id === u.id
                            ? 'bg-white font-medium text-slate-900 shadow-xs'
                            : 'text-slate-600 hover:bg-white/70'"
                        :style="{ paddingLeft: `${10 + u.depth * 14}px` }"
                        @click="select(u.id)"
                    >
                        <!-- Направляющая вложенности: без неё подотделы
                             читаются как плоский список с лишними отступами. -->
                        <span
                            v-if="u.depth"
                            class="absolute inset-y-1 w-px bg-slate-200"
                            :style="{ left: `${4 + u.depth * 14 - 7}px` }"
                        />
                        <span class="flex-1 leading-tight">{{ u.name }}</span>
                        <span
                            v-if="u.count"
                            class="shrink-0 text-[11px] tabular-nums text-slate-400"
                        >
                            {{ u.count }}
                        </span>
                    </button>
                </div>

                <div v-else class="px-4 pt-6 text-center">
                    <svg class="mx-auto size-8 text-slate-300" viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 7h6l1.5 2H20v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7Z"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <p class="mt-2 text-xs leading-relaxed text-slate-400">
                        Выберите департамент слева — здесь появятся его отделы.
                    </p>
                </div>
            </aside>

            <!-- Канбан -->
            <section class="flex min-w-0 flex-1 flex-col bg-slate-50">
                <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-slate-200 bg-white px-4 py-2.5">
                    <div class="min-w-0">
                        <h1 class="truncate text-sm font-semibold text-slate-900">
                            {{ selected.name ?? 'Все задачи' }}
                        </h1>
                        <p class="truncate text-[11px] text-slate-400">
                            {{ cards.length }} на доске · {{ scopeHint }}<span v-if="board.syncedAt"> · {{ board.syncedAt }}</span>
                        </p>
                    </div>

                    <div class="ml-auto flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            class="h-7 rounded-md bg-[#2fc7f7] px-3 text-xs font-semibold text-white transition hover:brightness-95"
                            @click="openFullForm"
                        >
                            + Создать задачу
                        </button>

                        <div class="relative">
                            <svg
                                class="pointer-events-none absolute left-2 top-1/2 size-3.5 -translate-y-1/2 text-slate-400"
                                viewBox="0 0 14 14"
                                fill="none"
                            >
                                <circle cx="6" cy="6" r="4" stroke="currentColor" stroke-width="1.4" />
                                <path d="m9.5 9.5 3 3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                            </svg>
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Название или #номер"
                                class="h-7 w-52 rounded-md bg-slate-100 pl-7 pr-2 text-xs placeholder:text-slate-400 focus:bg-white focus:outline-2 focus:outline-[#2fc7f7]"
                            >
                        </div>

                        <FilterSelect
                            label="Приоритет"
                            :options="priorityOptions"
                            :model-value="filters.priority"
                            @update:model-value="reload({ priority: $event ?? undefined })"
                        />

                        <FilterSelect
                            label="Исполнитель"
                            :options="responsibleOptions"
                            :model-value="filters.responsible"
                            searchable
                            search-placeholder="Найти сотрудника"
                            @update:model-value="reload({ responsible: $event ?? undefined })"
                        />

                        <FilterSelect
                            label="Срок"
                            :options="deadlineOptions"
                            :model-value="filters.deadline"
                            @update:model-value="reload({ deadline: $event ?? undefined })"
                        />

                        <button
                            v-if="hasFilters"
                            type="button"
                            class="h-7 rounded-md px-2 text-xs text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                            @click="resetFilters"
                        >
                            Сбросить
                        </button>

                        <Link
                            :href="route('app.boards.settings', board.id)"
                            class="flex size-7 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                            title="Настроить доску"
                        >
                            <svg class="size-4" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="2.25" stroke="currentColor" stroke-width="1.3" />
                                <path
                                    d="M8 1.5v1.75M8 12.75v1.75M14.5 8h-1.75M3.25 8H1.5m10.6-4.6-1.24 1.24M5.14 10.86 3.9 12.1m8.2 0-1.24-1.24M5.14 5.14 3.9 3.9"
                                    stroke="currentColor"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </Link>
                    </div>
                </header>

                <!-- overscroll-x-contain не даёт свайпу у края доски уйти в
                     навигацию браузера «назад» вместо прокрутки колонок. -->
                <div class="flex flex-1 gap-4 overflow-x-auto overscroll-x-contain px-4 pb-4">
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

                        <!-- overflow-x-hidden обязателен, а не для красоты:
                             при overflow-y-auto вторая ось по правилам CSS
                             тоже становится auto, колонка превращается в
                             горизонтальный скроллер без хода, и жест влево
                             вправо залипает на ней вместо прокрутки доски.

                             delay с delayOnTouchOnly оставляет мышке
                             мгновенное перетаскивание, а пальцу даёт сначала
                             проскроллить: иначе свайп по карточкам тащит
                             карточку, а доска стоит. -->
                        <draggable
                            v-model="stacks[column.id]"
                            :group="`board-${board.id}`"
                            item-key="id"
                            class="flex flex-1 flex-col gap-2 overflow-y-auto overflow-x-hidden overscroll-y-contain pt-2"
                            ghost-class="opacity-40"
                            :delay="150"
                            :delay-on-touch-only="true"
                            :touch-start-threshold="8"
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
