import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError, type MapPrefs, type Me } from '@/api/client'
import { sessionEpoch, invalidateSession, StaleOperationError } from '@/lib/operationLifetime'
import { BUNDLED, currentLocale, setLocale, i18n } from '@/i18n'

// The account's language wins over the browser's memory of it, and a choice
// the operator has since uninstalled comes back as English and is applied.
// Nobody signed in changes nothing: the sign-in page keeps the remembered one.
function adoptLocale(me: Me | null) {
  if (!me) return
  const wanted = me.locale ?? BUNDLED
  if (wanted !== currentLocale()) void setLocale(wanted).catch(() => undefined)
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<Me | null>(null)
  const checked = ref(false)
  /** The server refused this account as suspended, so sign-in says why. */
  const suspended = ref(false)
  const loggingOut = ref(false)
  const logoutError = ref<string | null>(null)
  let checkRequest = 0

  async function writeUser(operation: () => Promise<Me>): Promise<void> {
    const epoch = sessionEpoch.value
    const result = await operation()
    if (epoch !== sessionEpoch.value) throw new StaleOperationError(i18n.global.t('common.session_changed'))
    user.value = result
  }

  async function check(): Promise<boolean> {
    const epoch = sessionEpoch.value
    const request = ++checkRequest
    const current = () => epoch === sessionEpoch.value && request === checkRequest
    try {
      const result = await api.me()
      if (!current()) return user.value !== null
      if (result.team.handle !== user.value?.team.handle) invalidateSession()
      user.value = result
      suspended.value = false
    } catch (e) {
      if (!current() || e instanceof StaleOperationError) return user.value !== null
      invalidateSession()
      user.value = null
      suspended.value = e instanceof ApiError && e.data.code === 'suspended'
    }
    checked.value = true
    // Deliberately outside the catch above. Adopting is a CACHE write, and
    // localStorage throws on a full quota — inside, that turned a valid session
    // into user.value = null and the router bounced it to /login, on every load.
    if (user.value) window.adoptAppearance?.(user.value.appearance)
    adoptLocale(user.value)
    return user.value !== null
  }

  /** Renaming has to write back into the store, because the name is in the
   *  nav on every screen and a stale one there reads as a failed save. */
  async function rename(name: string): Promise<void> {
    await writeUser(() => api.updateName(name))
  }

  /** The face, for the same reason: it is in the nav and on every byline. */
  async function uploadPicture(file: File): Promise<void> {
    await writeUser(() => api.uploadPicture(file))
  }

  async function clearPicture(): Promise<void> {
    await writeUser(() => api.clearPicture())
  }

  /** The knowledge base's name, for the reason renaming a person is: it is
   *  what connected assistants are told they are writing to. */
  async function renameMemex(name: string): Promise<void> {
    await writeUser(() => api.updateMemexName(name))
  }

  /** One map control, written through and kept on the account in memory. */
  async function chooseMapSetting(patch: MapPrefs | Record<string, null>): Promise<void> {
    const epoch = sessionEpoch.value
    const { map } = await api.updateMapSettings(patch)
    if (epoch !== sessionEpoch.value) throw new StaleOperationError(i18n.global.t('common.session_changed'))
    if (user.value !== null) user.value = { ...user.value, map }
  }

  async function chooseLocale(code: string): Promise<void> {
    await writeUser(() => api.updateLocale(code))
    await setLocale(user.value?.locale ?? BUNDLED)
  }

  /** Leaving. There is nothing to write back — the account this store holds
   *  does not exist afterwards, and the caller reloads onto /login. */
  async function deleteAccount(confirmEmail: string): Promise<void> {
    const epoch = invalidateSession()
    await api.deleteAccount(confirmEmail)
    if (epoch !== sessionEpoch.value) throw new StaleOperationError(i18n.global.t('common.session_changed'))
    user.value = null
    invalidateSession()
  }

  async function logout(): Promise<boolean> {
    if (loggingOut.value) return false
    const epoch = invalidateSession()
    loggingOut.value = true
    logoutError.value = null
    try {
      await api.logout()
    } catch (e) {
      if (epoch !== sessionEpoch.value) return false
      if (!(e instanceof ApiError && e.status === 401)) {
        logoutError.value = e instanceof Error ? e.message : 'Sign out could not be confirmed.'
        return false
      }
    } finally {
      loggingOut.value = false
    }
    if (epoch !== sessionEpoch.value) return false
    user.value = null
    invalidateSession()
    return true
  }

  return { user, checked, suspended, loggingOut, logoutError, check, rename, uploadPicture, clearPicture, renameMemex, chooseMapSetting, chooseLocale, deleteAccount, logout }
})
