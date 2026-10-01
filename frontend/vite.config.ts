import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// A second checkout (a git worktree) needs a backend of its own, and both
// cannot hold port 8000. VITE_API_TARGET moves this one without editing the
// file in either tree.
const API_TARGET = process.env.VITE_API_TARGET ?? 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // `npm run dev` only. In production nginx serves the SPA and routes /api,
  // /mcp and /oauth to PHP, so the app talks to same-origin paths everywhere —
  // which left no way to run it locally at all, and is part of why the site
  // reached 2026-08-22 having never been looked at below 1200px wide.
  //
  // Point it at a local `php -S 127.0.0.1:8000 -t public` in the backend.
  // `changeOrigin: false` on purpose: the session cookie and the OAuth flows
  // care about the Host header, and rewriting it makes a local sign-in behave
  // unlike the real one.
  server: {
    proxy: {
      '/api': { target: API_TARGET, changeOrigin: false },
      '/mcp': { target: API_TARGET, changeOrigin: false },
      '/oauth': { target: API_TARGET, changeOrigin: false },
    },
  },
})
