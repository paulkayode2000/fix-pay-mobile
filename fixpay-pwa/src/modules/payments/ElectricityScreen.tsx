import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { CheckCircleIcon } from '@heroicons/react/24/solid'
import { queryClient } from '@/lib/query-client'
import type { BillerVerify } from '@/types'
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

const PROVIDERS = [
  { id: 'ikeja-electric',       label: 'Ikeja Electric',        short: 'IKEDC' },
  { id: 'eko-electric',         label: 'Eko Electricity',       short: 'EKEDC' },
  { id: 'abuja-electric',       label: 'Abuja Electric',        short: 'AEDC' },
  { id: 'kano-electric',        label: 'Kano Electricity',      short: 'KEDCO' },
  { id: 'enugu-electric',       label: 'Enugu Electric',        short: 'EEDC' },
  { id: 'ibadan-electric',      label: 'Ibadan Electric',       short: 'IBEDC' },
  { id: 'phed',                 label: 'Port Harcourt Elec.',   short: 'PHED' },
  { id: 'benin-electric',       label: 'Benin Electricity',     short: 'BEDC' },
  { id: 'kaduna-electric',      label: 'Kaduna Electric',       short: 'KAEDCO' },
  { id: 'jos-electric',         label: 'Jos Electricity',       short: 'JED' },
  { id: 'aba-electric',         label: 'Aba Electricity',       short: 'ABA' },
  { id: 'yola-electric',        label: 'Yola Electricity',      short: 'YEDC' },
]

const schema = z.object({
  serviceId:     z.string().min(1),
  billersCode:   z.string().min(10, 'Enter meter number').max(15),
  variationCode: z.enum(['prepaid', 'postpaid']),
  amount:        z.coerce.number().min(500, 'Minimum ₦500'),
})
type FormData = z.infer<typeof schema>

