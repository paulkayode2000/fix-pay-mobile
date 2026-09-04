import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { EyeIcon, EyeSlashIcon, PlusIcon, ArrowUpRightIcon } from '@heroicons/react/24/outline'
import { useQuery } from '@tanstack/react-query'
import { formatCurrency, maskAccount } from '@/lib/utils'
import type { Wallet } from '@/types'
import { walletService } from '@/lib/services/wallet.service'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'

export function BalanceCard() {
  const navigate = useNavigate()
  const [masked, setMasked] = useState(false)
  const { data: wallet, isLoading } = useQuery<Wallet>({
    queryKey: ['wallet'],
    queryFn: () => walletService.getBalance(),
    staleTime: 30_000,
  })

  if (isLoading) return (
    <div className="mx-4 rounded-[20px] h-44 flex items-center justify-center" style={{ background: 'var(--brand-primary)' }}>
      <Spinner color="white" />
    </div>
  )

  // Handle missing wallet gracefully — show zero balance state
  if (!wallet) {
    return (
      <div className="mx-4 rounded-[20px] p-5 text-white relative overflow-hidden" style={{ background: 'var(--brand-primary)' }}>
        <div className="absolute -top-8 -right-8 w-32 h-32 rounded-full bg-white/10 pointer-events-none" />
        <div className="absolute -bottom-12 -left-8 w-40 h-40 rounded-full bg-white/10 pointer-events-none" />
        <div className="relative">
          <span className="text-[13px] font-medium text-white/80">Wallet Balance</span>
          <div className="text-[32px] font-bold tracking-tight mb-3">₦ 0.00</div>
          <p className="text-[13px] text-white/70 mb-5">Complete KYC to activate your wallet</p>
        </div>
      </div>
    )
  }

  const balance = wallet?.balanceKobo ?? 0
  const acct = wallet?.virtualAccount?.accountNumber ?? ''
  const bank = wallet?.virtualAccount?.bankName ?? ''

  return (
    <div className="mx-4 rounded-[20px] p-5 text-white relative overflow-hidden" style={{ background: 'var(--brand-primary)' }}>
      {/* Decorative circles */}
      <div className="absolute -top-8 -right-8 w-32 h-32 rounded-full bg-white/10 pointer-events-none" />
      <div className="absolute -bottom-12 -left-8 w-40 h-40 rounded-full bg-white/10 pointer-events-none" />

      <div className="relative">
        <div className="flex items-center justify-between mb-1">
          <span className="text-[13px] font-medium text-white/80">Wallet Balance</span>
          <button onClick={() => setMasked(m => !m)} className="p-1 pressable">
            {masked ? <EyeIcon className="w-4 h-4 text-white/80" /> : <EyeSlashIcon className="w-4 h-4 text-white/80" />}
          </button>
        </div>
        <div className="text-[32px] font-bold tracking-tight mb-3">
          {masked ? '₦ ••••••' : formatCurrency(balance)}
        </div>

        {/* Virtual account */}
        <div className="flex items-center gap-2 mb-5">
          <div className="text-[13px] text-white/70">
            <span className="font-semibold text-white">{maskAccount(acct)}</span>
            {bank && <span className="ml-1">• {bank}</span>}
          </div>
        </div>

        <div className="flex gap-3">
          <Button size="sm" variant="ghost" className="flex-1 bg-white/20 text-white hover:bg-white/30 rounded-[12px]" onClick={() => navigate('/wallet/fund')}>
            <PlusIcon className="w-4 h-4" /> Fund
          </Button>
          <Button size="sm" variant="ghost" className="flex-1 bg-white/20 text-white hover:bg-white/30 rounded-[12px]" onClick={() => navigate('/send')}>
            <ArrowUpRightIcon className="w-4 h-4" /> Send
          </Button>
        </div>
      </div>
    </div>
  )
}
