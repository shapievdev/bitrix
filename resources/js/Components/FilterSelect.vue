<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
    // null проверку типа проходит и без объявления — Vue пропускает
    // пустое значение у необязательного свойства.
    modelValue: { type: [Number, String], default: null },
    // { value, label, color?, avatar? }
    options: { type: Array, required: true },
    // Подпись поля: она же остаётся видна, когда ничего не выбрано.
    label: { type: String, required: true },
    // Что показать в списке первым пунктом — «любой», «все» и подобное.
    anyLabel: { type: String, default: 'любой' },
    // Список длиннее десятка глазами не просматривают.
    searchable: { type: Boolean, default: false },
    searchPlaceholder: { type: String, default: 'Поиск' },
})

const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const query = ref('')
const root = ref(null)
const searchInput = ref(null)
const highlighted = ref(0)

const selected = computed(
    () => props.options.find((o) => String(o.value) === String(props.modelValue)) ?? null,
)

const visible = computed(() => {
    const term = query.value.trim().toLowerCase()

    if (!term) return props.options

    return props.options.filter((o) => o.label.toLowerCase().includes(term))
})

// Пункт «любой» живёт в списке наравне с остальными — иначе стрелками
// до него не добраться, и сбросить фильтр с клавиатуры нельзя.
const rows = computed(() => [{ value: null, label: props.anyLabel, muted: true }, ...visible.value])

function toggle() {
    open.value = !open.value
}

function choose(value) {
    open.value = false
    emit('update:modelValue', value)
}

function onKeydown(event) {
    if (!open.value) return

    if (event.key === 'Escape') {
        open.value = false

        return
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault()

        const step = event.key === 'ArrowDown' ? 1 : -1
        const count = rows.value.length

        highlighted.value = (highlighted.value + step + count) % count

        return
    }

    if (event.key === 'Enter') {
        event.preventDefault()
        choose(rows.value[highlighted.value]?.value ?? null)
    }
}

function onClickOutside(event) {
    if (open.value && root.value && !root.value.contains(event.target)) {
        open.value = false
    }
}

watch(open, async (isOpen) => {
    if (!isOpen) {
        query.value = ''

        return
    }

    highlighted.value = 0

    if (props.searchable) {
        await nextTick()
        searchInput.value?.focus()
    }
})

// Отфильтровали список — подсветка могла остаться за его пределами.
watch(rows, () => {
    highlighted.value = 0
})

onMounted(() => {
    document.addEventListener('mousedown', onClickOutside)
    document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onClickOutside)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex h-7 max-w-52 items-center gap-1.5 rounded-md border px-2 text-xs transition"
            :class="selected
                ? 'border-slate-300 bg-white text-slate-900 shadow-xs'
                : 'border-transparent bg-slate-100 text-slate-500 hover:bg-slate-200'"
            @click="toggle"
        >
            <span
                v-if="selected?.color"
                class="size-2 shrink-0 rounded-full"
                :style="{ backgroundColor: selected.color }"
            />
            <span class="truncate">
                <span :class="selected ? 'text-slate-400' : ''">{{ label }}<template v-if="selected">: </template></span>
                <span v-if="selected" class="font-medium">{{ selected.label }}</span>
            </span>
            <svg
                class="ml-auto size-3 shrink-0 text-slate-400 transition"
                :class="open ? 'rotate-180' : ''"
                viewBox="0 0 12 12"
                fill="none"
            >
                <path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-30 mt-1 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg"
        >
            <div v-if="searchable" class="border-b border-slate-100 p-1.5">
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    :placeholder="searchPlaceholder"
                    class="w-full rounded-md bg-slate-100 px-2 py-1.5 text-xs placeholder:text-slate-400 focus:bg-white focus:outline-2 focus:outline-[#2fc7f7]"
                >
            </div>

            <div class="max-h-64 overflow-y-auto py-1">
                <button
                    v-for="(row, index) in rows"
                    :key="row.value ?? '__any'"
                    type="button"
                    class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-xs transition"
                    :class="[
                        index === highlighted ? 'bg-slate-100' : '',
                        row.muted ? 'text-slate-400' : 'text-slate-700',
                        String(row.value) === String(modelValue) ? 'font-semibold text-slate-900' : '',
                    ]"
                    @mouseenter="highlighted = index"
                    @click="choose(row.value)"
                >
                    <span
                        v-if="row.color"
                        class="size-2 shrink-0 rounded-full"
                        :style="{ backgroundColor: row.color }"
                    />
                    <img
                        v-else-if="row.avatar"
                        :src="row.avatar"
                        :alt="row.label"
                        class="size-4 shrink-0 rounded-full object-cover"
                    >
                    <span class="truncate">{{ row.label }}</span>

                    <svg
                        v-if="String(row.value) === String(modelValue)"
                        class="ml-auto size-3 shrink-0 text-[#2fc7f7]"
                        viewBox="0 0 12 12"
                        fill="none"
                    >
                        <path d="m2.5 6.5 2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <p v-if="searchable && visible.length === 0" class="px-2.5 py-2 text-xs text-slate-400">
                    Никого не нашлось
                </p>
            </div>
        </div>
    </div>
</template>