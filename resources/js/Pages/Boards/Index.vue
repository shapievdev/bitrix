<script setup>
import { Link, useForm } from '@inertiajs/vue3'

defineProps({
    boards: { type: Array, required: true },
})

const form = useForm({ name: '', bitrix_group_id: null })

function create() {
    form.post(route('app.boards.store'), {
        onSuccess: () => form.reset(),
    })
}
</script>

<template>
    <div class="mx-auto max-w-3xl p-6">
        <h1 class="text-xl font-semibold">Доски</h1>

        <form class="mt-4 flex flex-wrap gap-2" @submit.prevent="create">
            <div class="flex-1">
                <input
                    v-model="form.name"
                    type="text"
                    placeholder="Название доски"
                    class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
                >
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">
                    {{ form.errors.name }}
                </p>
            </div>

            <input
                v-model.number="form.bitrix_group_id"
                type="number"
                placeholder="ID проекта"
                title="Необязательно: ограничить доску одной рабочей группой Битрикс24"
                class="w-32 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
            >

            <button
                type="submit"
                class="h-fit rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-50"
                :disabled="form.processing"
            >
                Создать
            </button>
        </form>

        <ul v-if="boards.length" class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            <li v-for="board in boards" :key="board.id">
                <Link
                    :href="route('app.boards.show', board.id)"
                    class="flex items-center gap-3 px-4 py-3 transition hover:bg-slate-50"
                >
                    <span class="flex-1 text-sm font-medium">{{ board.name }}</span>
                    <span class="text-xs text-slate-500">{{ board.cardsCount }} задач</span>
                    <span v-if="board.syncedAt" class="text-xs text-slate-400">{{ board.syncedAt }}</span>
                </Link>
            </li>
        </ul>

        <p v-else class="mt-6 rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
            Досок пока нет. Создайте первую — колонки под штатные статусы Битрикс24 добавятся сами.
        </p>
    </div>
</template>
