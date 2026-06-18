import { create } from 'zustand'
import { api } from '@/lib/api'

export type AdminRole =
  | 'PLATFORM_ADMIN'
  | 'SUPPORT_AGENT'
  | 'COMPLIANCE_OFFICER'
  | 'FINANCE_OPS'

interface AdminAuthState {
  isAuthenticated: boolean
  isInitialised: boolean
  username: string
  email: string
  roles: AdminRole[]
  init: (force?: boolean) => Promise<void>
  login: (credentials: { email: string; password: string }) => Promise<void>
  logout: () => Promise<void>
  hasRole: (role: AdminRole) => boolean
  hasAnyRole: (...roles: AdminRole[]) => boolean
}

export const useAdminAuthStore = create<AdminAuthState>((set, get) => ({
  isAuthenticated: false,
  isInitialised: false,
  username: '',
  email: '',
  roles: [],

  init: async (force = false) => {
    if (get().isInitialised && !force) return

    try {
      // Fetch CSRF cookie to ensure session works
      await api.get('/sanctum/csrf-cookie', { baseURL: '' })
      
      // Try fetching current user profile
      const { data } = await api.get('/admin/profile')
      
      set({
        isAuthenticated: true,
        isInitialised: true,
        username: data.name ?? '',
        email: data.email ?? '',
        roles: data.roles ?? ['PLATFORM_ADMIN'],
      })
    } catch (e) {
      set({ isInitialised: true, isAuthenticated: false })
      // Suppress throw on initial load to avoid console errors
    }
  },

  login: async (credentials) => {
    await api.get('/sanctum/csrf-cookie', { baseURL: '' })
    await api.post('/auth/login', {
      identifier: credentials.email,
      password: credentials.password
    })
    await get().init(true)
  },

  logout: async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Ignore errors on logout
    } finally {
      set({
        isAuthenticated: false,
        username: '',
        email: '',
        roles: [],
      })
    }
  },

  hasRole: (role) => get().roles.includes(role),
  hasAnyRole: (...roles) => roles.some(r => get().roles.includes(r)),
}))
