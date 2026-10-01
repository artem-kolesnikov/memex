import { app } from '@/main'

const lifeTime = 3000

export function toastSuccess(title: string, body: string = ''): void {
  app.config.globalProperties.$toast.add({
    severity: 'success',
    summary: title,
    detail: body,
    life: lifeTime,
  })
}

/**
 * Stays until it is dismissed (operator, 2026-09-02).
 *
 * Omitting `life` is what makes a PrimeVue toast sticky. An error is usually
 * something to read and act on, and often something to copy into a report — a
 * timer on it means the one message explaining what went wrong is the one
 * message that disappears while it is being read.
 */
export function toastError(title: string, body: string = ''): void {
  app.config.globalProperties.$toast.add({
    severity: 'error',
    summary: title,
    detail: body,
  })
}
