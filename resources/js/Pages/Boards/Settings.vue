<script setup>
import AppLayout from '../../Layouts/AppLayout.vue'
import { ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'

const props = defineProps({
    board: { type: Object, required: true },
    departments: { type: Array, required: true },
    priorities: { type: Array, required: true },
    columns: { type: Array, required: true },
    bitrixStatuses: { type: Array, required: true },
})

const tab = ref('departments')

const tabs = [
    { key: 'departments', label: 'Подразделения' },
    { key: 'columns', label: 'Колонки' },
    { key: 'priorities', label: 'Приоритеты' },
]

const departmentForm = useForm({ name: '', color: '#3b82f6', bitrix_department_id: null })
const columnForm = useForm({ name: '', color: '#94a3b8', bitrix_status: null, wip_limit: 0 })
const priorityForm = useForm({ name: '', color: '#f59e0b', weight: 25, bitrix_priority: null })

const addDepartment = () =>
    departmentForm.post(route('app.departments.store'), { onSuccess: () => departmentForm.reset() })

const addColumn = () =>
    columnForm.post(route('app.columns.store', props.board.id), { onSuccess: () => columnForm.reset() })

const addPriority = () =>
    priorityForm.post(route('app.priorities.store'), { onSuccess: () => priorityForm.reset() })

const patch = (routeName, id, payload) =>
    router.patch(route(routeName, id), payload, { preserveScroll: true })

const remove = (routeName, id) =>
    router.delete(route(routeName, id), { preserveScroll: true })

const importDepartments = () =>
    router.post(route('app.departments.import'), {}, { preserveScroll: true })

const statusLabel = (value) =>
    props.bitrixStatuses.find((s) => s.value === value)?.label ?? '—'

// Порядок колонок правится перетаскиванием. Локальная копия нужна,
// чтобы строка вставала на новое место сразу, не дожидаясь ответа.
const orderedColumns = ref([...props.columns])

watch(() => props.columns, (value) => {
    orderedColumns.value = [...value]
})

const saveColumnOrder = () =>
    router.patch(
        route('app.columns.reorder', props.board.id),
        { ids: orderedColumns.value.map((c) => c.id) },
        { preserveScroll: true, preserveState: true },
    )
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-4xl p-6">
            <header class="mb-5">
                <Link :href="route('app.boards.show', board.id)" class="text-sm text-slate-500 hover:text-slate-900">
                    ← {{ board.name }}
                </Link>
                <h1 class="mt-1 text-lg font-semibold">Настройка доски</h1>
            </header>


            <nav class="mb-4 flex gap-1 rounded-lg bg-slate-100 p-1">
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    type="button"
                    class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition"
                    :class="tab === t.key ? 'bg-white shadow-sm' : 'text-slate-500 hover:text-slate-900'"
                    @click="tab = t.key"
                >
                    {{ t.label }}
                </button>
            </nav>

            <!-- Подразделения -->
            <section v-if="tab === 'departments'" class="space-y-3">
                <p class="text-xs text-slate-500">
                    Подразделения — это горизонтальные дорожки доски. Если указать отдел
                    оргструктуры Битрикс24, задачи будут попадать в дорожку сами, по отделу
                    ответственного.
                </p>

                <div class="rounded-lg border border-slate-200 bg-white">
                    <div
                        v-for="d in departments"
                        :key="d.id"
                        class="flex items-center gap-3 border-b border-slate-100 px-3 py-2 last:border-0"
                    >
                        <input
                            type="color"
                            :value="d.color"
                            class="size-6 shrink-0 cursor-pointer rounded border-0 bg-transparent p-0"
                            @change="patch('app.departments.update', d.id, { name: d.name, color: $event.target.value })"
                        >
                        <input
                            type="text"
                            :value="d.name"
                            class="flex-1 rounded border border-transparent px-2 py-1 text-sm hover:border-slate-300 focus:border-slate-400 focus:outline-none"
                            @blur="patch('app.departments.update', d.id, { name: $event.target.value, color: d.color })"
                        >
                        <span v-if="d.bitrixId" class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">
                            отдел #{{ d.bitrixId }}
                        </span>
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-xs transition"
                            :class="d.isDefault ? 'bg-slate-900 text-white' : 'text-slate-400 hover:bg-slate-100'"
                            title="Сюда попадают задачи, чей отдел определить не удалось"
                            @click="patch('app.departments.update', d.id, { name: d.name, color: d.color, is_default: true })"
                        >
                            по умолчанию
                        </button>
                        <button
                            type="button"
                            class="text-xs text-slate-400 transition hover:text-red-600"
                            @click="remove('app.departments.destroy', d.id)"
                        >
                            удалить
                        </button>
                    </div>
                </div>

                <form class="flex gap-2" @submit.prevent="addDepartment">
                    <input v-model="departmentForm.color" type="color" class="size-9 cursor-pointer rounded border-0 bg-transparent p-0">
                    <input
                        v-model="departmentForm.name"
                        type="text"
                        placeholder="Название подразделения"
                        class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
                    >
                    <input
                        v-model.number="departmentForm.bitrix_department_id"
                        type="number"
                        placeholder="ID отдела"
                        title="Необязательно: ID отдела в оргструктуре Битрикс24"
                        class="w-28 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
                    >
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                        Добавить
                    </button>
                </form>

                <button
                    type="button"
                    class="text-sm text-slate-500 underline-offset-2 transition hover:text-slate-900 hover:underline"
                    @click="importDepartments"
                >
                    Подтянуть отделы из оргструктуры Битрикс24
                </button>
            </section>

            <!-- Колонки -->
            <section v-if="tab === 'columns'" class="space-y-3">
                <p class="text-xs text-slate-500">
                    Колонка без привязки к статусу Битрикса живёт только здесь — перенос в неё
                    ничего в портале не меняет. В этом и смысл: своих этапов может быть
                    больше, чем штатных статусов.
                </p>

                <p class="text-xs text-slate-500">
                    Порядок колонок — как на доске. Перетащите строку за
                    <span class="text-slate-400">⠿</span> слева, чтобы поменять.
                </p>

                <div class="rounded-lg border border-slate-200 bg-white">
                    <draggable
                        v-model="orderedColumns"
                        item-key="id"
                        handle=".column-grip"
                        ghost-class="opacity-40"
                        @end="saveColumnOrder"
                    >
                        <template #item="{ element: c }">
                            <div class="flex items-center gap-3 border-b border-slate-100 px-3 py-2 last:border-0">
                                <button
                                    type="button"
                                    class="column-grip -ml-1 cursor-grab px-1 text-slate-300 transition hover:text-slate-500 active:cursor-grabbing"
                                    title="Перетащите, чтобы изменить порядок"
                                >
                                    <svg class="size-4" viewBox="0 0 16 16" fill="currentColor">
                                        <circle cx="6" cy="4" r="1.3" />
                                        <circle cx="10" cy="4" r="1.3" />
                                        <circle cx="6" cy="8" r="1.3" />
                                        <circle cx="10" cy="8" r="1.3" />
                                        <circle cx="6" cy="12" r="1.3" />
                                        <circle cx="10" cy="12" r="1.3" />
                                    </svg>
                                </button>
                                <input
                                    type="color"
                                    :value="c.color"
                                    class="size-6 shrink-0 cursor-pointer rounded border-0 bg-transparent p-0"
                                    @change="patch('app.columns.update', c.id, { name: c.name, color: $event.target.value })"
                                >
                                <input
                                    type="text"
                                    :value="c.name"
                                    class="flex-1 rounded border border-transparent px-2 py-1 text-sm hover:border-slate-300 focus:border-slate-400 focus:outline-none"
                                    @blur="patch('app.columns.update', c.id, { name: $event.target.value, color: c.color })"
                                >
                                <select
                                    class="rounded border border-slate-200 px-1.5 py-1 text-xs"
                                    :value="c.bitrixStatus ?? ''"
                                    @change="patch('app.columns.update', c.id, { name: c.name, color: c.color, bitrix_status: $event.target.value || null })"
                                >
                                    <option value="">без статуса</option>
                                    <option v-for="s in bitrixStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                                </select>
                                <button
                                    type="button"
                                    class="text-xs text-slate-400 transition hover:text-red-600"
                                    @click="remove('app.columns.destroy', c.id)"
                                >
                                    удалить
                                </button>
                            </div>
                        </template>
                    </draggable>
                </div>

                <form class="flex gap-2" @submit.prevent="addColumn">
                    <input v-model="columnForm.color" type="color" class="size-9 cursor-pointer rounded border-0 bg-transparent p-0">
                    <input
                        v-model="columnForm.name"
                        type="text"
                        placeholder="Название колонки"
                        class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
                    >
                    <select v-model="columnForm.bitrix_status" class="rounded-md border border-slate-300 px-2 py-2 text-sm">
                        <option :value="null">без статуса</option>
                        <option v-for="s in bitrixStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                        Добавить
                    </button>
                </form>
            </section>

            <!-- Приоритеты -->
            <section v-if="tab === 'priorities'" class="space-y-3">
                <p class="text-xs text-slate-500">
                    Уровней может быть сколько угодно. <b>Вес</b> задаёт, насколько уровень
                    важнее остальных: чем больше число, тем выше приоритет. По нему уровни
                    сортируются в списках.
                </p>
                <p class="text-xs text-slate-500">
                    Связь со штатным приоритетом нужна, чтобы он был виден и в самом
                    Битриксе — там всего три градации. Если на один штатный указывают
                    несколько ваших уровней, при импорте задачи ставится
                    <b>наименее важный из них</b>: повышать приоритет самовольно приложение
                    не должно.
                </p>

                <div class="rounded-lg border border-slate-200 bg-white">
                    <div class="flex items-center gap-3 border-b border-slate-200 px-3 py-1.5 text-[11px] uppercase tracking-wide text-slate-400">
                        <span class="w-6 shrink-0" />
                        <span class="flex-1">Название</span>
                        <span class="w-16 text-center">Вес</span>
                        <span class="w-28">В Битриксе</span>
                        <span class="w-14" />
                    </div>
                    <div
                        v-for="p in priorities"
                        :key="p.id"
                        class="flex items-center gap-3 border-b border-slate-100 px-3 py-2 last:border-0"
                    >
                        <input
                            type="color"
                            :value="p.color"
                            class="size-6 shrink-0 cursor-pointer rounded border-0 bg-transparent p-0"
                            @change="patch('app.priorities.update', p.id, { name: p.name, color: $event.target.value, weight: p.weight })"
                        >
                        <input
                            type="text"
                            :value="p.name"
                            class="flex-1 rounded border border-transparent px-2 py-1 text-sm hover:border-slate-300 focus:border-slate-400 focus:outline-none"
                            @blur="patch('app.priorities.update', p.id, { name: $event.target.value, color: p.color, weight: p.weight })"
                        >
                        <input
                            type="number"
                            :value="p.weight"
                            min="0"
                            title="Чем больше число, тем выше приоритет"
                            class="w-16 rounded border border-slate-200 px-1.5 py-1 text-center text-xs"
                            @blur="patch('app.priorities.update', p.id, { name: p.name, color: p.color, weight: $event.target.value })"
                        >
                        <select
                            class="w-28 rounded border border-slate-200 px-1.5 py-1 text-xs"
                            :value="p.bitrixPriority ?? ''"
                            @change="patch('app.priorities.update', p.id, { name: p.name, color: p.color, weight: p.weight, bitrix_priority: $event.target.value === '' ? null : $event.target.value })"
                        >
                            <option value="">не передавать</option>
                            <option value="0">низкий</option>
                            <option value="1">средний</option>
                            <option value="2">высокий</option>
                        </select>
                        <button
                            type="button"
                            class="w-14 text-right text-xs text-slate-400 transition hover:text-red-600"
                            @click="remove('app.priorities.destroy', p.id)"
                        >
                            удалить
                        </button>
                    </div>
                </div>

                <form class="flex gap-2" @submit.prevent="addPriority">
                    <input v-model="priorityForm.color" type="color" class="size-9 cursor-pointer rounded border-0 bg-transparent p-0">
                    <input
                        v-model="priorityForm.name"
                        type="text"
                        placeholder="Название уровня"
                        class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
                    >
                    <input
                        v-model.number="priorityForm.weight"
                        type="number"
                        min="0"
                        placeholder="Вес"
                        title="Чем больше число, тем выше приоритет"
                        class="w-20 rounded-md border border-slate-300 px-3 py-2 text-center text-sm"
                    >
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                        Добавить
                    </button>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
