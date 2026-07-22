import { useRef, useState } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/solid'
import { ShareIcon, HomeIcon, DocumentDuplicateIcon, PrinterIcon, PhotoIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import { formatCurrency, formatDateFull } from '@/lib/utils'
import { Button } from '@/components/ui/Button'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { HeartIcon as HeartOutline } from '@heroicons/react/24/outline'
import { HeartIcon as HeartSolid } from '@heroicons/react/24/solid'
import { useFavouritesStore } from '@/store/favourites.store'
import type { Transaction } from '@/types'
import { toBlob } from 'html-to-image'

interface ReceiptState {
  type: string
  amount_kobo: number   // kobo — from API response; formatCurrency expects kobo
  date: string
  requestId?: string
  status?: string
  // Electricity
  token?: string
  units?: string
  meterType?: string
  meter?: string
  customerName?: string
  provider?: string
  // Education
  pin?: string
  exam?: string
  serviceId?: string
  // Airtime/Data
  phone?: string
  network?: string
  bundle?: string
  // TV
  smartcard?: string
  package?: string
}

export function ReceiptScreen() {
  const navigate = useNavigate()
  const { state } = useLocation()
  const r = state as ReceiptState

  if (!r) { navigate('/home', { replace: true }); return null }

  const [showShare, setShowShare] = useState(false)
  const [isSharing, setIsSharing] = useState(false)
  const [favDisabled, setFavDisabled] = useState(false)
  const receiptRef = useRef<HTMLDivElement>(null)

  const isPrepaidElectricity = r.type === 'electricity' && r.meterType === 'prepaid'
  const isFailed = r.status === 'FAILED' || r.status === 'failed'

  const { addFavourite, removeFavourite, isFavourite } = useFavouritesStore()
  const isFav = r.requestId ? isFavourite(r.requestId) : false
  const canBeSaved = r.type !== 'transfer_in' && r.type !== 'wallet_funding' && !!r.requestId

  const toggleFavourite = () => {
    if (!r.requestId || favDisabled) return
    setFavDisabled(true)
    if (isFav) {
      removeFavourite(r.requestId)
    } else {
      const typeStr = r.type === 'bank' || r.type === 'wallet' ? 'transfer_out' : 'bill_payment'
      let desc = 'Payment'
      if (r.type === 'airtime') desc = `${r.network?.toUpperCase()} Airtime`
      if (r.type === 'data') desc = `${r.network?.toUpperCase()} Data`
      if (r.type === 'tv') desc = `${(r.provider ?? '').toUpperCase()} Subscription`
      if (r.type === 'electricity') desc = `${(r.provider ?? '').replace(/-/g, ' ')} Electricity`
      if (r.type === 'education') desc = `${r.exam ?? 'Education'} Payment`
      if (typeStr === 'transfer_out') desc = `Transfer to ${r.customerName || r.phone || ''}`

      const fakeTx: Transaction = {
        id: r.requestId,
        type: typeStr,
        amountKobo: r.amount_kobo,
        feeKobo: 0,
        status: 'completed',
        reference: r.requestId,
        description: desc,
        counterpartyName: r.customerName || r.phone || r.meter || r.smartcard,
        serviceId: r.serviceId || r.network || r.provider,
        createdAt: r.date,
      }
      addFavourite(fakeTx)
    }
  }

  const handleShareImage = async () => {
    if (!receiptRef.current) return
    setIsSharing(true)
    try {
      const blob = await toBlob(receiptRef.current, { cacheBust: true, style: { backgroundColor: '#F2F2F7' } })
      if (!blob) throw new Error('Failed to generate image')
      
      const file = new File([blob], `receipt_${r.requestId || 'payment'}.png`, { type: 'image/png' })
      
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({
          title: 'Payment Receipt',
          files: [file],
        })
      } else {
        // Fallback: download the image
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `receipt_${r.requestId || 'payment'}.png`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch (err) {
      alert('Could not share image. Please try again.')
    } finally {
      setIsSharing(false)
      setShowShare(false)
    }
  }

  const handlePrint = () => {
    setShowShare(false)
    setTimeout(() => window.print(), 300)
  }

  const handleCopy = async () => {
    try {
      let text = `Receipt for Payment\n`
      text += `Status: ${isFailed ? 'Failed' : 'Successful'}\n`
      text += `Amount: ${formatCurrency(r.amount_kobo)}\n`
      text += `Date: ${formatDateFull(r.date)}\n`
      if (r.requestId) text += `Reference: ${r.requestId}\n`
      if (r.token) text += `Token: ${r.token}\n`
      if (r.pin) text += `PIN: ${r.pin}\n`
      await navigator.clipboard.writeText(text)
      alert('Receipt details copied to clipboard')
    } catch {
      alert('Failed to copy to clipboard')
    } finally {
      setShowShare(false)
    }
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <div ref={receiptRef} className="flex-1 overflow-y-auto no-scrollbar px-4 pt-safe pb-8 bg-[#F2F2F7]">
        {/* Status icon */}
        <div className="flex flex-col items-center pt-10 pb-6 animate-scale-in">
          <div className={`w-20 h-20 rounded-full flex items-center justify-center mb-4 ${isFailed ? 'bg-red-100' : 'bg-green-100'}`}>
            {isFailed ? <XCircleIcon className="w-12 h-12 text-ios-red" /> : <CheckCircleIcon className="w-12 h-12 text-ios-green" />}
          </div>
          <h1 className={`text-[22px] font-black ${isFailed ? 'text-ios-red' : 'text-gray-900'}`}>
            {isFailed ? 'Payment Failed' : 'Payment Successful'}
          </h1>
          <p className="text-[14px] text-gray-500 mt-1">{formatDateFull(r.date)}</p>
        </div>

        {/* Amount */}
        <div className="bg-white rounded-[20px] p-5 mb-4 animate-slide-up text-center">
          <p className="text-[13px] text-gray-400 uppercase tracking-wide mb-1">Amount Paid</p>
          <p className="text-[36px] font-black text-gray-900">{formatCurrency(r.amount_kobo)}</p>
        </div>

        {/* ELECTRICITY TOKEN — prominent box */}
        {isPrepaidElectricity && r.token && (
          <div className="rounded-[20px] p-5 mb-4 text-center" style={{ background: 'var(--brand-primary)' }}>
            <p className="text-white/70 text-[12px] uppercase tracking-widest mb-2">⚡ Electricity Token</p>
            <p className="text-white text-[26px] font-black tracking-[4px] font-mono">{r.token}</p>
            {r.units && <p className="text-white/70 text-[13px] mt-2">{r.units}</p>}
            <p className="text-white/50 text-[11px] mt-3">Enter this token on your meter to load your units</p>
          </div>
        )}

        {/* Education PIN */}
        {r.type === 'education' && r.pin && (
          <div className="rounded-[20px] p-5 mb-4 text-center border-2" style={{ borderColor: 'var(--brand-primary)', background: 'rgba(0,122,255,0.04)' }}>
            <p className="text-[12px] uppercase tracking-widest mb-2 font-semibold" style={{ color: 'var(--brand-primary)' }}>🎓 {r.exam ?? 'Exam'} PIN</p>
            <p className="text-[20px] font-black tracking-wide font-mono text-gray-900">{r.pin}</p>
          </div>
        )}

        {/* Details */}
        <div className="bg-white rounded-[20px] overflow-hidden mb-4 animate-slide-up">
          {r.requestId && <Row label="Reference" value={r.requestId} />}
          {r.type === 'airtime' && <>
            <Row label="Network" value={r.network?.toUpperCase() ?? ''} />
            <Row label="Phone" value={r.phone ?? ''} />
          </>}
          {r.type === 'data' && <>
            <Row label="Network" value={r.network?.toUpperCase() ?? ''} />
            <Row label="Bundle" value={r.bundle ?? ''} />
            <Row label="Phone" value={r.phone ?? ''} />
          </>}
          {r.type === 'tv' && <>
            <Row label="Provider" value={(r.provider ?? '').toUpperCase()} />
            <Row label="Customer" value={r.customerName ?? ''} />
            <Row label="Smartcard" value={r.smartcard ?? ''} />
            <Row label="Package" value={r.package ?? ''} />
          </>}
          {r.type === 'electricity' && <>
            <Row label="Provider" value={(r.provider ?? '').replace(/-/g, ' ')} />
            <Row label="Customer" value={r.customerName ?? ''} />
            <Row label="Meter" value={r.meter ?? ''} />
            <Row label="Type" value={r.meterType ?? ''} last={!r.token} />
            {!isPrepaidElectricity && <Row label="Status" value={isFailed ? 'Failed' : 'Payment Posted'} last />}
          </>}
          {r.type === 'education' && <>
            <Row label="Exam" value={r.exam ?? ''} last />
          </>}
        </div>
      </div>

      {/* Actions */}
      <div className="px-4 pb-safe flex flex-col gap-3 pb-6 shrink-0 print:hidden">
        <Button variant="outline" fullWidth onClick={() => setShowShare(true)} >
          <ShareIcon className="w-4 h-4" /> Share Receipt
        </Button>
        {canBeSaved && (
          <Button variant="outline" fullWidth onClick={toggleFavourite} disabled={favDisabled} className={isFav ? "text-brand border-red-200 bg-red-50" : ""}>
            {isFav ? <HeartSolid className="w-4 h-4 text-brand" /> : <HeartOutline className="w-4 h-4" />} 
            {isFav ? 'Saved to Favourites' : 'Save to Favourites'}
          </Button>
        )}
        <Button variant="outline" fullWidth onClick={() => navigate('/more/disputes/new', { state: { prefillTxId: r.requestId, txDate: r.date, type: r.type === 'transfer_out' ? 'TRANSFER' : 'VTPASS' } })}>
          <ExclamationTriangleIcon className="w-4 h-4" /> Raise Dispute
        </Button>
        <Button fullWidth onClick={() => navigate('/home', { replace: true })}>
          <HomeIcon className="w-4 h-4" /> Back to Home
        </Button>
      </div>

      <BottomSheet open={showShare} onClose={() => setShowShare(false)} title="Share Receipt">
        <div className="flex flex-col gap-3 p-4 pb-8">
          <Button variant="outline" fullWidth onClick={handleShareImage} disabled={isSharing} className="justify-start px-6 font-semibold">
            <PhotoIcon className="w-5 h-5 mr-3" /> {isSharing ? 'Generating Image...' : 'Share as Image'}
          </Button>
          <Button variant="outline" fullWidth onClick={handlePrint} className="justify-start px-6 font-semibold">
            <PrinterIcon className="w-5 h-5 mr-3" /> Save as PDF / Print
          </Button>
          <Button variant="outline" fullWidth onClick={handleCopy} className="justify-start px-6 font-semibold">
            <DocumentDuplicateIcon className="w-5 h-5 mr-3" /> Copy Details
          </Button>
        </div>
      </BottomSheet>
    </div>
  )
}

function Row({ label, value, last }: { label: string; value: string; last?: boolean }) {
  return (
    <div className={`flex items-center justify-between px-4 py-3 ${!last ? 'border-b border-black/5' : ''}`}>
      <span className="text-[14px] text-gray-500">{label}</span>
      <span className="text-[14px] font-semibold text-gray-900 text-right max-w-[60%] break-all">{value}</span>
    </div>
  )
}
