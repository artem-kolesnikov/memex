// The owner's endpoints. Plain fetch: their failures are codes this edition
// words, and a wrong password's 401 must not send the screen to sign-in.
export interface OwnerAnswer {
  ok: boolean
  status: number
  data: { error?: string; redirect?: string; owner?: boolean; code?: boolean }
}

export async function owner(method: string, path: string, body?: Record<string, unknown>): Promise<OwnerAnswer> {
  const res = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  let data: OwnerAnswer['data'] = {}
  try {
    data = await res.json()
  } catch {
    data = {}
  }
  return { ok: res.ok, status: res.status, data }
}
