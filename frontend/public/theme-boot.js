(() => {
  'use strict'

  const store = {
    get(key) {
      try {
        return localStorage.getItem(key)
      } catch {
        return null
      }
    },
    set(key, value) {
      try {
        localStorage.setItem(key, value)
      } catch {
      }
    },
    remove(key) {
      try {
        localStorage.removeItem(key)
      } catch {
      }
    },
  }

  const THEMES = ['light', 'dark']

  const getStoredTheme = () => {
    const stored = store.get('theme')
    return THEMES.includes(stored) ? stored : null
  }
  const setStoredTheme = theme => store.set('theme', theme)

  const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches

  const getPreferredTheme = () => {
    const storedTheme = getStoredTheme()
    if (storedTheme) {
      return storedTheme
    }
    return prefersDark() ? 'dark' : 'light'
  }

  const resolvedTheme = () => getStoredTheme() ?? (prefersDark() ? 'dark' : 'light')

  const setTheme = theme => {
    const applied = THEMES.includes(theme) ? theme : (prefersDark() ? 'dark' : 'light')
    document.documentElement.setAttribute('data-bs-theme', applied)
  }

  const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n))

  const hexToRgb = hex => [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
  ]

  const rgbToHex = ([r, g, b]) =>
    '#' + [r, g, b].map(v => clamp(Math.round(v), 0, 255).toString(16).padStart(2, '0')).join('')

  const rgbToHsl = ([r, g, b]) => {
    r /= 255; g /= 255; b /= 255
    const max = Math.max(r, g, b), min = Math.min(r, g, b)
    const l = (max + min) / 2
    if (max === min) return [0, 0, l]
    const d = max - min
    const s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
    let h
    if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6
    else if (max === g) h = ((b - r) / d + 2) / 6
    else h = ((r - g) / d + 4) / 6
    return [h, s, l]
  }

  const hslToRgb = ([h, s, l]) => {
    if (s === 0) return [l * 255, l * 255, l * 255]
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s
    const p = 2 * l - q
    const channel = t => {
      if (t < 0) t += 1
      if (t > 1) t -= 1
      if (t < 1 / 6) return p + (q - p) * 6 * t
      if (t < 1 / 2) return q
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6
      return p
    }
    return [channel(h + 1 / 3) * 255, channel(h) * 255, channel(h - 1 / 3) * 255]
  }

  const luminance = ([r, g, b]) => {
    const f = v => {
      v /= 255
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
    }
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
  }

  const contrast = (a, b) => {
    const la = luminance(a), lb = luminance(b)
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
  }

  const INK_LIGHT = [255, 255, 255]
  const INK_DARK = [16, 22, 31]

  const inkFor = bg => (contrast(bg, INK_LIGHT) >= contrast(bg, INK_DARK) ? INK_LIGHT : INK_DARK)

  const hoverOf = (h, s, l, theme) => {
    const hoverS = Math.min(0.95, s + 0.16 * clamp((s - 0.02) / 0.08, 0, 1))
    const deepened = l - Math.max(0.07, l * 0.16)
    const brighten = deepened < 0.06 || (theme === 'dark' && l < 0.45)
    return hslToRgb([h, hoverS, clamp(brighten ? l + (1 - l) * 0.22 : deepened, 0.06, 0.94)])
  }

  const accentTokens = (hex, theme) => {
    const rgb = hexToRgb(hex)
    const [h, s, l] = rgbToHsl(rgb)

    const fixed = FIXED_ACCENT[theme]
    const hover = fixed ? hexToRgb(fixed.hover) : hoverOf(h, s, l, theme)

    const floor = 0.38 * clamp((s - 0.04) / 0.16, 0, 1)
    const softS = clamp(Math.max(s, floor), 0, 0.85)
    const soft = hslToRgb([h, softS, fixed ? 0.68 : 0.78])

    const on = fixed ? hexToRgb(fixed.on) : inkFor(rgb)
    const onFill = rgbToHex(on).replace('#', '%23')
    const tick = `url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='none' stroke='${onFill}' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m6 10 3 3 6-6'/%3e%3c/svg%3e")`
    const dot = `url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='2' fill='${onFill}'/%3e%3c/svg%3e")`

    return {
      '--mm-check': tick,
      '--mm-radio': dot,
      '--mm-primary': hex,
      '--mm-primary-rgb': rgb.map(v => Math.round(v)).join(', '),
      '--mm-primary-hover': rgbToHex(hover),
      '--mm-primary-soft': rgbToHex(soft),
      '--mm-on-primary': rgbToHex(on),
      '--mm-on-primary-rgb': on.map(v => Math.round(v)).join(', '),
      '--mm-on-soft': rgbToHex(inkFor(soft)),
      '--mm-on-soft-rgb': inkFor(soft).map(v => Math.round(v)).join(', '),
    }
  }

  const PALETTES = {
    light: { bg: '#edf4f7', surface: '#fffefb', line: '#ccd9e0', lineStrong: '#b7c7d0', ink: '#20303c', inkSoft: '#607482', muted: '#7e909b', highlight: '#e4eef2', hover: '#eef6f8', tagBg: '#e6edf1', tagInk: '#4b5f6c', tagHover: '#d8e3e9', rowBg: '#fffefb', rowAlt: '#f7f8f6', inputBg: '#f8fbfc', selectBg: '#f8fbfc', placeholder: '#81919b', accent: '#1376a3', link: '#1376a3', note: '#20303c', chrome: '#f8fbfc', footer: '#eef3f5', rule: '#b7c7d0', tableHead: '#f1f0ea' },
    dark: { bg: '#101617', surface: '#1d2526', line: '#2d393b', lineStrong: '#344347', ink: '#ebe8df', inkSoft: '#b6c1c2', muted: '#849398', highlight: '#242e30', hover: '#213136', tagBg: '#293639', tagInk: '#d2dada', tagHover: '#314246', rowBg: '#1d2526', rowAlt: '#192123', inputBg: '#131a1c', selectBg: '#131a1c', placeholder: '#7a888d', accent: '#31a9d3', link: '#47c5ed', note: '#e3e0d7', chrome: '#171d1e', footer: '#171d1e', rule: '#344347', tableHead: '#242e30' },
  }

  const SKILL_COLORS = {
    light: { builtIn: ['#a9bfca', '#bfd1d8', '#92afbc', '#b1c9d1', '#9fbbc5'], personal: ['#167fa5', '#39988e', '#7b77b6', '#c39a52', '#5893b4', '#ab7c92'] },
    dark: { builtIn: ['#435e69', '#536c75', '#3b5662', '#49656e', '#405b65'], personal: ['#38a6cb', '#57b6a6', '#9991cd', '#cbaa6b', '#73a8c4', '#bd91a7'] },
  }

  const FIXED_ACCENT = {
    dark: { hover: '#08749b', on: '#f8fbfb' },
  }

  const APPEARANCE = {
    text: { key: 'mm-text-size', allowed: ['9', '10', '11', '12', '13', '14', '15'], fallback: '15' },
    font: { key: 'mm-md-font', attr: 'data-mm-font', allowed: ['sans', 'serif', 'mono'], fallback: 'sans' },
  }

  const THEMED = {
    accent: { key: t => `mm-accent-${t}`, field: 'accent' },
    link: { key: t => `mm-link-${t}`, field: 'link' },
    bodyText: { key: t => `mm-body-text-${t}`, field: 'note' },
  }

  const isHex = v => typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v)

  const readAppearance = which => {
    const spec = APPEARANCE[which]
    const stored = store.get(spec.key)
    return spec.allowed.includes(stored) ? stored : spec.fallback
  }

  const themedDefault = (which, theme) => PALETTES[theme][THEMED[which].field]

  const readThemed = (which, theme) => {
    const stored = store.get(THEMED[which].key(theme))
    return isHex(stored) ? stored.toLowerCase() : themedDefault(which, theme)
  }

  const applyAppearance = (which, value) => {
    if (which === 'text') {
      document.documentElement.style.setProperty('--mm-note-size', `${value}px`)
      return
    }
    document.documentElement.setAttribute(APPEARANCE[which].attr, value)
  }

  const applyThemed = () => {
    const theme = resolvedTheme()
    const root = document.documentElement.style

    const tokens = accentTokens(readThemed('accent', theme), theme)
    for (const [prop, value] of Object.entries(tokens)) root.setProperty(prop, value)

    const skillColors = SKILL_COLORS[theme]
    skillColors.builtIn.forEach((color, index) => root.setProperty(`--mm-skill-built-in-${index}`, color))
    skillColors.personal.forEach((color, index) => root.setProperty(`--mm-skill-${index}`, color))

    const palette = PALETTES[theme]
    root.setProperty('--mm-bg', palette.bg)
    root.setProperty('--mm-surface', palette.surface)
    root.setProperty('--mm-chrome', palette.chrome)
    root.setProperty('--mm-footer', palette.footer)
    root.setProperty('--mm-line', palette.line)
    root.setProperty('--mm-line-strong', palette.lineStrong)
    root.setProperty('--mm-rule', palette.rule)
    root.setProperty('--mm-ink', palette.ink)
    root.setProperty('--mm-ink-soft', palette.inkSoft)
    root.setProperty('--mm-body-ink', readThemed('bodyText', theme))
    root.setProperty('--mm-muted', palette.muted)
    root.setProperty('--mm-highlight', palette.highlight)
    root.setProperty('--mm-hover', palette.hover)
    root.setProperty('--mm-tag-bg', palette.tagBg)
    root.setProperty('--mm-on-tag', palette.tagInk)
    root.setProperty('--mm-tag-hover', palette.tagHover)
    root.setProperty('--mm-table-head', palette.tableHead)
    root.setProperty('--mm-row-bg', palette.rowBg)
    root.setProperty('--mm-row-alt', palette.rowAlt)
    root.setProperty('--mm-input-bg', palette.inputBg)
    root.setProperty('--mm-select-bg', palette.selectBg)
    root.setProperty('--mm-placeholder', palette.placeholder)

    const linkHex = readThemed('link', theme)
    const linkRgb = hexToRgb(linkHex)
    const [lh, ls, ll] = rgbToHsl(linkRgb)
    root.setProperty('--mm-link', linkHex)
    root.setProperty('--mm-link-rgb', linkRgb.map(v => Math.round(v)).join(', '))
    root.setProperty('--mm-link-hover', rgbToHex(hoverOf(lh, ls, ll, theme)))
  }

  for (const theme of THEMES) {
    store.remove(`mm-surface-${theme}`)
    store.remove(`mm-muted-${theme}`)
    for (const which of Object.keys(THEMED)) store.remove(THEMED[which].key(theme))
  }

  setTheme(getPreferredTheme())
  applyAppearance('text', readAppearance('text'))
  applyAppearance('font', readAppearance('font'))
  applyThemed()

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (getStoredTheme()) return
    setTheme('auto')
    applyThemed()
  })

  window.getResolvedTheme = resolvedTheme

  window.setPreferredTheme = theme => {
    if (!THEMES.includes(theme)) return
    setStoredTheme(theme)
    setTheme(theme)
    applyThemed()
  }

  window.getAppearance = which => readAppearance(which)

  window.setAppearance = (which, value) => {
    const spec = APPEARANCE[which]
    if (!spec || !spec.allowed.includes(value)) {
      return
    }
    store.set(spec.key, value)
    applyAppearance(which, value)
  }

  window.getThemed = (which, theme) => readThemed(which, theme || resolvedTheme())

  window.setThemed = (which, theme, value) => {
    const spec = THEMED[which]
    if (!spec || (theme !== 'light' && theme !== 'dark') || !isHex(value)) {
      return false
    }
    store.set(spec.key(theme), value.toLowerCase())
    applyThemed()
    return true
  }

  window.resetThemed = () => {
    for (const which of Object.keys(THEMED)) {
      for (const theme of ['light', 'dark']) store.remove(THEMED[which].key(theme))
    }
    applyThemed()
  }

  window.themePalette = theme => Object.assign({}, PALETTES[theme])

  window.inkOn = hex => (isHex(hex) ? rgbToHex(inkFor(hexToRgb(hex))) : null)

  window.hoverOn = (hex, theme) => {
    if (!isHex(hex)) return null
    const [h, s, l] = rgbToHsl(hexToRgb(hex))
    return rgbToHex(hoverOf(h, s, l, theme === 'dark' ? 'dark' : 'light'))
  }

  window.adoptAppearance = prefs => {
    if (!prefs || typeof prefs !== 'object') return
    for (const which of Object.keys(THEMED)) {
      for (const theme of THEMES) store.remove(THEMED[which].key(theme))
    }
    applyThemed()
  }

  window.accentContrast = (hex, theme) => {
    const t = theme || resolvedTheme()
    if (!isHex(hex)) return null
    const rgb = hexToRgb(hex)
    const palette = PALETTES[t]

    return {
      page: Math.min(
        ...[palette.bg, palette.rowAlt, palette.highlight].map(fill => contrast(rgb, hexToRgb(fill)))
      ),
      ink: contrast(rgb, inkFor(rgb)),
    }
  }
})()
