import { useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { BalanceCard } from '@/components/feature/BalanceCard'
import { ServiceGrid } from '@/components/feature/ServiceGrid'
import { WalletPromoCard } from '@/components/feature/WalletPromoCard'
import { NinePsbPromoCard } from '@/components/feature/NinePsbPromoCard'
import { TransactionItem, txIcon } from '@/components/feature/TransactionItem'
import { TransactionDetailsBottomSheet } from '@/components/feature/TransactionDetailsBottomSheet'
import { RepeatPaymentBottomSheet } from '@/components/feature/RepeatPaymentBottomSheet'
import type { Transaction } from '@/types'
import { useFavouritesStore } from '@/store/favourites.store'
import { useAuthStore } from '@/store/auth.store'
import { formatCurrency, formatDateShort } from '@/lib/utils'
import { Badge, statusBadge } from '@/components/ui/Badge'
import { HeartIcon as HeartSolid } from '@heroicons/react/24/solid'
import { useAnalyticsStore } from '@/store/analytics.store'
import { AnalyticsSummaryChart } from '@/components/feature/AnalyticsSummaryChart'
import { useEffect } from 'react'

export function HomeScreen() {
  const navigate = useNavigate()
  const [selectedTx, setSelectedTx] = useState<Transaction | null>(null)
  const [repeatTx, setRepeatTx] = useState<Transaction | null>(null)
  const { favourites, removeFavourite, fetchFavourites } = useFavouritesStore()
  const { data: analyticsData, fetchAnalytics } = useAnalyticsStore()
  const { kycCompleted, user } = useAuthStore()

  // Show wallet promo if user hasn't completed KYC (i.e., hasn't opened a wallet yet)
  // KYC is required for wallet creation — this is the primary funnel.
  const showWalletPromo = !kycCompleted

  useEffect(() => {
    fetchAnalytics('7d')
    fetchFavourites()
  }, [fetchAnalytics, fetchFavourites])

  return (
    <div className="flex flex-col bg-[#F2F2F7] min-h-[100dvh] pb-nav">

      {/* Balance card */}
      <div className="animate-slide-up">
        <BalanceCard />
      </div>

      {/* Wallet promo — primary funnel to KYC + wallet creation */}
      {showWalletPromo && <WalletPromoCard />}

      {/* 9PSB wallet promo — shows after KYC, nudges to create 9PSB wallet */}
      <NinePsbPromoCard />

      {/* Quick services */}
      <section className="px-4 mt-5 animate-slide-up">
        <h2 className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-3">Quick Pay</h2>
        <ServiceGrid compact />
      </section>

      {/* Favourites */}
      <section className="mt-5 animate-slide-up">
        <div className="flex items-center justify-between mb-3 px-4">
          <h2 className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide">Favourites</h2>
          <button className="text-[13px] font-semibold" style={{ color: 'var(--brand-primary)' }} onClick={() => navigate('/wallet')}>History</button>
        </div>
        {favourites.length === 0 ? (
          <div className="mx-4 bg-white rounded-[16px] p-8 text-center text-gray-400 text-[14px]">No saved favourites</div>
        ) : (
          <div className="flex overflow-x-auto no-scrollbar px-4 gap-3 pb-2">
            {favourites.map(tx => {
              const { label, variant } = statusBadge(tx.status)
              return (
                <div 
                  key={tx.id} 
                  className="bg-white rounded-[16px] p-4 min-w-[200px] max-w-[200px] shrink-0 cursor-pointer active:scale-95 transition-transform pressable flex flex-col justify-between relative"
                  style={{ boxShadow: '0 4px 20px rgba(0,0,0,0.03)' }}
                  onClick={() => setRepeatTx(tx)}
                >
                  <button 
                    className="absolute top-3 right-3 p-1 rounded-full bg-red-50 hover:bg-red-100 z-10"
                    onClick={(e) => { e.stopPropagation(); removeFavourite(tx.id) }}
                  >
                    <HeartSolid className="w-4 h-4 text-brand" />
                  </button>
                  <div className="flex items-start gap-3 mb-4 pr-6">
                    {txIcon(tx)}
                    <div className="flex-1 min-w-0">
                      <p className="text-[14px] font-semibold text-gray-900 truncate">{tx.counterpartyName || tx.serviceName || 'Payment'}</p>
                      <p className="text-[12px] text-gray-500 truncate">{tx.description}</p>
                    </div>
                  </div>
                  <div className="flex items-end justify-between mt-auto">
                    <div>
                      <p className="text-[11px] text-gray-400 mb-1">{formatDateShort(tx.createdAt)}</p>
                      <Badge variant={variant} className="text-[9px] px-1.5 py-0 h-[18px]">{label}</Badge>
                    </div>
                    <p className="text-[15px] font-bold text-gray-900">{formatCurrency(tx.amountKobo)}</p>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </section>

      {/* Analytics Summary */}
      {analyticsData && (
        <div className="px-4 mt-5 mb-5 animate-slide-up">
          <AnalyticsSummaryChart 
            data={analyticsData.trend_data} 
            incomeTotal={analyticsData.income_total} 
            expenseTotal={analyticsData.expense_total} 
            period="7d" 
          />
        </div>
      )}

      <TransactionDetailsBottomSheet tx={selectedTx} open={selectedTx !== null} onClose={() => setSelectedTx(null)} />
      <RepeatPaymentBottomSheet tx={repeatTx} open={repeatTx !== null} onClose={() => setRepeatTx(null)} />
    </div>
  )
}