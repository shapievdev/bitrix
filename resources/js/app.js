import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { ZiggyVue } from 'ziggy-js'
import { syncFrameHeight } from './bitrix'

// Резервный контекст портала. Приложение живёт в iframe чужого домена, и
// если браузер режет сторонние cookie, сессия до следующего запроса не
// доживает. Токен приходит в общих пропсах и досылается заголовком.
let contextToken = null

createInertiaApp({
    title: (title) => (title ? `${title} — Задачи+` : 'Задачи+'),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })

        return pages[`./Pages/${name}.vue`]
    },

    setup({ el, App, props, plugin }) {
        contextToken = props.initialPage.props.contextToken ?? null

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el)
    },
})

router.on('before', (event) => {
    if (contextToken) {
        event.detail.visit.headers = {
            ...event.detail.visit.headers,
            'X-Bitrix-Context': contextToken,
        }
    }
})

router.on('success', (event) => {
    contextToken = event.detail.page.props.contextToken ?? contextToken

    // Фрейм не растёт сам — без этого длинные списки обрезаются.
    syncFrameHeight()
})

syncFrameHeight()