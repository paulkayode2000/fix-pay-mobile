import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { PinPad } from '@/components/ui/PinPad'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import type { Transaction } from '@/types'
import { api } from '@/lib/api'
import { queryClient } from '@/lib/query-client'
import { formatCurrency, cn } from '@/lib/utils'
import { useTransactionStore } from '@/store/transaction.store'
import { authService } from '@/lib/services/auth.service'

interface RepeatPaymentBottomSheetProps {
  tx: Transaction | null
  open: boolean
  onClose: () => void
}

export function RepeatPaymentBottomSheet({ tx, open, onClose }: RepeatPaymentBottomSheetProps) {
  const navigate = useNavigate()
  const { isProcessing, startProcessing, stopProcessing } = useTransactionStore()

  const [loadingDetails, setLoadingDetails] = useState(false)
  const [details, setDetails] = useState<any>(null)
  const [error, setError] = useState('')

  const [showPin, setShowPin] = useState(false)
  const [pin, setPin] = useState('')
  const [pinError, setPinError] = useState('')

  // Reset state when opened with a new tx
  useEffect(() => {
    if (open && tx) {
      setDetails(null)
      setError('')
      setShowPin(false)
      setPin('')
      setPinError('')
      fetchDetails()
    }
  }, [open, tx])

  const fetchDetails = async () => {
    if (!tx) return
    setLoadingDetails(true)
    setError('')
    try {
      let res
      if (tx.type === 'transfer_out') {
        res = await api.get(`/transfers/${tx.reference}`)
      } else {
        res = await api.get(`/payments/vtpass/${tx.reference}`)
      }
      setDetails(res.data.data ?? res.data)
    } catch (err) {
      setError('Failed to load transaction details. Please try again.')
    } finally {
      setLoadingDetails(false)
    }
  }

  const handlePinChange = async (val: string) => {
    setPin(val)
    setPinError('')
    if (val.length < 6 || !details || isProcessing) return
    startProcessing()
    try {
      await authService.verifyPin(val)
      
      let res;
      if (tx?.type === 'transfer_out') {
        // We know it's a bank transfer or wallet transfer.
        // But for simplicity, we assume bank transfer if bank_code is present.
        if (details.bank_code) {
          res = await api.post('/transfers/bank', {
            amount_kobo: details.amount_kobo,
            account_number: details.account_number,
            bank_code: details.bank_code,
            narration: details.narration,
          })
        } else {
           res = await api.post('/transfers/wallet', {
            amount_kobo: details.amount_kobo,
            recipient_phone: details.account_number || details.phone,
            narration: details.narration,
          })
        }
      } else {
        res = await api.post('/payments/vtpass', {
          service_id: details.service_id,
          amount_kobo: details.amount_kobo,
          phone: details.phone || '',
          billers_code: details.billers_code || '',
          variation_code: details.variation_code || '',
        })
      }

      const responseData = res.data.data ?? res.data
      
      const receiptState = {
        type: tx?.type === 'transfer_out' ? 'bank' : details.service_id,
        amount_kobo: responseData.amount_kobo || details.amount_kobo,
        requestId: responseData.payment_reference || responseData.transfer_reference,
        date: new Date().toISOString(),
        token: responseData.token,
        units: responseData.units,
        pin: responseData.pin,
        customerName: details.account_name || details.customer_name,
        phone: details.phone,
        meter: details.meter_number,
        smartcard: details.smartcard_number,
        provider: details.provider_code,
        exam: details.variation_code,
        network: details.service_id,
      }

      onClose()
      navigate('/payments/receipt', { replace: true, state: receiptState })
    } catch (err: any) {
      const errData = err?.response?.data
      if (errData?.payment_reference || errData?.transfer_reference) {
        const receiptState = {
          type: tx?.type === 'transfer_out' ? 'bank' : details.service_id,
          amount_kobo: errData.amount_kobo || details.amount_kobo,
          requestId: errData.payment_reference || errData.transfer_reference,
          date: new Date().toISOString(),
          status: 'FAILED',
          customerName: details.account_name || details.customer_name,
          phone: details.phone,
          meter: details.meter_number,
          smartcard: details.smartcard_number,
          provider: details.provider_code,
          exam: details.variation_code,
          network: details.service_id,
        }
        onClose()
        navigate('/payments/receipt', { replace: true, state: receiptState })
      } else {
        setPinError(errData?.message || 'Service not available at this time. Please try again later.')
        setPin('')
      }
    } finally {
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      stopProcessing()
    }
  }

  if (!tx) return null

  // Total calculation
  const totalAmount = details ? (details.amount_kobo + (details.fee_kobo || 0)) : 0

  return (
    <BottomSheet open={open} onClose={() => { if (!isProcessing) onClose() }} title={showPin ? "Enter PIN" : "Repeat Payment"} dismissible={!isProcessing}>
      <div className={showPin ? "" : "px-4 pt-2 pb-6 flex flex-col"}>
        {showPin ? (
          <div className="px-2 pt-2 pb-4">
             <PinPad value={pin} onChange={handlePinChange} error={pinError} disabled={isProcessing} scrambled />
          </div>
        ) : loadingDetails ? (
          <div className="flex flex-col items-center justify-center py-10">
            <Spinner size="md" />
            <p className="text-[13px] text-gray-400 mt-3">Loading details...</p>
          </div>
        ) : error ? (
          <div className="flex flex-col items-center justify-center py-6 text-center">
            <p className="text-[14px] text-ios-red mb-4">{error}</p>
            <Button variant="outline" size="sm" onClick={fetchDetails}>Retry</Button>
          </div>
        ) : details ? (
          <div className="flex flex-col">
             <div className="w-full bg-gray-50 rounded-[20px] p-4 flex flex-col gap-3.5 mb-6">
                <div className="flex justify-between items-start">
                  <span className="text-[13px] text-gray-500">Service / To</span>
                  <span className="text-[14px] font-semibold text-gray-900 text-right max-w-[65%]">
                    {tx.type === 'transfer_out' ? details.account_name : details.service_id.toUpperCase()}
                  </span>
                </div>
                {tx.type === 'transfer_out' && (
                  <div className="flex justify-between items-center">
                    <span className="text-[13px] text-gray-500">Account</span>
                    <span className="text-[13px] font-medium text-gray-900">{details.account_number} ({details.bank_name})</span>
                  </div>
                )}
                {tx.type !== 'transfer_out' && (details.phone || details.billers_code) && (
                  <div className="flex justify-between items-center">
                    <span className="text-[13px] text-gray-500">Beneficiary</span>
                    <span className="text-[13px] font-medium text-gray-900">{details.billers_code || details.phone}</span>
                  </div>
                )}
                <div className="flex justify-between items-center">
                  <span className="text-[13px] text-gray-500">Amount</span>
                  <span className="text-[13px] font-medium text-gray-900">{formatCurrency(details.amount_kobo)}</span>
                </div>
                {details.fee_kobo > 0 && (
                  <div className="flex justify-between items-center">
                    <span className="text-[13px] text-gray-500">Fee</span>
                    <span className="text-[13px] font-medium text-gray-900">{formatCurrency(details.fee_kobo)}</span>
                  </div>
                )}
             </div>

             <div className="flex items-center justify-between px-1 mb-6">
               <span className="text-[15px] font-bold text-gray-900">Total</span>
               <span className="text-[24px] font-black text-ios-green">{formatCurrency(totalAmount)}</span>
             </div>

             <Button fullWidth onClick={() => setShowPin(true)} disabled={isProcessing}>
               Confirm & Pay
             </Button>
          </div>
        ) : null}
      </div>
    </BottomSheet>
  )
}
