/**
 * Обёртки над BX24 — библиотекой портала, доступной внутри iframe.
 *
 * Каждый вызов проверяет наличие BX24: приложение должно открываться и
 * вне портала (локальная разработка, сторибук), просто без интеграции.
 */

const bx = () => (typeof window !== 'undefined' ? window.BX24 : undefined)

export const inBitrix = () => Boolean(bx())

/**
 * Подогнать высоту фрейма под содержимое.
 *
 * Битрикс задаёт фрейму фиксированную высоту, и всё, что не поместилось,
 * просто обрезается — своей полосы прокрутки у фрейма нет.
 */
export function syncFrameHeight() {
    const api = bx()

    if (!api) return

    api.init(() => {
        api.fitWindow()
    })
}

/**
 * Явно задать высоту, когда содержимое меняется без перехода —
 * например, при перетаскивании карточки между колонками канбана.
 */
export function resizeFrame(height) {
    const api = bx()

    if (!api) return

    api.init(() => {
        api.resizeWindow(document.body.scrollWidth, height ?? document.body.scrollHeight)
    })
}

/**
 * Открыть карточку задачи в основном окне портала.
 */
export function openTask(taskId) {
    const api = bx()

    if (!api) {
        window.open(`/company/personal/user/0/tasks/task/view/${taskId}/`, '_blank')

        return
    }

    api.openPath(`/company/personal/user/0/tasks/task/view/${taskId}/`)
}

/**
 * Открыть штатную форму создания задачи в портале.
 *
 * Свою полную форму повторять смысла нет: в родной есть чек-листы,
 * файлы, наблюдатели и всё остальное, чего в быстром создании быть не
 * может.
 */
export function openTaskForm() {
    const api = bx()
    const path = '/company/personal/user/0/tasks/task/edit/0/'

    if (!api) {
        window.open(path, '_blank')

        return
    }

    api.init(() => {
        api.openPath(path)
    })
}

/**
 * Прямой вызов REST из браузера.
 *
 * Годится для мелочей вроде выбора сотрудника. Всё, что меняет данные,
 * должно идти через бэкенд: в браузере нет ни лимитов, ни повторов, ни
 * записи в нашу базу.
 */
export function callMethod(method, params = {}) {
    const api = bx()

    return new Promise((resolve, reject) => {
        if (!api) {
            reject(new Error('BX24 недоступен: приложение открыто вне Битрикс24'))

            return
        }

        api.init(() => {
            api.callMethod(method, params, (result) => {
                if (result.error()) {
                    reject(new Error(result.error().ex?.error_description ?? 'Ошибка REST'))

                    return
                }

                resolve(result.data())
            })
        })
    })
}