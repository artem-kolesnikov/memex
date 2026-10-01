import { watch } from 'vue'
import { i18n } from '@/i18n'
import { sessionEpoch, StaleOperationError } from '@/lib/operationLifetime'

/**
 * The Your space step's save queue, outside the component on purpose: the
 * step unmounts on pause and remounts on resume, and a second queue started
 * while the first still has a request out can land a newer value before an
 * older one. One queue per page, and the value each field last sent, survive
 * the component.
 */
let chain: Promise<void> = Promise.resolve()

/** The value each field last sent and not yet answered. */
export const sent = { name: null as string | null, memex: null as string | null }
/** The value each field last sent that the server accepted, as it was typed. */
export const saved = { name: null as string | null, memex: null as string | null }

export function enqueue(job: () => Promise<void>): Promise<void> {
  const epoch = sessionEpoch.value
  const run = () => {
    if (epoch !== sessionEpoch.value) throw new StaleOperationError(i18n.global.t('common.session_changed'))
    return job()
  }
  const next = chain.then(run, run)
  chain = next.catch(() => undefined)
  return next
}

export function settled(): Promise<void> {
  return chain
}

watch(sessionEpoch, () => {
  chain = Promise.resolve()
  sent.name = sent.memex = saved.name = saved.memex = null
}, { flush: 'sync' })
