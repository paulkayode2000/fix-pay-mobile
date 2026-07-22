import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { DocumentDuplicateIcon, ShieldExclamationIcon } from '@heroicons/react/24/outline'
import type { Wallet } from '@/types'
import { walletService } from '@/lib/services/wallet.service'
import { useAuthStore } from '@/store/auth.store'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/Button'
import { vibrate } from '@/lib/utils'

export function FundWalletScreen() {
  const navigate = useNavigate()
  const { kycCompleted } = useAuthStore()

  const { data: wallet } = useQuery<Wallet>({
    queryKey: ['wallet'],
    queryFn: () => walletService.getBalance(),
    enabled: kycCompleted,
  })

  // KYC gate — redirect to verification
  if (!kycCompleted) {
    return (
      <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
        <PageHeader title="Fund Wallet" onBack="default" />
        <div className="flex-1 flex flex-col items-center justify-center px-6 pb-12 animate-slide-up">
          <div className="bg-white rounded-[24px] p-8 flex flex-col items-center text-center shadow-sm border border-black/5 max-w-sm w-full">
            <div className="w-16 h-16 rounded-full bg-blue-50 flex items-center justify-center mb-4">
              <ShieldExclamationIcon className="w-8 h-8 text-ios-blue" />
            </div>
            <h2 className="text-[18px] font-bold text-gray-900 mb-2">Verification Required</h2>
            <p className="text-[13px] text-gray-500 leading-relaxed mb-6">
              Complete your identity verification to fund your wallet. Once verified, you'll receive a virtual account for instant bank transfers.
            </p>
            <Button fullWidth className="mb-3" onClick={() => navigate('/kyc')}>
              Verify Identity
            </Button>
            <button
              onClick={() => navigate('/wallet')}
              className="text-[12px] font-semibold"
              style={{ color: 'var(--brand-primary)' }}
            >
              Back to Wallet
            </button>
          </div>
        </div>
      </div>
    )
  }

  const acct = wallet?.virtualAccount?.accountNumber ?? '—'
  const bank = wallet?.virtualAccount?.bankName ?? ''

  const copy = () => {
    navigator.clipboard.writeText(acct)
    vibrate([10])
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Fund Wallet" onBack="default" />
      <div className="flex-1 px-4 pt-4 pb-8 animate-slide-up">

        {/* How it works */}
        <div className="bg-blue-50 rounded-[16px] p-4 mb-6 flex gap-3">
          <span className="text-2xl">ℹ️</span>
          <p className="text-[13px] text-blue-700 leading-relaxed">
            Transfer any amount to the account below. Your wallet is funded <strong>instantly</strong> once we receive your transfer.
          </p>
        </div>

        {/* Virtual account */}
        <div className="bg-white rounded-[16px] p-5 border border-black/5">
          <p className="text-[11px] text-gray-400 mb-4 uppercase tracking-wide font-semibold">Your Dedicated Account</p>
          <p className="text-[12px] text-gray-500">Bank Name</p>
          <p className="text-[15px] font-semibold text-gray-900 mb-3">{bank}</p>
          <p className="text-[12px] text-gray-500">Account Number</p>
          <div className="flex items-center gap-3 mt-1">
            <p className="text-[28px] font-black text-gray-900 tracking-widest">{acct}</p>
            <button onClick={copy} className="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center pressable">
              <DocumentDuplicateIcon className="w-4 h-4 text-gray-500" />
            </button>
          </div>
          <p className="text-[12px] text-gray-400 mt-3">Account Name: <strong className="text-gray-700">FixPay / John Adeyemi</strong></p>
        </div>

        <div className="mt-6 flex flex-col gap-3">
          <Button fullWidth variant="outline" onClick={() => navigate(-1)}>Back to Wallet</Button>
        </div>
      </div>
    </div>
  )
}