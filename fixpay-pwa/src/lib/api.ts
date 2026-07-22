import axios from 'axios'
import { useAuthStore } from '@/store/auth.store'
import { purgeDbKey } from '@/lib/crypto'
import { clearTransactions } from '@/lib/db'
import { useDuplicatePaymentStore } from '@/store/duplicatePayment.store'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  timeout: 30_000,
  withCredentials: true,
  headers: { 'Content-Type': 'application/json' },
})

const idempotencyCache = new Map<string, { key: string, expiry: number }>()

function generateUUID() {
  if (typeof self !== 'undefined' && self.crypto && self.crypto.randomUUID) {
    return self.crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    const r = Math.random() * 16 | 0;
    const v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

api.interceptors.request.use(async (config) => {
  const token = useAuthStore.getState().token
  if (token) config.headers.Authorization = `Bearer ${token}`
  const fp = localStorage.getItem('device_fp')
  if (fp) config.headers['X-Device-Fingerprint'] = fp

  if (config.method && ['post', 'put', 'patch', 'delete'].includes(config.method.toLowerCase())) {
    if (!config.headers['X-Idempotency-Key']) {
      let dataStr = ''
      if (typeof config.data === 'string') {
        dataStr = config.data
      } else if (config.data && typeof config.data === 'object') {
        try { dataStr = JSON.stringify(config.data) } catch { dataStr = 'unserializable' }
      }

      const sig = `${config.method.toLowerCase()}:${config.url}:${dataStr}`
      const now = Date.now()

      for (const [k, v] of idempotencyCache.entries()) {
        if (v.expiry < now) idempotencyCache.delete(k)
      }

      const isPayment = config.url?.includes('/payments') || config.url?.includes('/transfers')
      const isFavourite = config.url?.includes('/favourites')

      let key = ''
      const existing = idempotencyCache.get(sig)

      if (isPayment && existing && existing.expiry > now) {
        const proceed = await useDuplicatePaymentStore.getState().showWarning("You recently made this exact payment. Are you sure you want to duplicate it?")
        if (!proceed) {
          return Promise.reject(new Error("DuplicatePaymentCancelled"))
        }
        key = generateUUID()
        idempotencyCache.set(sig, { key, expiry: now + 60000 })
      } else if (existing && existing.expiry > now) {
        key = existing.key
      } else {
        key = generateUUID()
        const duration = isPayment ? 60000 : (isFavourite ? 30000 : 5000)
        idempotencyCache.set(sig, { key, expiry: now + duration })
      }

      config.headers['X-Idempotency-Key'] = key
    }
  }

  const tenantSlug = localStorage.getItem('tenant_slug')
  if (tenantSlug) config.headers['X-Tenant-Slug'] = tenantSlug
  return config
})

api.interceptors.response.use(
  r => r,
  async (err) => {
    const orig = err.config
    if (err.response?.status === 401 && !orig._retry) {
      orig._retry = true

      const { token, isAuthenticated } = useAuthStore.getState()
      if (!token && !isAuthenticated) return Promise.reject(err)

      try {
        await serverLogout()
      } finally {
        if (!window.location.pathname.startsWith('/auth')) {
          window.location.href = '/auth/login'
        }
      }
    }
    return Promise.reject(err)
  }
)

export async function serverLogout(): Promise<void> {
  try {
    await axios.post('/api/auth/logout', {}, { withCredentials: true })
  } catch {
    // best-effort
  }
  localStorage.removeItem('tenant_slug')
  purgeDbKey()
  await clearTransactions()
  useAuthStore.getState().logout()
}

export { api }
export default api