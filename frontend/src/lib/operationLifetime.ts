import { onBeforeUnmount, ref } from 'vue'

export const sessionEpoch = ref(0)
export const invalidateSession = () => ++sessionEpoch.value

export class StaleOperationError extends Error {
  constructor(message: string) { super(message) }
}

export function useOperationLifetime() {
  let generation = 0
  let active = true
  const invalidate = () => ++generation
  const capture = () => ({ generation, session: sessionEpoch.value })
  const current = (token: ReturnType<typeof capture>) =>
    active && token.generation === generation && token.session === sessionEpoch.value
  onBeforeUnmount(() => { active = false; invalidate() })
  return { capture, current, invalidate }
}
