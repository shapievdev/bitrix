<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

defineProps({
    stats: { type: Object, required: true },
})

const page = usePage()
const user = computed(() => page.props.auth.user)
const placement = computed(() => page.props.placement)
</script>

<template>
    <div class="mx-auto max-w-5xl p-6">
        <header class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Задачи+</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Портал {{ stats.portal }}
                </p>
            </div>

            <div v-if="user" class="flex items-center gap-3">
                <img
                    v-if="user.avatar"
                    :src="user.avatar"
                    :alt="user.name"
                    class="size-9 rounded-full object-cover"
                >
                <div class="text-right">
                    <div class="text-sm font-medium">{{ user.name }}</div>
                    <div class="text-xs text-slate-500">
                        {{ user.is_admin ? 'Администратор портала' : 'Сотрудник' }}
                    </div>
                </div>
            </div>
        </header>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Сотрудников подключено</div>
                <div class="mt-1 text-2xl font-semibold">{{ stats.users }}</div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Установлено</div>
                <div class="mt-1 text-sm font-medium">{{ stats.installedAt ?? '—' }}</div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500">Место встраивания</div>
                <div class="mt-1 text-sm font-medium">{{ placement.code }}</div>
            </div>
        </div>

        <div class="mt-6 rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500">Выданные права</div>
            <div class="mt-2 flex flex-wrap gap-2">
                <span
                    v-for="scope in stats.scope"
                    :key="scope"
                    class="rounded bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700"
                >{{ scope }}</span>
                <span v-if="!stats.scope.length" class="text-sm text-slate-400">—</span>
            </div>
        </div>
    </div>
</template>