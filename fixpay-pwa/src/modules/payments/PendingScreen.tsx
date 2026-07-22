import { useState, useEffect } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { ArrowPathIcon, HomeIcon, ExclamationTriangleIcon, ClockIcon } from '@heroicons/react/24/outline'
import { formatCurrency } from '@/lib/utils'
import { Button } from '@/components/ui/Button'
import { paymentsService } from '@/lib/services/payments.service'
import { queryClient } from '@/lib/query-client'
import { resolveVtpassCode } from '@/lib/vtpass-codes'

interface PendingState {
  type: string
  amount_kobo: number
  date: string
  requestId: string
  phone?: string
  network?: string
  meter?: string
  meterType?: string
  customerName?: string
  provider?: string
  smartcard?: string
  package?: string
  exam?: string
  pin?: string
}

export function PendingScreen() {
  const navigate = useNavigate()
  const { state } = useLocation()
  const r = state as PendingState

  const [loading, setLoading] = useState(false)
  const [statusText, setStatusText] = useState('Transaction is currently processing...')
  const [errorMsg, setErrorMsg] = useState('')

  useEffect(() => {
    if (!r || !r.requestId) {
      navigate('/home', { replace: true })
    }
  }, [r, navigate])

  if (!r) return null

  const handleRequery = async () => {
    setLoading(true)
    setErrorMsg('')
    try {
      const res = await paymentsService.requery(r.requestId)
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })

      const outcome = resolveVtpassCode(res.vtpass_code)

      if (res.status === 'delivered' || res.status === 'completed' || outcome.isSuccess) {
        // Successful transaction!
        navigate('/payments/receipt', {
          state: {
            ...r,
            amount_kobo: res.amount_kobo || r.amount_kobo,
            token: res.token || r.pin, // map electricity token / PIN if retrieved
            pin: res.Pin || r.pin,
            date: new Date().toISOString(),
          },
          replace: true,
        })
      } else if (res.status === 'failed' || outcome.isFatal) {
        // Failed transaction
        setErrorMsg(outcome.message || 'Transaction failed. Please contact support.')
        setStatusText('Transaction Failed')
      } else {
        // Still pending
        setStatusText(outcome.message || 'Transaction is still processing. Please try again in a few moments.')
      }
    } catch (err: any) {
      const serverMsg = err?.response?.data?.message || 'Failed to fetch status. Try again.'
      setErrorMsg(serverMsg)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-safe pb-8 flex flex-col justify-center items-center">
        
        {/* Pulsing Pending Icon or warning */}
        <div className="flex flex-col items-center pt-6 pb-6 animate-scale-in">
          <div className={`w-20 h-20 rounded-full flex items-center justify-center mb-4 ${errorMsg ? 'bg-red-100' : 'bg-blue-100 animate-pulse'}`}>
            {errorMsg ? (
              <ExclamationTriangleIcon className="w-12 h-12 text-ios-red" />
            ) : (
              <ClockIcon className="w-12 h-12 text-brand" style={{ color: 'var(--brand-primary)' }} />
            )}
          </div>
          <h1 className="text-[22px] font-black text-gray-900 text-center">
            {errorMsg ? 'Payment Issue' : 'Payment Processing'}
          </h1>
          <p className="text-[14px] text-gray-500 mt-2 text-center max-w-[280px]">
            {errorMsg ? errorMsg : statusText}
          </p>
        </div>

        {/* Transaction Brief */}
        <div className="bg-white w-full max-w-sm rounded-[20px] p-5 mb-4 animate-slide-up shadow-sm">
          <div className="text-center pb-4 border-b border-gray-100">
            <p className="text-[12px] text-gray-400 uppercase tracking-wide mb-1">Estimated Amount</p>
            <p className="text-[28px] font-black text-gray-900">{formatCurrency(r.amount_kobo)}</p>
          </div>

          <div className="mt-4 space-y-3">
            <div className="flex justify-between text-[13px]">
              <span className="text-gray-400">Payment Reference</span>
              <span className="font-mono text-gray-800 font-medium break-all text-right max-w-[60%]">{r.requestId}</span>
            </div>
            {r.type && (
              <div className="flex justify-between text-[13px]">
                <span className="text-gray-400">Service Type</span>
                <span className="font-semibold text-gray-800 capitalize">{r.type.replace(/-/g, ' ')}</span>
              </div>
            )}
            {r.phone && (
              <div className="flex justify-between text-[13px]">
                <span className="text-gray-400">Recipient Phone</span>
                <span className="font-semibold text-gray-800">{r.phone}</span>
              </div>
            )}
            {r.meter && (
              <div className="flex justify-between text-[13px]">
                <span className="text-gray-400">Meter Number</span>
                <span className="font-semibold text-gray-800">{r.meter}</span>
              </div>
            )}
            {r.smartcard && (
              <div className="flex justify-between text-[13px]">
                <span className="text-gray-400">Smartcard Number</span>
                <span className="font-semibold text-gray-800">{r.smartcard}</span>
              </div>
            )}
          </div>
        </div>

        {/* Informative Guidance */}
        {!errorMsg && (
          <p className="text-[12px] text-gray-400 text-center max-w-[280px] mb-6">
            Do not worry. If your wallet was debited and the transaction ultimately fails, the amount will be automatically reversed to your wallet.
          </p>
        )}
      </div>

      {/* Actions */}
      <div className="px-4 pb-safe flex flex-col gap-3 pb-6 shrink-0">
        <Button 
          variant={errorMsg ? 'outline' : 'primary'} 
          fullWidth 
          loading={loading}
          onClick={handleRequery}
        >
          <ArrowPathIcon className="w-5 h-5" /> Check Status (Requery)
        </Button>
        <Button variant="secondary" fullWidth onClick={() => navigate('/home', { replace: true })}>
          <HomeIcon className="w-4 h-4" /> Back to Home
        </Button>
      </div>
    </div>
  )
}
