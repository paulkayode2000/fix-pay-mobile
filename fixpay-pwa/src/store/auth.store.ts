import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { User } from '@/types'

interface AuthState {
  /**
   * In-memory access token.
   * NOT stored in localStorage — the real backend delivers it via an
   * httpOnly cookie. This field acts as a session-scoped cache so the
   * Axios interceptor can still attach Authorization headers in mock mode
   * (where httpOnly cookies aren't available from MSW).
   * It is cleared on logout and NOT written to disk.
   */
  token: string | null
  user: User | null
  isAuthenticated: boolean
  pinCreated: boolean
  kycCompleted: boolean
  kycDeferred: boolean
  /**
   * Whether the user has an ACTIVE wallet. Wallets are OPTIONAL — the account
   * can pay bills through the Payfixy gateway rail without one — so the UI must
   * never assume a wallet exists. Derived from the server (`has_wallet`).
   */
  hasWallet: boolean
  pendingPhone: string | null
  pendingEmail: string | null
  /** True once zustand-persist has finished reading from localStorage */
  _hasHydrated: boolean
  setToken: (token: string) => void
  setUser: (user: User) => void
  setPinCreated: (v: boolean) => void
  setKycCompleted: (v: boolean) => void
  setKycDeferred: (v: boolean) => void
  setHasWallet: (v: boolean) => void
  setPending: (phone?: string, email?: string) => void
  logout: () => void
  setHasHydrated: (v: boolean) => void
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      isAuthenticated: false,
      pinCreated: false,
      kycCompleted: false,
      kycDeferred: false,
      hasWallet: false,
      pendingPhone: null,
      pendingEmail: null,
      _hasHydrated: false,
      setToken: (token) => set({ token, isAuthenticated: true }),
      setUser: (user) => set({ user }),
      setPinCreated: (v) => set({ pinCreated: v }),
      setKycCompleted: (v) => set({ kycCompleted: v }),
      setKycDeferred: (v) => set({ kycDeferred: v }),
      setHasWallet: (v) => set({ hasWallet: v }),
      setPending: (phone, email) => set({ pendingPhone: phone ?? null, pendingEmail: email ?? null }),
      logout: () => set({ token: null, user: null, isAuthenticated: false, pinCreated: false, kycCompleted: false, hasWallet: false }),
      setHasHydrated: (v) => set({ _hasHydrated: v }),
    }),
    {
      name: 'fixpay-auth',
      // _hasHydrated is memory-only — never written to localStorage.
      partialize: (state) => ({
        token: state.token,
        user: state.user,
        isAuthenticated: state.isAuthenticated,
        pinCreated: state.pinCreated,
        kycCompleted: state.kycCompleted,
        kycDeferred: state.kycDeferred,
        hasWallet: state.hasWallet,
        pendingPhone: state.pendingPhone,
        pendingEmail: state.pendingEmail,
      }),
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true)
      },
    }
  )
)
