<script setup>
defineProps({
    card: { type: Object, required: true },
})

defineEmits(['open'])

// Приоритеты Битрикса: 0 — низкий, 1 — средний, 2 — высокий.
const priorityLabels = { 2: 'Высокий', 0: 'Низкий' }
</script>

<template>
    <article
        class="cursor-grab rounded-md bg-white p-3 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300 active:cursor-grabbing"
        @dblclick="$emit('open')"
    >
        <p class="text-sm leading-snug">{{ card.title }}</p>

        <footer class="mt-2 flex flex-wrap items-center gap-2 text-xs">
            <span class="font-medium text-slate-400">#{{ card.taskId }}</span>

            <span
                v-if="card.deadline"
                class="rounded px-1.5 py-0.5 font-medium"
                :class="card.isOverdue
                    ? 'bg-red-100 text-red-700'
                    : 'bg-slate-100 text-slate-600'"
            >
                {{ card.deadline }}
            </span>

            <span
                v-if="priorityLabels[card.priority]"
                class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-700"
            >
                {{ priorityLabels[card.priority] }}
            </span>
        </footer>
    </article>
</template>
