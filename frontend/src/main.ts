// mm2 stack: Bootstrap 5 (layout/nav) + PrimeVue 3 saga-blue (rich inputs) +
// FontAwesome icons — all self-hosted (no CDN; the box serves everything).
// Inter, self-hosted. It has been named in --mm-font-sans since the mm2
// restyle and never shipped, so every screen fell through to the platform's
// own face. The CSP is `font-src 'self' data:`, which is why this is a
// dependency rather than a Google Fonts link.
import '@fontsource-variable/inter'
import 'bootstrap/dist/css/bootstrap.min.css'
import '@fortawesome/fontawesome-free/css/all.min.css'
import './assets/main.css'
import './assets/app.css'
import 'primevue/resources/themes/saga-blue/theme.css'
import 'primevue/resources/primevue.min.css'
import 'primeicons/primeicons.css'

// The dist bundle (Popper included) — exposed on window for programmatic
// Modal/Dropdown/Tooltip use in views, matching mm2's global-bootstrap idiom.
// @ts-expect-error UMD bundle without type declarations
import bootstrap from 'bootstrap/dist/js/bootstrap.bundle.min.js'
;(window as unknown as { bootstrap: unknown }).bootstrap = bootstrap

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'

import App from './App.vue'
import router from './router'
import { BUNDLED, i18n, setLocale, storedLocale } from './i18n'
import { editions } from './editions'

export const app = createApp(App)

app.use(createPinia())
for (const route of editions.flatMap((edition) => edition.routes ?? [])) router.addRoute(route)
app.use(router)
app.use(PrimeVue)
app.use(ToastService)
app.use(i18n)
for (const edition of editions) edition.install?.(app, router)

// The language a returning person chose, fetched before the first paint so the
// sign-in page speaks it too. A file that cannot be fetched leaves English.
const boot = storedLocale()
const ready = boot === BUNDLED ? Promise.resolve() : setLocale(boot).catch(() => undefined)
ready.then(() => app.mount('#app'))
