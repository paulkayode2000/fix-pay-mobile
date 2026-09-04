import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAdminAuthStore } from '@/store/auth.store'
import { cn } from '@/lib/utils'
import {
  ChartBarIcon,
  BuildingOffice2Icon,
  UsersIcon,
  BanknotesIcon,
  ArrowsRightLeftIcon,
  ShieldCheckIcon,
  CpuChipIcon,
  ExclamationTriangleIcon,
  WrenchScrewdriverIcon,
  BellIcon,
  ChevronDownIcon,
  ArrowRightOnRectangleIcon,
} from '@heroicons/react/24/outline'
import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { alertsApi } from '@/modules/alerts/alertsApi'

interface NavItem {
  label: string
  to?: string
  icon: React.ComponentType<{ className?: string }>
  children?: { label: string; to: string }[]
  roles?: string[]
}

const NAV: NavItem[] = [
  { label: 'Analytics',    to: '/analytics',     icon: ChartBarIcon },
  { label: 'Alerts',       to: '/alerts',        icon: BellIcon },
  { label: 'Tenants',      to: '/tenants',        icon: BuildingOffice2Icon },
  { label: 'Admin Users',  to: '/users',          icon: UsersIcon },
  { label: 'Transactions', to: '/transactions',   icon: ArrowsRightLeftIcon },
  { label: 'Settlement',   to: '/settlement',     icon: BanknotesIcon },
  { label: 'Rails',        to: '/rails',          icon: CpuChipIcon },
  {
    label: 'Compliance',
    icon: ShieldCheckIcon,
    children: [
      { label: 'KYC Queue', to: '/compliance/kyc' },
      { label: 'AML Monitoring', to: '/compliance/aml' },
    ],
  },
  {
    label: 'Risk & Fraud',
    icon: ExclamationTriangleIcon,
    children: [
      { label: 'Fraud Rules', to: '/risk/rules' },
      { label: 'Cases', to: '/risk/cases' },
    ],
  },
  { label: 'System',       to: '/system',         icon: WrenchScrewdriverIcon },
]

function AlertsNavItem({ item }: { item: NavItem }) {
  const Icon = item.icon!
  const { data } = useQuery({
    queryKey: ['admin', 'alerts', 'unread'],
    queryFn: alertsApi.unreadCount,
    refetchInterval: 15_000,
  })
  const count = data?.count ?? 0

  return (
    <NavLink
      to={item.to!}
      className={({ isActive }) =>
        cn('flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
          isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100')
      }
    >
      <Icon className="w-4 h-4 shrink-0" />
      <span className="flex-1 text-left">{item.label}</span>
      {count > 0 && (
        <span className="min-w-5 h-5 px-1.5 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center">
          {count > 99 ? '99+' : count}
        </span>
      )}
    </NavLink>
  )
}

function SidebarItem({ item }: { item: NavItem }) {
  const [open, setOpen] = useState(false)
  const Icon = item.icon

  // Alerts carries a live unread badge.
  if (item.label === 'Alerts') {
    return <AlertsNavItem item={item} />
  }

  if (item.children) {
    return (
      <div>
        <button
          onClick={() => setOpen(o => !o)}
          className="flex items-center w-full gap-3 px-3 py-2 rounded-lg text-slate-600 hover:bg-slate-100 text-sm font-medium transition-colors"
        >
          <Icon className="w-4 h-4 shrink-0" />
          <span className="flex-1 text-left">{item.label}</span>
          <ChevronDownIcon className={cn('w-3 h-3 transition-transform', open && 'rotate-180')} />
        </button>
        {open && (
          <div className="ml-7 mt-0.5 flex flex-col gap-0.5">
            {item.children.map(c => (
              <NavLink
                key={c.to}
                to={c.to}
                className={({ isActive }) =>
                  cn('block px-3 py-1.5 rounded-md text-sm transition-colors',
                    isActive
                      ? 'bg-indigo-50 text-indigo-700 font-medium'
                      : 'text-slate-600 hover:bg-slate-100')
                }
              >
                {c.label}
              </NavLink>
            ))}
          </div>
        )}
      </div>
    )
  }

  return (
    <NavLink
      to={item.to!}
      className={({ isActive }) =>
        cn('flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
          isActive
            ? 'bg-indigo-50 text-indigo-700'
            : 'text-slate-600 hover:bg-slate-100')
      }
    >
      <Icon className="w-4 h-4 shrink-0" />
      {item.label}
    </NavLink>
  )
}

export function AdminShell() {
  const { username, email, logout } = useAdminAuthStore()
  const navigate = useNavigate()

  useEffect(() => {
    // Trap the back button
    window.history.pushState(null, '', window.location.href)
    const onPopState = () => {
      window.history.pushState(null, '', window.location.href)
    }
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [])

  return (
    <div className="flex h-screen bg-slate-50 overflow-hidden">
      {/* Sidebar */}
      <aside className="w-60 shrink-0 flex flex-col bg-white border-r border-slate-200">
        {/* Logo */}
        <div
          className="flex items-center gap-2 px-4 py-4 border-b border-slate-100 cursor-pointer"
          onClick={() => navigate('/analytics')}
        >
          <div className="w-7 h-7 rounded-lg bg-indigo-600 flex items-center justify-center text-white text-xs font-bold">F</div>
          <span className="font-semibold text-slate-800 text-sm">FixPay Admin</span>
        </div>

        {/* Nav */}
        <nav className="flex-1 overflow-y-auto no-scrollbar px-3 py-3 flex flex-col gap-0.5">
          {NAV.map(item => <SidebarItem key={item.label} item={item} />)}
        </nav>

        {/* User footer */}
        <div className="border-t border-slate-100 px-3 py-3">
          <div className="flex items-center gap-2 px-2 py-2 rounded-lg hover:bg-slate-50">
            <div className="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 text-xs font-bold shrink-0">
              {username.charAt(0).toUpperCase() || 'A'}
            </div>
            <div className="flex-1 min-w-0">
              <div className="text-xs font-medium text-slate-800 truncate">{username}</div>
              <div className="text-xs text-slate-400 truncate">{email}</div>
            </div>
            <button onClick={logout} title="Sign out" className="text-slate-400 hover:text-red-500 transition-colors">
              <ArrowRightOnRectangleIcon className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 overflow-y-auto">
        <Outlet />
      </main>
    </div>
  )
}