export function ElectricityScreen() {
  const navigate = useNavigate()
  const [showPin, setShowPin] = useState(false)
  const [pin, setPin] = useState('')
  const [pinError, setPinError] = useState('')
  const [verifyResult, setVerifyResult] = useState<BillerVerify | null>(null)
  const [verifying, setVerifying] = useState(false)
  const [verifyError, setVerifyError] = useState('')
  const [pending, setPending] = useState<FormData | null>(null)
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('wallet')
  const { isProcessing, startProcessing, stopProcessing } = useTransactionStore()

  const AMOUNTS = [500, 1000, 2000, 5000, 10000]

  const { register, handleSubmit, setValue, watch, formState: { errors }, getValues } = useForm<FormData>({
    resolver: zodResolver(schema) as Resolver<FormData>,
    defaultValues: { serviceId: 'ikeja-electric', billersCode: '', variationCode: 'prepaid', amount: 0 },
  })
  const serviceId = watch('serviceId')
  const variationCode = watch('variationCode')

  const handleVerify = async () => {
    const meter = getValues('billersCode')
    const type = getValues('variationCode')
    if (!meter || meter.length < 10) { setVerifyError('Enter meter number first'); return }
    setVerifying(true); setVerifyError(''); setVerifyResult(null)
    try {
      const res = await paymentsService.verify({ serviceId, billersCode: meter, type })
      setVerifyResult(res)
    } catch {
      setVerifyError('Meter not found. (Demo prepaid: 1111111111111, postpaid: 1010101010101)')
    } finally { setVerifying(false) }
  }

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
          billersCode: data.billersCode,
          variationCode: data.variationCode,
          amount: data.amount,
        })
        
        await new Promise(resolve => setTimeout(resolve, 2000))
        const res = await paymentsService.alternativeVerify(initRes.gateway_reference)
        
        queryClient.invalidateQueries({ queryKey: ['wallet'] })
        queryClient.invalidateQueries({ queryKey: ['transactions'] })

        const outcome = resolveVtpassCode(res.vtpass_code)
        const statePayload = {
          type: 'electricity',
          provider: data.serviceId,
          customerName: verifyResult?.customerName,
          meter: data.billersCode,
          meterType: data.variationCode,
          amount_kobo: res.amount_kobo,
          token: res.token,
          units: res.units,
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
      const res = await paymentsService.electricity(pending)
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })

      const outcome = resolveVtpassCode(res.vtpass_code)
      const statePayload = {
        type: 'electricity',
        provider: serviceId,
        customerName: verifyResult?.customerName,
        meter: pending.billersCode,
        meterType: variationCode,
        amount_kobo: res.amount_kobo,
        token: res.token,
        units: res.units,
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
      <PageHeader title="Electricity" onBack="default" />
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">

        {/* Provider selector */}
        <p className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Electricity Provider</p>
        <div className="grid grid-cols-3 gap-2 mb-4">
          {PROVIDERS.map(p => (
            <button key={p.id} onClick={() => { setValue('serviceId', p.id); setVerifyResult(null) }}
              className="py-3 bg-white rounded-[14px] border-2 text-[12px] font-bold text-gray-700 transition-all pressable"
              style={{ borderColor: serviceId === p.id ? 'var(--brand-primary)' : 'transparent' }}>
              {p.short}
            </button>
          ))}
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          {/* Meter type */}
          <div className="flex bg-white rounded-[12px] p-1 gap-1 shadow-sm">
            {(['prepaid', 'postpaid'] as const).map(t => (
              <button key={t} type="button" onClick={() => { setValue('variationCode', t); setVerifyResult(null) }}
                className="flex-1 py-2 rounded-[10px] text-[14px] font-semibold transition-all pressable"
                style={variationCode === t ? { background: 'var(--brand-primary)', color: 'white' } : { color: '#8E8E93' }}>
                {t.charAt(0).toUpperCase() + t.slice(1)}
              </button>
            ))}
          </div>

          {/* Meter number + verify */}
          <div>
            <Input label="Meter Number" type="tel" inputMode="numeric" placeholder="Enter meter number"
              error={errors.billersCode?.message} {...register('billersCode')} />
            <p className="text-[11px] text-gray-400 mt-1 px-1">Demo prepaid: 1111111111111 | postpaid: 1010101010101</p>
            <Button type="button" variant="outline" size="sm" className="mt-2 w-full" onClick={handleVerify} loading={verifying}>
              Verify Meter
            </Button>
          </div>

          {verifyError && <p className="text-ios-red text-[13px]">{verifyError}</p>}
          {verifyResult && (
            <div className="bg-green-50 rounded-[14px] px-4 py-3 flex gap-3 items-start">
              <CheckCircleIcon className="w-5 h-5 text-brand shrink-0 mt-0.5" />
              <div>
                <p className="font-semibold text-gray-900">{verifyResult.customerName}</p>
                {verifyResult.address && <p className="text-[12px] text-gray-500">{verifyResult.address}</p>}
              </div>
            </div>
          )}

          {/* Amount */}
          <div>
            <p className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Amount (₦)</p>
            <div className="grid grid-cols-5 gap-2 mb-3">
              {AMOUNTS.map(a => (
                <button key={a} type="button" onClick={() => setValue('amount', a)}
                  className="py-2 bg-white rounded-[12px] text-[12px] font-semibold text-gray-700 pressable shadow-sm">
                  {a >= 1000 ? `${a/1000}k` : a}
                </button>
              ))}
            </div>
            <Input label="" type="number" inputMode="numeric" placeholder="Or enter amount" prefix="₦"
              error={errors.amount?.message} {...register('amount')} />
          </div>

          <PaymentMethodSelector value={paymentMethod} onChange={setPaymentMethod} disabled={isProcessing} />

          <Button type="submit" fullWidth className="mt-2" disabled={!verifyResult || isProcessing}>Pay Now</Button>
        </form>
      </div>

      <BottomSheet open={showPin} onClose={() => setShowPin(false)} title="Enter PIN" dismissible={!isProcessing}>
        <div className="px-2 pt-2 pb-4">
          <PinPad value={pin} onChange={handlePinChange} error={pinError} disabled={isProcessing} />
        </div>
      </BottomSheet>
    </div>
  )
}
