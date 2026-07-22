import { NavLink, useLocation } from 'react-router-dom'
import { HomeIcon, CreditCardIcon, PaperAirplaneIcon, WalletIcon, EllipsisHorizontalIcon } from '@heroicons/react/24/outline'
import { HomeIcon as HomeIconSolid, CreditCardIcon as CreditCardSolid, PaperAirplaneIcon as PaperAirplaneSolid, WalletIcon as WalletSolid, EllipsisHorizontalCircleIcon as EllipsisSolid } from '@heroicons/react/24/solid'
import { cn } from '@/lib/utils'

const tabs = [
  { to: '/home',     label: 'Home',     Icon: HomeIcon,            IconActive: HomeIconSolid },
  { to: '/payments', label: 'Pay',      Icon: CreditCardIcon,      IconActive: CreditCardSolid },
  { to: '/send',     label: 'Send',     Icon: PaperAirplaneIcon,   IconActive: PaperAirplaneSolid },
  { to: '/wallet',   label: 'Wallet',   Icon: WalletIcon,          IconActive: WalletSolid },
  { to: '/more',     label: 'More',     Icon: EllipsisHorizontalIcon, IconActive: EllipsisSolid },
]

export function BottomNav() {
  const location = useLocation()
  return (
    <nav className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-[480px] bg-white/80 backdrop-blur-xl border-t border-black/5 z-30 h-nav pb-safe flex items-start">
      {tabs.map(({ to, label, Icon, IconActive }) => {
        const active = location.pathname.startsWith(to)
        return (
          <NavLink key={to} to={to} className={cn('flex-1 flex flex-col items-center justify-center pt-2 gap-[3px] pressable', active ? 'text-brand' : 'text-gray-400')} style={active ? { color: 'var(--brand-primary)' } : undefined}>
            {active ? <IconActive className="w-5 h-5" /> : <Icon className="w-5 h-5" />}
            <span className="text-[9px] font-medium">{label}</span>
          </NavLink>
        )
      })}
    </nav>
  )
}