import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ShieldExclamationIcon } from '@heroicons/react/24/outline'
import { formatCurrency } from '@/lib/utils'
import { PageHeader } from '@/components/layout/PageHeader'
import { TransactionItem } from '@/components/feature/TransactionItem'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import type { Wallet, Transaction } from '@/types'
import { walletService } from '@/lib/services/wallet.service'
import { useAuthStore } from '@/store/auth.store'
import { TransactionDetailsBottomSheet } from '@/components/feature/TransactionDetailsBottomSheet'

export function WalletScreen() {
  const navigate = useNavigate()
  const { kycCompleted } = useAuthStore()
  const [filter, setFilter] = useState<'all' | 'today' | 'week' | 'month' | 'last_month' | 'custom'>('all')
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [selectedTx, setSelectedTx] = useState<Transaction | null>(null)

  const { data: wallet } = useQuery<Wallet>({
    queryKey: ['wallet'],
    queryFn: () => walletService.getBalance(),
    staleTime: 30_000,
    enabled: kycCompleted,
  })

  const { data: txPage, isLoading } = useQuery({
    queryKey: ['transactions', { page: 0, size: 50 }],
    queryFn: () => walletService.getTransactions(0, 50),
    staleTime: 30_000,
    enabled: kycCompleted,
  })

  // KYC gate — show verification prompt instead of wallet
  if (!kycCompleted) {
    return (
      <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
        <PageHeader title="Wallet" />
        <div className="flex-1 flex flex-col items-center justify-center px-6 pb-12 animate-slide-up">
          <div className="bg-white rounded-[24px] p-8 flex flex-col items-center text-center shadow-sm border border-black/5 max-w-sm w-full">
            <div className="w-16 h-16 rounded-full bg-blue-50 flex items-center justify-center mb-4">
              <ShieldExclamationIcon className="w-8 h-8 text-ios-blue" />
            </div>
            <h2 className="text-[18px] font-bold text-gray-900 mb-2">Verification Required</h2>
            <p className="text-[13px] text-gray-500 leading-relaxed mb-6">
              Complete your identity verification to open a wallet. Get a virtual account, send money anywhere, and unlock ₦5,000,000 daily transfers.
            </p>
            <div className="flex flex-col gap-3 w-full">
              <div className="flex items-center gap-2 text-left">
                <span className="w-1.5 h-1.5 rounded-full bg-ios-green shrink-0 mt-1.5" />
                <span className="text-[12px] text-gray-600">Virtual bank account</span>
              </div>
              <div className="flex items-center gap-2 text-left">
                <span className="w-1.5 h-1.5 rounded-full bg-ios-green shrink-0 mt-1.5" />
                <span className="text-[12px] text-gray-600">Instant transfers to any bank</span>
              </div>
              <div className="flex items-center gap-2 text-left">
                <span className="w-1.5 h-1.5 rounded-full bg-ios-green shrink-0 mt-1.5" />
                <span className="text-[12px] text-gray-600">₦5,000,000 daily limit</span>
              </div>
            </div>
            <Button fullWidth className="mt-6" onClick={() => navigate('/kyc')}>
              Verify Identity
            </Button>
            <button
              onClick={() => navigate('/home')}
              className="mt-3 text-[12px] font-semibold"
              style={{ color: 'var(--brand-primary)' }}
            >
              Maybe Later
            </button>
          </div>
        </div>
      </div>
    )
  }

  const txns = txPage?.content ?? []

  const isToday = (dateStr: string) => {
    const d = new Date(dateStr)
    const today = new Date()
    return d.getDate() === today.getDate() &&
           d.getMonth() === today.getMonth() &&
           d.getFullYear() === today.getFullYear()
  }

  const isThisWeek = (dateStr: string) => {
    const d = new Date(dateStr)
    const today = new Date()
    const firstDayOfWeek = new Date(today.setDate(today.getDate() - today.getDay()))
    firstDayOfWeek.setHours(0, 0, 0, 0)
    return d >= firstDayOfWeek
  }

  const isThisMonth = (dateStr: string) => {
    const d = new Date(dateStr)
    const today = new Date()
    return d.getMonth() === today.getMonth() &&
           d.getFullYear() === today.getFullYear()
  }

  const isLastMonth = (dateStr: string) => {
    const d = new Date(dateStr)
    const today = new Date()
    let lmYear = today.getFullYear()
    let lmMonth = today.getMonth() - 1
    if (lmMonth < 0) { lmMonth = 11; lmYear -= 1 }
    return d.getMonth() === lmMonth && d.getFullYear() === lmYear
  }

  const isWithinRange = (dateStr: string, start: string, end: string) => {
    if (!start || !end) return true
    const d = new Date(dateStr)
    d.setHours(0, 0, 0, 0)
    const startDateObj = new Date(start)
    startDateObj.setHours(0, 0, 0, 0)
    const endDateObj = new Date(end)
    endDateObj.setHours(23, 59, 59, 999)
    return d >= startDateObj && d <= endDateObj
  }

  const filteredTxns = txns.filter(tx => {
    if (filter === 'all') return true
    if (filter === 'today') return isToday(tx.createdAt)
    if (filter === 'week') return isThisWeek(tx.createdAt)
    if (filter === 'month') return isThisMonth(tx.createdAt)
    if (filter === 'last_month') return isLastMonth(tx.createdAt)
    if (filter === 'custom') return isWithinRange(tx.createdAt, startDate, endDate)
    return true
  })

  return (
    <div className="flex flex-col bg-[#F2F2F7] min-h-[100dvh] pb-nav">
      <PageHeader title="Wallet" />

      {/* Account info card */}
      <div className="mx-4 mt-4 bg-white rounded-[16px] p-5 animate-slide-up shrink-0 shadow-sm border border-black/5">
        <p className="text-[13px] text-gray-400 mb-1">Available Balance</p>
        <p className="text-[26px] font-black text-gray-900">{formatCurrency(wallet?.balanceKobo ?? 0)}</p>
        {wallet?.virtualAccount && (
          <div className="mt-3 pt-3 border-t border-gray-100">
            <p className="text-[12px] text-gray-400">Fund via bank transfer:</p>
            <p className="text-[15px] font-bold text-gray-900 mt-0.5">{wallet.virtualAccount.accountNumber}</p>
            <p className="text-[12px] text-gray-500">{wallet.virtualAccount.bankName}</p>
          </div>
        )}
      </div>

      {/* Quick Filters */}
      <div className="flex gap-2 overflow-x-auto px-4 py-3 no-scrollbar shrink-0">
        {([
          { id: 'all', label: 'All' },
          { id: 'today', label: 'Today' },
          { id: 'week', label: 'This Week' },
          { id: 'month', label: 'This Month' },
          { id: 'last_month', label: 'Last Month' },
          { id: 'custom', label: 'Custom' },
        ] as const).map(f => (
          <button key={f.id} onClick={() => setFilter(f.id)}
            className="px-4 py-1.5 rounded-full text-[12px] font-semibold transition-all shrink-0 pressable"
            style={filter === f.id ? { background: 'var(--brand-primary)', color: 'white' } : { background: 'white', color: '#8E8E93', border: '1px solid border-black/5' }}>
            {f.label}
          </button>
        ))}
      </div>

      {/* Custom Date Inputs */}
      {filter === 'custom' && (
        <div className="mx-4 mb-4 p-3 bg-white rounded-[16px] flex gap-3 items-center justify-between animate-fade-in shadow-sm border border-black/5 shrink-0">
          <div className="flex-1 flex flex-col gap-1">
            <span className="text-[10px] text-gray-400 font-bold uppercase px-1">Start Date</span>
            <input type="date" value={startDate} onChange={e => setStartDate(e.target.value)}
              className="w-full bg-gray-50 border border-black/5 rounded-[10px] px-3 py-1.5 text-[13px] text-gray-800 focus:outline-none" />
          </div>
          <div className="flex-1 flex flex-col gap-1">
            <span className="text-[10px] text-gray-400 font-bold uppercase px-1">End Date</span>
            <input type="date" value={endDate} onChange={e => setEndDate(e.target.value)}
              className="w-full bg-gray-50 border border-black/5 rounded-[10px] px-3 py-1.5 text-[13px] text-gray-800 focus:outline-none" />
          </div>
        </div>
      )}

      {/* Transactions list */}
      <section className="px-4 flex-1 flex flex-col min-h-0 animate-slide-up">
        <h2 className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-3 shrink-0">All Transactions</h2>
        {isLoading ? (
          <div className="flex justify-center py-8"><Spinner /></div>
        ) : filteredTxns.length === 0 ? (
          <div className="bg-white rounded-[16px] p-8 text-center text-gray-400 text-[13px]">No transactions match filter</div>
        ) : (
          <div className="flex-1 min-h-0 bg-white rounded-[16px] overflow-hidden flex flex-col border border-black/5 shadow-sm">
            <div className="flex-1 overflow-y-auto pr-0.5 max-h-[380px]">
              {filteredTxns.map(tx => <TransactionItem key={tx.id} tx={tx} onClick={() => setSelectedTx(tx)} />)}
            </div>
          </div>
        )}
      </section>

      <TransactionDetailsBottomSheet tx={selectedTx} open={selectedTx !== null} onClose={() => setSelectedTx(null)} />
    </div>
  )
}