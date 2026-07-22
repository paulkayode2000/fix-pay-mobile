import { useNavigate } from 'react-router-dom'
import { ChevronRightIcon, UserCircleIcon, BanknotesIcon, ExclamationTriangleIcon, ArrowRightStartOnRectangleIcon, ShieldCheckIcon, ChartBarIcon } from '@heroicons/react/24/outline'
import { useAuthStore } from '@/store/auth.store'
import { useTenantStore } from '@/store/tenant.store'
import { serverLogout } from '@/lib/api'
import { PageHeader } from '@/components/layout/PageHeader'
import { Badge, statusBadge } from '@/components/ui/Badge'
import { cn } from '@/lib/utils'

function MenuItem({ icon: Icon, label, sub, onClick, variant = 'default', last }: {
  icon: React.FC<React.SVGProps<SVGSVGElement>>; label: string; sub?: string; onClick: () => void; variant?: 'default' | 'danger'; last?: boolean
}) {
  return (
    <button onClick={onClick}
      className={cn('w-full flex items-center gap-3 px-4 py-3.5 bg-white pressable active:bg-gray-50 text-left', !last && 'border-b border-black/5')}>
      <div className={cn('w-8 h-8 rounded-full flex items-center justify-center shrink-0', variant === 'danger' ? 'bg-red-100' : 'bg-gray-100')}>
        <Icon className={cn('w-4 h-4', variant === 'danger' ? 'text-ios-red' : 'text-gray-600')} />
      </div>
      <div className="flex-1 min-w-0">
        <p className={cn('text-[14px] font-medium', variant === 'danger' ? 'text-ios-red' : 'text-gray-900')}>{label}</p>
        {sub && <p className="text-[11px] text-gray-400 mt-0.5">{sub}</p>}
      </div>
      <ChevronRightIcon className="w-3.5 h-3.5 text-brand shrink-0" />
    </button>
  )
}

function FooterLink({ label, href }: { label: string; href: string }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer"
      className="text-[11px] text-gray-400 hover:text-brand transition-colors"
      style={{ color: 'var(--brand-primary)' }}>
      {label}
    </a>
  )
}

export function MoreScreen() {
  const navigate = useNavigate()
  const { user } = useAuthStore()
  const { config } = useTenantStore()
  const { label, variant } = statusBadge(user?.kycStatus ?? 'pending')

  return (
    <div className="flex flex-col bg-[#F2F2F7] min-h-[100dvh] pb-nav">
      <PageHeader title="More" />

      {/* Profile summary */}
      <div className="mx-4 mt-4 bg-white rounded-[16px] p-4 flex items-center gap-4 animate-slide-up border border-black/5">
        <div className="w-12 h-12 rounded-full flex items-center justify-center text-white text-[18px] font-black shrink-0"
          style={{ background: 'var(--brand-primary)' }}>
          {(user?.firstName ?? 'U')[0]}
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-[14px] font-bold text-gray-900 truncate">{user?.firstName} {user?.lastName}</p>
          <p className="text-[11px] text-gray-500 truncate">{user?.phone ?? user?.email}</p>
          <div className="mt-1">
            <Badge variant={variant} dot>{label}</Badge>
            {user?.tier && <Badge variant="info" className="ml-1">Tier {user.tier}</Badge>}
          </div>
        </div>
      </div>

      {/* Menu items */}
      <div className="mx-4 mt-4 rounded-[16px] overflow-hidden animate-slide-up stagger border border-black/5">
        <MenuItem icon={UserCircleIcon}      label="Profile"          sub="Manage your account"            onClick={() => navigate('/more/profile')} />
        <MenuItem icon={ShieldCheckIcon}     label="KYC & Security"  sub="Identity & PIN settings"         onClick={() => navigate('/more/security')} />
        <MenuItem icon={BanknotesIcon}       label="Direct Debit"    sub="Manage NIBSS mandates"            onClick={() => navigate('/more/mandates')} />
        <MenuItem icon={ChartBarIcon}        label="Analytics"       sub="Track your spending & income"     onClick={() => navigate('/more/analytics')} />
        <MenuItem icon={ExclamationTriangleIcon} label="Disputes"   sub="Raise & track disputes"           onClick={() => navigate('/more/disputes')} last />
      </div>

      <div className="mx-4 mt-4 rounded-[16px] overflow-hidden animate-slide-up border border-black/5">
        <MenuItem icon={ArrowRightStartOnRectangleIcon} label="Sign Out" variant="danger" onClick={() => { serverLogout().then(() => navigate('/welcome', { replace: true })) }} last />
      </div>

      {/* Footer — trust signals */}
      <div className="mx-4 mt-6 mb-4 animate-slide-up">
        {/* Social & Contact */}
        <div className="flex items-center justify-center gap-4 mb-4">
          <a href="https://instagram.com/fixpay" target="_blank" rel="noopener noreferrer" className="text-[11px] text-gray-400 hover:text-gray-600 transition-colors">Instagram</a>
          <span className="text-gray-300">·</span>
          <a href="https://x.com/fixpay" target="_blank" rel="noopener noreferrer" className="text-[11px] text-gray-400 hover:text-gray-600 transition-colors">X</a>
          <span className="text-gray-300">·</span>
          <a href="https://linkedin.com/company/fixpay" target="_blank" rel="noopener noreferrer" className="text-[11px] text-gray-400 hover:text-gray-600 transition-colors">LinkedIn</a>
          <span className="text-gray-300">·</span>
          <a href="https://facebook.com/fixpay" target="_blank" rel="noopener noreferrer" className="text-[11px] text-gray-400 hover:text-gray-600 transition-colors">Facebook</a>
        </div>

        {/* Legal & FAQ links */}
        <div className="flex items-center justify-center gap-4 mb-4">
          <FooterLink label="FAQs" href="/faqs" />
          <span className="text-gray-300">·</span>
          <FooterLink label="Privacy Policy" href="/privacy" />
          <span className="text-gray-300">·</span>
          <FooterLink label="Terms & Conditions" href="/terms" />
          <span className="text-gray-300">·</span>
          <FooterLink label="Cookie Policy" href="/cookies" />
        </div>

        {/* Contact support */}
        <div className="text-center mb-4">
          <a href="mailto:support@fixpay.ng" className="text-[11px] font-semibold" style={{ color: 'var(--brand-primary)' }}>
            support@fixpay.ng
          </a>
          <span className="text-gray-300 mx-2">·</span>
          <a href="https://wa.me/2348000000000" target="_blank" rel="noopener noreferrer" className="text-[11px] font-semibold" style={{ color: 'var(--brand-primary)' }}>
            WhatsApp Support
          </a>
        </div>

        {/* Version */}
        <p className="text-center text-[10px] text-gray-300">{config.appName} v1.0.0</p>
      </div>
    </div>
  )
}