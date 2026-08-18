import { onBeforeUnmount, readonly, ref } from 'vue'

/**
 * Общее «сейчас», которое подтягивается раз в минуту.
 *
 * Один таймер на всю доску, а не по таймеру на карточку: карточек на
 * экране сотни, и триста интервалов ради подписи вида «3 дн» — верный
 * способ подвесить вкладку.
 */
const now = ref(Date.now())

let timer = null
let subscribers = 0

export function useNow() {
    subscribers++

    if (timer === null) {
        timer = setInterval(() => {
            now.value = Date.now()
        }, 60_000)
    }

    onBeforeUnmount(() => {
        subscribers--

        if (subscribers === 0 && timer !== null) {
            clearInterval(timer)
            timer = null
        }
    })

    return readonly(now)
}

/**
 * Сколько прошло с момента: «12 мин», «5 ч», «3 дн».
 *
 * Единица одна, самая крупная из подходящих. «3 дн 7 ч 12 мин» на
 * карточке канбана не читают — там важен порядок величины, а не точность.
 */
export function formatSince(iso, at = Date.now()) {
    if (!iso) return null

    const started = Date.parse(iso)

    if (Number.isNaN(started)) return null

    const minutes = Math.floor((at - started) / 60_000)

    if (minutes < 1) return 'только что'
    if (minutes < 60) return `${minutes} мин`

    const hours = Math.floor(minutes / 60)

    if (hours < 24) return `${hours} ч`

    const days = Math.floor(hours / 24)

    if (days < 30) return `${days} дн`

    const months = Math.floor(days / 30)

    return `${months} мес`
}

/**
 * Полная дата для подсказки — когда именно карточка попала на этап.
 */
export function formatMoment(iso) {
    if (!iso) return null

    const date = new Date(iso)

    if (Number.isNaN(date.getTime())) return null

    return date.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}