import { useEffect } from 'react'
import { createBrowserRouter, RouterProvider, Navigate, Outlet } from 'react-router-dom'
import { useAdminAuthStore } from '@/store/auth.store'

// Layout
import { AdminShell } from '@/components/layout/AdminShell'

// Rail management (migrated from PWA admin module)
import { AdminDashboard } from '@/modules/rails/AdminDashboard'

// Tenant management
import { TenantListScreen }       from '@/modules/tenants/TenantListScreen'
import { TenantDetailScreen }     from '@/modules/tenants/TenantDetailScreen'
import { TenantOnboardingWizard } from '@/modules/tenants/TenantOnboardingWizard'

// User management
import { AdminUserManagementScreen } from '@/modules/users/AdminUserManagementScreen'

// Transactions
import { TransactionLedgerScreen } from '@/modules/transactions/TransactionLedgerScreen'
import { TransactionDetailAdminScreen } from '@/modules/transactions/TransactionDetailAdminScreen'

// Settlement
import { SettlementDashboard } from '@/modules/settlement/SettlementDashboard'

// Analytics
import { AnalyticsDashboard } from '@/modules/analytics/AnalyticsDashboard'

// Compliance
import { KycReviewQueue }  from '@/modules/compliance/KycReviewQueue'
import { AmlMonitoringScreen } from '@/modules/compliance/AmlMonitoringScreen'

// System
import { SystemHealthScreen } from '@/modules/system/SystemHealthScreen'

// Fraud
import { FraudRulesScreen } from '@/modules/risk/FraudRulesScreen'
import { FraudCasesScreen } from '@/modules/risk/FraudCasesScreen'

// Risk alerts (TMS AML/antifraud flags)
import { AlertsScreen } from '@/modules/alerts/AlertsScreen'

// ─── Initialisation gate ───────────────────────────────────────────────────

import { LoginScreen } from '@/modules/auth/LoginScreen'

function AppInit() {
  const { init, isInitialised, isAuthenticated } = useAdminAuthStore()

  useEffect(() => { void init() }, [init])

  if (!isInitialised) {
    return (
      <div className="flex items-center justify-center h-screen bg-slate-50">
        <div className="text-slate-500 text-sm">Authenticating…</div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <LoginScreen />
  }

  return <Outlet />
}

// ─── Router ───────────────────────────────────────────────────────────────

const router = createBrowserRouter([
  {
    element: <AppInit />,
    children: [
      {
        path: '/',
        element: <AdminShell />,
        children: [
          { index: true,                         element: <Navigate to="/analytics" replace /> },
          { path: 'analytics',                   element: <AnalyticsDashboard /> },

          // Risk alerts (TMS AML/antifraud)
          { path: 'alerts',                      element: <AlertsScreen /> },

          // Tenants
          { path: 'tenants',                     element: <TenantListScreen /> },
          { path: 'tenants/new',                 element: <TenantOnboardingWizard /> },
          { path: 'tenants/:id',                 element: <TenantDetailScreen /> },

          // Users
          { path: 'users',                       element: <AdminUserManagementScreen /> },

          // Transactions
          { path: 'transactions',                element: <TransactionLedgerScreen /> },
          { path: 'transactions/:id',            element: <TransactionDetailAdminScreen /> },

          // Settlement
          { path: 'settlement',                  element: <SettlementDashboard /> },

          // Rails & processors (migrated from PWA)
          { path: 'rails',                       element: <AdminDashboard /> },
          { path: 'rails/*',                     element: <AdminDashboard /> },

          // Compliance
          { path: 'compliance/kyc',              element: <KycReviewQueue /> },
          { path: 'compliance/aml',              element: <AmlMonitoringScreen /> },

          // Risk & Fraud
          { path: 'risk/rules',                  element: <FraudRulesScreen /> },
          { path: 'risk/cases',                  element: <FraudCasesScreen /> },

          // System
          { path: 'system',                      element: <SystemHealthScreen /> },

          { path: '*',                           element: <Navigate to="/analytics" replace /> },
        ],
      },
    ],
  },
], { basename: '/fixpay-admin' })

export default function App() {
  return <RouterProvider router={router} />
}
