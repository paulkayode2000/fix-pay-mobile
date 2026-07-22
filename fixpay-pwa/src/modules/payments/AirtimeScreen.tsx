import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { queryClient } from '@/lib/query-client'
import { paymentsService } from '@/lib/services/payments.service'
import { authService } from '@/lib/services/auth.service'
import { PageHeader } from '@/components/layout/PageHeader'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { useTransactionStore } from '@/store/transaction.store'
import { PinPad } from '@/components/ui/PinPad'
import { resolveVtpassCode } from '@/lib/vtpass-codes'
import { PaymentMethodSelector, type PaymentMethod } from '@/components/feature/PaymentMethodSelector'

const NETWORKS = [
  { id: 'mtn',      label: 'MTN',     color: '#FFCC00', text: '#000' },
  { id: 'airtel',   label: 'Airtel',  color: '#EF3125', text: '#FFF' },
  { id: 'glo',      label: 'Glo',     color: '#009900', text: '#FFF' },
  { id: 'etisalat', label: '9mobile', color: '#006600', text: '#FFF' },
]

const AMOUNTS = [100, 200, 500, 1000, 2000, 5000]

const schema = z.object({
  phone:     z.string().min(6, 'Enter a phone number'),
  amount:    z.coerce.number().min(50, 'Minimum ₦50').max(200000, 'Maximum ₦200,000'),
  serviceId: z.string().min(1, 'Select a network'),
})
type FormData = z.infer<typeof schema>

export function AirtimeScreen() {
  const navigate = useNavigate()
  const [showPin, setShowPin] = useState(false)
  const [pin, setPin] = useState('')
  const [pinError, setPinError] = useState('')
  const [pending, setPending] = useState<FormData | null>(null)
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('wallet')
  const { isProcessing, startProcessing, stopProcessing } = useTransactionStore()

  const { register, handleSubmit, setValue, watch, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema) as Resolver<FormData>,
    defaultValues: { serviceId: 'mtn', phone: '', amount: 0 },
  })
  const selectedNet = watch('serviceId')
  const isInternational = selectedNet === 'foreign-airtime'

  const onSubmit = async (data: FormData) => {
    setPending(data)
    if (paymentMethod === 'wallet') {
      setPin('')
      setPinError('')
      setShowPin(true)
    } else {
      startProcessing()
      try {
        const initRes = await paymentsService.alternativeInitiate({
          paymentMethod,
          serviceId: data.serviceId,
          phone: data.phone,
          amount: data.amount,
        })
        
        await new Promise(resolve => setTimeout(resolve, 2000))
        const res = await paymentsService.alternativeVerify(initRes.gateway_reference)
        
        queryClient.invalidateQueries({ queryKey: ['wallet'] })
        queryClient.invalidateQueries({ queryKey: ['transactions'] })

        const outcome = resolveVtpassCode(res.vtpass_code)
        const statePayload = {
          type: 'airtime',
          network: data.serviceId,
          phone: data.phone,
          amount_kobo: res.amount_kobo,
          requestId: res.payment_reference,
          date: new Date().toISOString(),
        }

        if (res.status === 'pending' || outcome.isPending) {
          navigate('/payments/pending', { state: statePayload })
        } else {
          navigate('/payments/receipt', { state: statePayload })
        }
      } catch (err: any) {
        alert(err?.response?.data?.message || 'Payment failed. Try again.')
      } finally {
        stopProcessing()
      }
    }
  }

  const handlePinChange = async (val: string) => {
    setPin(val); setPinError('')
    if (val.length < 6 || !pending || isProcessing) return
    startProcessing()
    try {
      await authService.verifyPin(val)
      const res = await paymentsService.airtime({
        serviceId: pending.serviceId, phone: pending.phone, amount: pending.amount,
      })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })

      const outcome = resolveVtpassCode(res.vtpass_code)
      const statePayload = {
        type: 'airtime',
        network: pending.serviceId,
        phone: pending.phone,
        amount_kobo: res.amount_kobo,
        requestId: res.payment_reference,
        date: new Date().toISOString(),
      }

      if (res.status === 'pending' || outcome.isPending) {
        navigate('/payments/pending', { state: statePayload })
      } else {
        navigate('/payments/receipt', { state: statePayload })
      }
    } catch (err: any) {
      const code = err?.response?.data?.vtpass_code || err?.response?.data?.provider_code
      const outcome = resolveVtpassCode(code)
      const serverMsg = err?.response?.data?.message || (code ? `${outcome.message} (${code})` : 'Service not available at this time. Please try again later.')
      setPinError(serverMsg)
      setPin('')
    } finally {
      stopProcessing()
    }
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Buy Airtime" onBack="default" />
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">

        {/* Network selector */}
        <p className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Select Network</p>
        <div className="grid grid-cols-4 gap-2 mb-3">
          {NETWORKS.map(n => (
            <button key={n.id} onClick={() => setValue('serviceId', n.id)}
              className="flex flex-col items-center gap-1.5 py-3 rounded-[14px] border-2 transition-all pressable"
              style={{ borderColor: selectedNet === n.id ? 'var(--brand-primary)' : 'transparent', background: n.color }}>
              <span className="text-[12px] font-bold" style={{ color: n.text }}>{n.label}</span>
            </button>
          ))}
        </div>
        <button type="button" onClick={() => setValue('serviceId', 'foreign-airtime')}
          className="w-full py-2.5 rounded-[14px] border-2 text-[13px] font-semibold mb-5 pressable transition-all"
          style={{ borderColor: selectedNet === 'foreign-airtime' ? 'var(--brand-primary)' : 'transparent', background: '#2C2C2E', color: '#FFF' }}>
          🌍 International Airtime
        </button>
        {errors.serviceId && <p className="text-ios-red text-[13px] mb-3">{errors.serviceId.message}</p>}

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <Input
            label={isInternational ? 'International Phone Number' : 'Phone Number'}
            type="tel" inputMode="tel"
            placeholder={isInternational ? '+1 2125551234' : '08012345678'}
            error={errors.phone?.message} {...register('phone')} />

          {/* Quick amounts */}
          <div>
            <p className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Amount</p>
            {!isInternational && (
            <div className="grid grid-cols-3 gap-2 mb-3">
              {AMOUNTS.map(a => (
                <button key={a} type="button" onClick={() => setValue('amount', a)}
                  className="py-2.5 bg-white rounded-[12px] text-[15px] font-semibold text-gray-700 pressable active:bg-gray-50 shadow-sm">
                  ₦{a.toLocaleString()}
                </button>
              ))}
            </div>
            )}
            <Input label="" type="number" inputMode="numeric" placeholder="Or enter amount" prefix="₦"
              error={errors.amount?.message} {...register('amount')} />
          </div>

          <PaymentMethodSelector value={paymentMethod} onChange={setPaymentMethod} disabled={isProcessing} />

          <Button type="submit" fullWidth className="mt-2" disabled={isProcessing}>Continue</Button>
        </form>
      </div>

      <BottomSheet open={showPin} onClose={() => setShowPin(false)} title="Enter PIN" dismissible={!isProcessing}>
        <div className="px-2 pt-2 pb-4">
          <PinPad value={pin} onChange={handlePinChange} label="" error={pinError} disabled={isProcessing} />
        </div>
      </BottomSheet>
    </div>
  )
}
