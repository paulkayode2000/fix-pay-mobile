import { createBrowserRouter, RouterProvider, Navigate, Outlet, useLocation } from 'react-router-dom'
import { lazy, Suspense, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useTenantStore } from '@/store/tenant.store'
import { useAuthStore } from '@/store/auth.store'
import type { TenantConfig, ApiResponse } from '@/types'

// Layout (eager — always rendered, tiny)
import { AppShell } from '@/components/layout/AppShell'
import { RouteErrorBoundary } from '@/components/layout/RouteErrorBoundary'
import { DuplicatePaymentModal } from '@/components/feature/DuplicatePaymentModal'

// Auth
const SplashScreen       = lazy(() => import('@/modules/auth/SplashScreen').then(m => ({ default: m.SplashScreen })))
const WelcomeScreen      = lazy(() => import('@/modules/auth/WelcomeScreen').then(m => ({ default: m.WelcomeScreen })))
const RegisterScreen     = lazy(() => import('@/modules/auth/RegisterScreen').then(m => ({ default: m.RegisterScreen })))
const LoginScreen        = lazy(() => import('@/modules/auth/LoginScreen').then(m => ({ default: m.LoginScreen })))
const OtpScreen          = lazy(() => import('@/modules/auth/OtpScreen').then(m => ({ default: m.OtpScreen })))
const CreatePinScreen    = lazy(() => import('@/modules/auth/CreatePinScreen').then(m => ({ default: m.CreatePinScreen })))

// KYC
const KycStepper = lazy(() => import('@/modules/kyc/KycStepper').then(m => ({ default: m.KycStepper })))

// Home
const HomeScreen = lazy(() => import('@/modules/home/HomeScreen').then(m => ({ default: m.HomeScreen })))

// Payments
const PaymentsScreen    = lazy(() => import('@/modules/payments/PaymentsScreen').then(m => ({ default: m.PaymentsScreen })))
const AirtimeScreen     = lazy(() => import('@/modules/payments/AirtimeScreen').then(m => ({ default: m.AirtimeScreen })))
const DataScreen        = lazy(() => import('@/modules/payments/DataScreen').then(m => ({ default: m.DataScreen })))
const TvScreen          = lazy(() => import('@/modules/payments/TvScreen').then(m => ({ default: m.TvScreen })))
const ElectricityScreen = lazy(() => import('@/modules/payments/ElectricityScreen').then(m => ({ default: m.ElectricityScreen })))
const EducationScreen   = lazy(() => import('@/modules/payments/EducationScreen').then(m => ({ default: m.EducationScreen })))
const InsuranceScreen   = lazy(() => import('@/modules/payments/InsuranceScreen').then(m => ({ default: m.InsuranceScreen })))
const ReceiptScreen     = lazy(() => import('@/modules/payments/ReceiptScreen').then(m => ({ default: m.ReceiptScreen })))
const PendingScreen     = lazy(() => import('@/modules/payments/PendingScreen').then(m => ({ default: m.PendingScreen })))

// Send
const SendScreen = lazy(() => import('@/modules/send/SendScreen').then(m => ({ default: m.SendScreen })))

// Wallet
const WalletScreen            = lazy(() => import('@/modules/wallet/WalletScreen').then(m => ({ default: m.WalletScreen })))
const FundWalletScreen        = lazy(() => import('@/modules/wallet/FundWalletScreen').then(m => ({ default: m.FundWalletScreen })))
const TransactionDetailScreen = lazy(() => import('@/modules/wallet/TransactionDetailScreen').then(m => ({ default: m.TransactionDetailScreen })))
const NinePsbOnboardingScreen = lazy(() => import('@/modules/wallet/NinePsbOnboardingScreen').then(m => ({ default: m.NinePsbOnboardingScreen })))

// More
const MoreScreen          = lazy(() => import('@/modules/more/MoreScreen').then(m => ({ default: m.MoreScreen })))
const ProfileScreen       = lazy(() => import('@/modules/more/ProfileScreen').then(m => ({ default: m.ProfileScreen })))
const SecurityScreen      = lazy(() => import('@/modules/more/SecurityScreen').then(m => ({ default: m.SecurityScreen })))
const MandatesScreen      = lazy(() => import('@/modules/more/MandatesScreen').then(m => ({ default: m.MandatesScreen })))
const DisputesScreen      = lazy(() => import('@/modules/more/DisputesScreen').then(m => ({ default: m.DisputesScreen })))
const DisputeDetailScreen = lazy(() => import('@/modules/more/DisputeDetailScreen').then(m => ({ default: m.DisputeDetailScreen })))
const RaiseDisputeScreen  = lazy(() => import('@/modules/more/RaiseDisputeScreen').then(m => ({ default: m.RaiseDisputeScreen })))
const AnalyticsScreen     = lazy(() => import('@/modules/more/AnalyticsScreen').then(m => ({ default: m.AnalyticsScreen })))

