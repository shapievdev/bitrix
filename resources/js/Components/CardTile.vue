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
        class="group relative cursor-grab rounded-sm border border-slate-200 bg-white p-3 transition hover:border-slate-300 active:cursor-grabbing"
    >
        <!-- Цветная полоса приоритета: читается боковым зрением, не занимая
             места в карточке. -->
        <span
            v-if="card.priority"
            class="absolute inset-y-0 left-0 w-[3px] rounded-l-sm"
            :style="{ backgroundColor: card.priority.color }"
            :title="`Приоритет: ${card.priority.name}`"
        />

        <p
            class="text-[13px] leading-snug text-slate-900"
            @dblclick="$emit('open')"
        >
            {{ card.title }}
        </p>

        <div v-if="card.departments?.length" class="mt-2 flex flex-wrap gap-1">
            <span
                v-for="d in card.departments"
                :key="d.name"
                class="rounded-sm px-1.5 py-0.5 text-[11px] leading-tight"
                :style="{ backgroundColor: d.color + '1f', color: d.color }"
                :title="d.source === 'accomplice' ? 'Соисполнитель' : 'Исполнитель'"
            >
                <template v-if="d.source === 'accomplice'">· </template>{{ d.name }}
            </span>
        </div>

        <footer class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
            <span
                v-if="card.deadline"
                class="rounded-sm px-1.5 py-0.5"
                :class="card.isOverdue
                    ? 'bg-red-50 text-red-600'
                    : 'bg-slate-100 text-slate-500'"
            >
                {{ card.deadline }}
            </span>
            <span v-else class="rounded-sm bg-slate-100 px-1.5 py-0.5 text-slate-400">
                Без срока
            </span>

            <span class="text-slate-300">#{{ card.taskId }}</span>

            <button
                type="button"
                class="ml-auto rounded-sm px-1.5 py-0.5 transition"
                :style="card.priority
                    ? { backgroundColor: card.priority.color + '1f', color: card.priority.color }
                    : {}"
                :class="card.priority ? '' : 'bg-slate-100 text-slate-400 opacity-0 group-hover:opacity-100'"
                @click.stop="picking = !picking"
            >
                {{ card.priority ? card.priority.name : 'приоритет' }}
            </button>
        </footer>

        <div
            v-if="picking"
            class="absolute right-2 top-full z-20 mt-1 w-40 rounded-sm border border-slate-200 bg-white py-1 shadow-lg"
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
