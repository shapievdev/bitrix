<script setup>
import { ref } from 'vue'

defineProps({
    card: { type: Object, required: true },
    priorities: { type: Array, default: () => [] },
})

const emit = defineEmits(['open', 'priority'])

const picking = ref(false)

function choose(priorityId) {
    picking.value = false
    emit('priority', priorityId)
}
</script>

<template>
    <article
        class="group relative cursor-grab rounded-md bg-white p-2.5 shadow-sm ring-1 ring-slate-200 transition hover:ring-slate-300 active:cursor-grabbing"
    >
        <!-- Цветная полоса приоритета: читается боковым зрением, не занимая
             места в карточке. -->
        <span
            v-if="card.priority"
            class="absolute inset-y-1 left-0 w-1 rounded-full"
            :style="{ backgroundColor: card.priority.color }"
            :title="`Приоритет: ${card.priority.name}`"
        />

        <p class="pl-1.5 text-sm leading-snug" @dblclick="$emit('open')">
            {{ card.title }}
        </p>

        <footer class="mt-1.5 flex flex-wrap items-center gap-1.5 pl-1.5 text-xs">
            <span class="font-medium text-slate-400">#{{ card.taskId }}</span>

            <span
                v-if="card.deadline"
                class="rounded px-1.5 py-0.5 font-medium"
                :class="card.isOverdue ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'"
            >
                {{ card.deadline }}
            </span>

            <button
                type="button"
                class="ml-auto rounded px-1.5 py-0.5 font-medium transition"
                :style="card.priority
                    ? { backgroundColor: card.priority.color + '22', color: card.priority.color }
                    : {}"
                :class="card.priority ? '' : 'bg-slate-100 text-slate-500 opacity-0 group-hover:opacity-100'"
                @click.stop="picking = !picking"
            >
                {{ card.priority ? card.priority.name : 'приоритет' }}
            </button>
        </footer>

        <div
            v-if="picking"
            class="absolute right-2 top-full z-20 mt-1 w-40 rounded-md border border-slate-200 bg-white py-1 shadow-lg"
        >
            <button
                v-for="p in priorities"
                :key="p.id"
                type="button"
                class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-xs transition hover:bg-slate-50"
                @click.stop="choose(p.id)"
            >
                <span class="size-2 rounded-full" :style="{ backgroundColor: p.color }" />
                {{ p.name }}
            </button>
            <button
                type="button"
                class="w-full px-2.5 py-1.5 text-left text-xs text-slate-400 transition hover:bg-slate-50"
                @click.stop="choose(null)"
            >
                Без приоритета
            </button>
        </div>
    </article>
</template>