// ─── Guards ────────────────────────────────────────────────────────────────

function RequireAuth() {
  const { isAuthenticated, pinCreated, _hasHydrated } = useAuthStore()
  const { pathname } = useLocation()

  // Block all routing decisions until Zustand has rehydrated from localStorage.
  if (!_hasHydrated) return null

  // Always show splash on first page load of a new browser session
  if (!sessionStorage.getItem('splash_shown')) {
    return <Navigate to="/splash" replace />
  }

  if (!isAuthenticated) return <Navigate to="/auth/login" replace />
  if (!pinCreated)      return <Navigate to="/auth/pin"   replace />

  // KYC is now voluntary. Users can freely use the app without verification.
  // KYC is only required when accessing wallet creation or high-limit features.
  return <Outlet />
}

// ─── Tenant theme applier ──────────────────────────────────────────────────

function TenantLoader() {
  const { setConfig } = useTenantStore()
  const tenantSlug = localStorage.getItem('tenant_slug')
  const { data } = useQuery<TenantConfig>({
    queryKey: ['tenant-config', tenantSlug],
    queryFn: () =>
      api
        .get<ApiResponse<TenantConfig>>('/tenant/config', {
          headers: tenantSlug ? { 'X-Tenant-Slug': tenantSlug } : {},
        })
        .then(r => r.data.data),
    staleTime: 5 * 60 * 1000,
    retry: false,
  })
  useEffect(() => { if (data) setConfig(data) }, [data, setConfig])
  return null
}

// ─── Router ───────────────────────────────────────────────────────────────

const router = createBrowserRouter([
  {
    element: <Outlet />,
    errorElement: <RouteErrorBoundary />,
    children: [
      { path: '/splash',         element: <SplashScreen /> },
      { path: '/welcome',        element: <WelcomeScreen /> },
      { path: '/auth/register',  element: <RegisterScreen /> },
      { path: '/auth/login',     element: <LoginScreen /> },
      { path: '/auth/otp',       element: <OtpScreen /> },
      { path: '/auth/pin',       element: <CreatePinScreen /> },
      {
        path: '/',
        element: <RequireAuth />,
        children: [
          {
            element: <AppShell />,
            children: [
              { index: true,           element: <Navigate to="/home" replace /> },
              { path: 'home',          element: <HomeScreen /> },
              { path: 'payments',      element: <PaymentsScreen /> },
              { path: 'send',          element: <SendScreen /> },
              { path: 'wallet',        element: <WalletScreen /> },
              { path: 'more',          element: <MoreScreen /> },
              { path: 'kyc',                   element: <KycStepper /> },
              { path: 'payments/airtime',      element: <AirtimeScreen /> },
              { path: 'payments/data',         element: <DataScreen /> },
              { path: 'payments/tv',           element: <TvScreen /> },
              { path: 'payments/electricity',  element: <ElectricityScreen /> },
              { path: 'payments/education',    element: <EducationScreen /> },
              { path: 'payments/insurance',    element: <InsuranceScreen /> },
              { path: 'payments/receipt',      element: <ReceiptScreen /> },
              { path: 'payments/pending',      element: <PendingScreen /> },
              { path: 'wallet/fund',                element: <FundWalletScreen /> },
              { path: 'wallet/transactions/:id',  element: <TransactionDetailScreen /> },
              { path: 'ninepsb/onboarding', element: <NinePsbOnboardingScreen /> },
              { path: 'more/profile',             element: <ProfileScreen /> },
              { path: 'more/security',            element: <SecurityScreen /> },
              { path: 'more/mandates',         element: <MandatesScreen /> },
              { path: 'more/disputes',         element: <DisputesScreen /> },
              { path: 'more/disputes/new',     element: <RaiseDisputeScreen /> },
              { path: 'more/disputes/:id',     element: <DisputeDetailScreen /> },
              { path: 'more/analytics',        element: <AnalyticsScreen /> },
            ],
          },
        ],
      },
      { path: '*', element: <Navigate to="/splash" replace /> },
    ]
  }
])

// ─── App ──────────────────────────────────────────────────────────────────

const PageLoader = () => (
  <div className="flex items-center justify-center h-screen">
    <div className="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin" />
  </div>
)

export default function App() {
  return (
    <Suspense fallback={<PageLoader />}>
      <TenantLoader />
      <RouterProvider router={router} />
      <DuplicatePaymentModal />
    </Suspense>
  )
}