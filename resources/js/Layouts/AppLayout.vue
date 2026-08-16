<script setup>
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()
</script>

<template>
    <div class="min-h-full">
        <nav class="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2">
            <Link
                :href="route('app.boards.index')"
                class="text-sm font-semibold transition hover:text-slate-600"
            >
                Задачи+
            </Link>

            <span class="text-xs text-slate-400">{{ page.props.portal?.domain }}</span>

            <div v-if="page.props.auth.user" class="ml-auto flex items-center gap-2">
                <img
                    v-if="page.props.auth.user.avatar"
                    :src="page.props.auth.user.avatar"
                    :alt="page.props.auth.user.name"
                    class="size-6 rounded-full object-cover"
                >
                <span class="text-xs text-slate-500">{{ page.props.auth.user.name }}</span>
            </div>
        </nav>

        <p
            v-if="page.props.flash.success"
            class="border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800"
        >
            {{ page.props.flash.success }}
        </p>
        <p
            v-if="page.props.flash.error"
            class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800"
        >
            {{ page.props.flash.error }}
        </p>

        <slot />
    </div>
</template>
