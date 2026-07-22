import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  ArrowDownLeftIcon, ArrowUpRightIcon, BoltIcon,
  DevicePhoneMobileIcon, TvIcon, AcademicCapIcon, WalletIcon,
  DocumentDuplicateIcon, ExclamationTriangleIcon,
} from '@heroicons/react/24/outline'
import { CheckCircleIcon, XCircleIcon, ClockIcon } from '@heroicons/react/24/solid'
import { walletService } from '@/lib/services/wallet.service'
import { formatCurrency, formatDateFull, vibrate, cn } from '@/lib/utils'
import type { Transaction } from '@/types'
import { PageHeader } from '@/components/layout/PageHeader'
import { Badge, statusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'

// ─── Helpers ─────────────────────────────────────────────────────────────────

function txIconBg(tx: Transaction): { bg: string; color: string } {
  if (tx.type === 'transfer_in')    return { bg: '#D1FAE5', color: '#059669' }
  if (tx.type === 'transfer_out')   return { bg: '#FEE2E2', color: '#DC2626' }
  if (tx.type === 'wallet_funding') return { bg: '#DBEAFE', color: '#2563EB' }
  const sid = tx.serviceId ?? ''
  if (sid.includes('electric'))                              return { bg: '#FEF3C7', color: '#D97706' }
  if (['dstv','gotv','startimes','showmax'].includes(sid))   return { bg: '#EDE9FE', color: '#7C3AED' }
  if (['jamb','waec','waec-registration'].includes(sid))     return { bg: '#CCFBF1', color: '#0F766E' }
  return { bg: '#FFEDD5', color: '#EA580C' }
}

function TxIcon({ tx, size = 64 }: { tx: Transaction; size?: number }) {
  const { bg, color } = txIconBg(tx)
  const cls = `shrink-0 flex items-center justify-center rounded-full`
  const style = { width: size, height: size, background: bg }
  const iconCls = `w-7 h-7`
  const Icon =
    tx.type === 'transfer_in'    ? ArrowDownLeftIcon :
    tx.type === 'transfer_out'   ? ArrowUpRightIcon :
    tx.type === 'wallet_funding' ? WalletIcon :
    (tx.serviceId ?? '').includes('electric')                           ? BoltIcon :
    ['dstv','gotv','startimes','showmax'].includes(tx.serviceId ?? '')  ? TvIcon :
    ['jamb','waec','waec-registration'].includes(tx.serviceId ?? '')    ? AcademicCapIcon :
    DevicePhoneMobileIcon
  return (
    <div className={cls} style={style}>
      <Icon className={iconCls} style={{ color }} />
    </div>
  )
}

function StatusIcon({ status }: { status: string }) {
  if (status === 'completed' || status === 'delivered')
    return <CheckCircleIcon className="w-5 h-5 text-ios-green" />
  if (status === 'failed' || status === 'reversed')
    return <XCircleIcon className="w-5 h-5 text-ios-red" />
  return <ClockIcon className="w-5 h-5 text-ios-orange" />
}

function Row({ label, value, mono, copyable }: {
  label: string; value: string; mono?: boolean; copyable?: boolean
}) {
  const copy = () => {
    navigator.clipboard.writeText(value).catch(() => {})
    vibrate(10)
  }
  return (
    <div className="flex items-center justify-between px-4 py-3 border-b border-black/[0.06] last:border-0">
      <span className="text-[14px] text-gray-500 shrink-0 mr-4">{label}</span>
      <div className="flex items-center gap-2 min-w-0">
        <span className={cn('text-[14px] font-semibold text-gray-900 text-right truncate', mono && 'font-mono text-[13px]')}>
          {value}
        </span>
        {copyable && (
          <button onClick={copy} className="shrink-0 w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center pressable">
            <DocumentDuplicateIcon className="w-3.5 h-3.5 text-brand" />
          </button>
        )}
      </div>
    </div>
  )
}

// ─── Screen ──────────────────────────────────────────────────────────────────

export function TransactionDetailScreen() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const { data: tx, isLoading } = useQuery<Transaction>({
    queryKey: ['transactions', id],
    queryFn: () => walletService.getTransaction(id!),
    enabled: !!id,
  })

  if (isLoading) {
    return (
      <div className="h-[100dvh] bg-[#F2F2F7] flex flex-col">
        <PageHeader title="Transaction" onBack="default" />
        <div className="flex-1 flex items-center justify-center">
          <Spinner size="lg" />
        </div>
      </div>
    )
  }

  if (!tx) {
    return (
      <div className="h-[100dvh] bg-[#F2F2F7] flex flex-col">
        <PageHeader title="Transaction" onBack="default" />
        <div className="flex-1 flex flex-col items-center justify-center gap-3 px-8 text-center">
          <XCircleIcon className="w-14 h-14 text-gray-200" />
          <p className="text-[17px] font-semibold text-gray-700">Transaction not found</p>
          <p className="text-[14px] text-gray-400">This transaction may have been removed.</p>
        </div>
      </div>
    )
  }

  const isCredit = tx.type === 'transfer_in' || tx.type === 'wallet_funding'
  const { label, variant } = statusBadge(tx.status)

  const txTypeLabel: Record<string, string> = {
    bill_payment:   'Bill Payment',
    transfer_out:   'Bank Transfer (Out)',
    transfer_in:    'Transfer Received',
    wallet_funding: 'Wallet Funding',
    withdrawal:     'Withdrawal',
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Transaction" onBack="default" />

      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-2 pb-10 animate-slide-up">

        {/* ── Hero ── */}
        <div className="flex flex-col items-center pt-4 pb-6">
          <TxIcon tx={tx} size={72} />

          <p className={cn(
            'text-[38px] font-black tracking-tight mt-4',
            isCredit ? 'text-ios-green' : 'text-gray-900'
          )}>
            {isCredit ? '+' : '-'}{formatCurrency(tx.amountKobo)}
          </p>

          {tx.feeKobo > 0 && (
            <p className="text-[13px] text-gray-400 mt-0.5">
              + {formatCurrency(tx.feeKobo)} fee
            </p>
          )}

          <p className="text-[16px] text-gray-700 font-medium mt-2 text-center px-4">{tx.description}</p>

          {/* Status pill */}
          <div className="flex items-center gap-1.5 mt-3 bg-white rounded-full px-3 py-1.5 shadow-sm">
            <StatusIcon status={tx.status} />
            <Badge variant={variant} className="shadow-none bg-transparent p-0 text-[14px]">{label}</Badge>
          </div>
        </div>

        {/* ── Transaction details ── */}
        <p className="text-[12px] font-semibold text-gray-400 uppercase tracking-wider mb-2 px-1">Details</p>
        <div className="bg-white rounded-[20px] overflow-hidden mb-4">
          <Row label="Type"      value={txTypeLabel[tx.type] ?? tx.type} />
          <Row label="Date"      value={formatDateFull(tx.createdAt)} />
          <Row label="Reference" value={tx.reference} mono copyable />
          {tx.serviceId   && <Row label="Service"     value={tx.serviceName ?? tx.serviceId} />}
          {tx.counterpartyName && <Row label="Counterparty" value={tx.counterpartyName} />}
          {tx.token       && <Row label="Token"       value={tx.token} mono copyable />}
        </div>

        {/* ── Amount breakdown ── */}
        <p className="text-[12px] font-semibold text-gray-400 uppercase tracking-wider mb-2 px-1">Amount</p>
        <div className="bg-white rounded-[20px] overflow-hidden mb-4">
          <Row label={isCredit ? 'Amount Received' : 'Amount Sent'} value={formatCurrency(tx.amountKobo)} />
          {tx.feeKobo > 0 && <Row label="Transaction Fee" value={formatCurrency(tx.feeKobo)} />}
          {tx.feeKobo > 0 && (
            <Row
              label="Total Deducted"
              value={formatCurrency(tx.amountKobo + tx.feeKobo)}
            />
          )}
        </div>

        {/* ── Raise dispute ── */}
        {(tx.status === 'completed' || tx.status === 'failed') && (
          <Button
            variant="outline"
            fullWidth
            className="mt-2"
            onClick={() => navigate('/more/disputes', { state: { prefillTxId: tx.id } })}
          >
            <ExclamationTriangleIcon className="w-5 h-5 text-brand" />
            Raise a Dispute
          </Button>
        )}
      </div>
    </div>
  )
}
