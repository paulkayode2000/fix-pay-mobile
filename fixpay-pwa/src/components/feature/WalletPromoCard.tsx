import { useNavigate } from 'react-router-dom'
import { WalletIcon, ShieldCheckIcon, BanknotesIcon, ArrowTrendingUpIcon } from '@heroicons/react/24/outline'

export function WalletPromoCard() {
  const navigate = useNavigate()

  return (
    <div className="mx-4 mt-4 animate-slide-up">
      <button
        onClick={() => navigate('/wallet')}
        className="w-full text-left rounded-[16px] overflow-hidden pressable border border-black/5"
        style={{ background: 'linear-gradient(135deg, var(--brand-primary), color-mix(in srgb, var(--brand-primary) 70%, #34C759))' }}
      >
        {/* Top section */}
        <div className="px-5 pt-5 pb-3">
          <div className="flex items-center gap-3 mb-3">
            <div className="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
              <WalletIcon className="w-5 h-5 text-white" />
            </div>
            <div>
              <h3 className="text-[15px] font-bold text-white">Open Your FixPay Wallet</h3>
              <p className="text-[11px] text-white/70">Get a virtual account in seconds</p>
            </div>
          </div>
          <p className="text-[12px] text-white/80 leading-relaxed">
            Send money anywhere, receive bank transfers, and unlock ₦5,000,000 daily limits.
          </p>
        </div>

        {/* Benefit list */}
        <div className="px-5 py-3 bg-black/10 flex flex-col gap-2">
          <div className="flex items-center gap-2">
            <BanknotesIcon className="w-3.5 h-3.5 text-white/70" />
            <span className="text-[11px] text-white/80">Virtual bank account — receive transfers instantly</span>
          </div>
          <div className="flex items-center gap-2">
            <ShieldCheckIcon className="w-3.5 h-3.5 text-white/70" />
            <span className="text-[11px] text-white/80">Bank-grade security with 6-digit PIN protection</span>
          </div>
          <div className="flex items-center gap-2">
            <ArrowTrendingUpIcon className="w-3.5 h-3.5 text-white/70" />
            <span className="text-[11px] text-white/80">₦5,000,000 daily transfer limit</span>
          </div>
        </div>

        {/* CTA */}
        <div className="px-5 py-3 bg-white/10 flex items-center justify-between">
          <span className="text-[12px] font-semibold text-white">Open your wallet now</span>
          <span className="text-white text-[16px]">→</span>
        </div>
      </button>
    </div>
  )
}