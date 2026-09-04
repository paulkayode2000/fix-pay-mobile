import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { BuildingLibraryIcon, ShieldCheckIcon, BanknotesIcon } from '@heroicons/react/24/outline'
import { api } from '@/lib/api'
import { cn } from '@/lib/utils'

interface KYCStatus {
  ready_for_ninepsb: boolean
  next_action: string
  next_action_label: string
  wallet_status?: string
  kyc_status: string
  tier: number
}

interface NinePsbPromoCardProps {
  className?: string
}

export function NinePsbPromoCard({ className }: NinePsbPromoCardProps) {
  const navigate = useNavigate()
  const [kycStatus, setKycStatus] = useState<KYCStatus | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const checkKyc = async () => {
      try {
        const res = await api.get('/kyc/status')
        const data = res.data as KYCStatus
        setKycStatus(data)
      } catch {
        // Silently fail — promo is optional
      } finally {
        setLoading(false)
      }
    }

    checkKyc()
  }, [])

  // Don't show anything while loading or if not ready
  if (loading || !kycStatus?.ready_for_ninepsb) {
    return null
  }

  return (
    <div className={cn('mx-4 rounded-[20px] p-5 relative overflow-hidden', className)}
      style={{
        background: 'linear-gradient(135deg, var(--brand-primary), #1a237e)',
      }}>

      {/* Background pattern */}
      <div className="absolute inset-0 opacity-10">
        <div className="absolute top-2 right-2 w-20 h-20 rounded-full bg-white/20" />
        <div className="absolute bottom-2 left-4 w-12 h-12 rounded-full bg-white/10" />
      </div>

      <div className="relative z-10">
        {/* Header */}
        <div className="flex items-center gap-2 mb-3">
          <BuildingLibraryIcon className="w-5 h-5 text-yellow-300" />
          <h3 className="text-[15px] font-bold text-white">Activate Your 9PSB Wallet</h3>
        </div>

        {/* Body */}
        <p className="text-[13px] text-white/80 mb-4 leading-relaxed">
          Your identity is verified. Get your free NUBAN account to send and receive money from any Nigerian bank.
        </p>

        {/* Feature list */}
        <div className="space-y-2 mb-4">
          <div className="flex items-start gap-2">
            <BanknotesIcon className="w-4 h-4 text-green-300 shrink-0 mt-0.5" />
            <span className="text-[12px] text-white/80">Free NUBAN account number</span>
          </div>
          <div className="flex items-start gap-2">
            <svg className="w-4 h-4 text-green-300 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
            <span className="text-[12px] text-white/80">{kycStatus.tier >= 3 ? '₦1,000,000' : '₦50,000'} daily transfer limit</span>
          </div>
          <div className="flex items-start gap-2">
            <ShieldCheckIcon className="w-4 h-4 text-green-300 shrink-0 mt-0.5" />
            <span className="text-[12px] text-white/80">Bank-grade security with PIN protection</span>
          </div>
        </div>

        {/* CTA */}
        <button
          onClick={() => navigate('/ninepsb/onboarding')}
          className="w-full py-3 bg-white rounded-[14px] text-[14px] font-bold pressable active:scale-[0.98] transition-transform"
          style={{ color: 'var(--brand-primary)' }}
        >
          Open 9PSB Wallet
        </button>

        {/* Alternative note */}
        <p className="text-[10px] text-white/50 text-center mt-2">
          ─── or continue with alternative payment methods ───
        </p>
      </div>
    </div>
  )
}