import i18n from '../locales'
import { storage, session } from '../utils/storage'

function resolveBaseUrl() {
  // If running in browser on remote host (e.g. sanjgarva.vercel.app), NEVER use localhost / 127.0.0.1
  if (typeof window !== 'undefined' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
    return '/api'
  }

  // In production builds, ALWAYS force relative '/api' on the same domain
  if (import.meta.env.PROD) {
    return '/api'
  }

  // In local development, respect VITE_API_URL (e.g. http://127.0.0.1:8765/api)
  const devUrl = (import.meta.env.VITE_API_URL || '').trim()
  return (devUrl || '/api').replace(/\/+$/, '')
}

const BASE_URL = resolveBaseUrl()
const TOKEN_KEY = 'sg_token'

export const tokenStore = {
  get: () => storage.get(TOKEN_KEY) || session.get(TOKEN_KEY),
  set(token, remember) {
    this.clear()
    ;(remember ? storage : session).set(TOKEN_KEY, token)
  },
  clear() {
    storage.remove(TOKEN_KEY)
    session.remove(TOKEN_KEY)
  },
}

let unauthorizedHandler = () => {}
export function onUnauthorized(handler) {
  unauthorizedHandler = handler
}

export class ApiError extends Error {
  constructor({ status = 0, code = 'UNKNOWN', message, errors = null, meta = null }) {
    super(message || i18n.t('errors.generic'))
    this.status = status
    this.code = code
    this.errors = errors
    this.meta = meta
  }
}

function buildUrl(path, params) {
  let cleanPath = path.startsWith('/') ? path : `/${path}`
  // Prevent duplicate /api/api if both BASE_URL and path contain /api
  if (BASE_URL.endsWith('/api') && cleanPath.startsWith('/api/')) {
    cleanPath = cleanPath.slice(4)
  }
  const url = new URL(`${BASE_URL}${cleanPath}`, window.location.origin)
  if (params) {
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v)
    })
  }
  return url.toString()
}

async function request(method, path, { params, body, signal, raw = false } = {}) {
  const headers = { Accept: 'application/json', 'X-Locale': i18n.language }
  const token = tokenStore.get()
  if (token) headers.Authorization = `Bearer ${token}`

  let payload
  if (body instanceof FormData) payload = body
  else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  let res
  try {
    res = await fetch(buildUrl(path, params), { method, headers, body: payload, signal })
  } catch (err) {
    if (err.name === 'AbortError') throw err
    throw new ApiError({ code: 'NETWORK', message: i18n.t('errors.network') })
  }

  if (raw && res.ok) return res

  let json = null
  try {
    json = await res.json()
  } catch {
    /* non-JSON response */
  }

  if (!res.ok) {
    if (res.status === 401 && !path.startsWith('/auth/login')) unauthorizedHandler()
    throw new ApiError({
      status: res.status,
      code: json?.code || (res.status === 429 ? 'TOO_MANY' : 'HTTP_' + res.status),
      message: res.status >= 500 ? i18n.t('errors.generic') : json?.message,
      errors: json?.errors,
      meta: json?.meta,
    })
  }
  return json
}

export const api = {
  get: (path, params, opts) => request('GET', path, { params, ...opts }),
  post: (path, body, opts) => request('POST', path, { body, ...opts }),
  put: (path, body, opts) => request('PUT', path, { body, ...opts }),
  delete: (path, opts) => request('DELETE', path, opts),
  /** Authenticated file download (exports). */
  async download(path, params, fallbackName = 'export.csv') {
    const res = await request('GET', path, { params, raw: true })
    const blob = await res.blob()
    const disposition = res.headers.get('Content-Disposition') || ''
    const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] || fallbackName
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
}

/** First validation message per field: { field: 'message' } */
export function fieldErrors(error) {
  if (!error?.errors) return {}
  return Object.fromEntries(Object.entries(error.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]))
}
