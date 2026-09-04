import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { CheckCircleIcon } from '@heroicons/react/24/solid'
import { queryClient } from '@/lib/query-client'
import type { ServiceVariation, BillerVerify } from '@/types'
import { paymentsService } from '@/lib/services/payments.service'
import { authService } from '@/lib/services/auth.service'
import { PageHeader } from '@/components/layout/PageHeader'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { useTransactionStore } from '@/store/transaction.store'
import { PinPad } from '@/components/ui/PinPad'
import { Spinner } from '@/components/ui/Spinner'
import { resolveVtpassCode } from '@/lib/vtpass-codes'
import { PaymentMethodSelector, type PaymentMethod } from '@/components/feature/PaymentMethodSelector'

const parseAmount = (amt: string | number | undefined | null): number => {
  if (!amt) return 0;
  const parsed = typeof amt === 'number' ? amt : parseFloat(amt);
  return isNaN(parsed) ? 0 : parsed;
};

const PROVIDERS = [
  { id: 'dstv',      label: 'DStv',      bg: '#0072CE' },
  { id: 'gotv',      label: 'GOtv',      bg: '#E37022' },
  { id: 'startimes', label: 'Startimes', bg: '#E60000' },
  { id: 'showmax',   label: 'ShowMax',   bg: '#E50914' },
]

const schema = z.object({
  serviceId:     z.string().min(1),
  billersCode:   z.string().min(6, 'Enter smartcard number'),
  variationCode: z.string().min(1, 'Select a package'),
  subscriptionType: z.enum(['renew', 'change']),
})
type FormData = z.infer<typeof schema>

export function TvScreen() {
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

  const { register, handleSubmit, setValue, watch, formState: { errors }, getValues } = useForm<FormData>({
    resolver: zodResolver(schema) as Resolver<FormData>,
    defaultValues: { serviceId: 'dstv', billersCode: '', variationCode: '', subscriptionType: 'renew' },
  })
  const serviceId = watch('serviceId')
  const variationCode = watch('variationCode')
  const subscriptionType = watch('subscriptionType')
  const isShowMax = serviceId === 'showmax'

  const { data: variations = [], isLoading: varsLoading } = useQuery<ServiceVariation[]>({
    queryKey: ['variations', serviceId],
    queryFn: () => paymentsService.getVariations(serviceId),
  })
  const chosen = variations.find(v => (v.variationCode ?? (v as any).variation_code) === variationCode)

  const handleVerify = async () => {
    const smartcard = getValues('billersCode')
    if (!smartcard || smartcard.length < 6) { setVerifyError('Enter smartcard number first'); return }
    setVerifying(true); setVerifyError(''); setVerifyResult(null)
    try {
      const res = await paymentsService.verify({ serviceId, billersCode: smartcard })
      setVerifyResult(res)
    } catch {
      setVerifyError('Smartcard not found. Check and retry. (Demo: use 1212121212)')
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
        const chosenAmountNaira = parseAmount(chosen?.variationAmount ?? (chosen as any)?.variation_amount ?? '0')
        const amount = subscriptionType === 'renew'
          ? (verifyResult?.renewalAmount ?? chosenAmountNaira)
          : chosenAmountNaira

        const initRes = await paymentsService.alternativeInitiate({
          paymentMethod,
          serviceId: data.serviceId,
          billersCode: data.billersCode,
          variationCode: data.variationCode,
          subscriptionType: data.subscriptionType,
          amount,
        })
        
        await new Promise(resolve => setTimeout(resolve, 2000))
        const res = await paymentsService.alternativeVerify(initRes.gateway_reference)
        
        queryClient.invalidateQueries({ queryKey: ['wallet'] })
        queryClient.invalidateQueries({ queryKey: ['transactions'] })

        const outcome = resolveVtpassCode(res.vtpass_code)
        const statePayload = {
          type: 'tv',
          provider: data.serviceId,
          customerName: verifyResult?.customerName,
          smartcard: data.billersCode,
          package: chosen?.name ?? 'Renewal',
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
      // Always resolve a non-zero amount: renew uses verify result price, change uses variation price
      const chosenAmountNaira = parseAmount(chosen?.variationAmount ?? (chosen as any)?.variation_amount ?? '0')
      const amount = subscriptionType === 'renew'
        ? (verifyResult?.renewalAmount ?? chosenAmountNaira)
        : chosenAmountNaira
      const res = await paymentsService.tv({
        serviceId: pending.serviceId,
        billersCode: pending.billersCode,
        variationCode: pending.variationCode,
        subscriptionType: pending.subscriptionType,
        amount,
      })
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })

      const outcome = resolveVtpassCode(res.vtpass_code)
      const statePayload = {
        type: 'tv',
        provider: serviceId,
        customerName: verifyResult?.customerName,
        smartcard: pending.billersCode,
        package: chosen?.name ?? 'Renewal',
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
      <PageHeader title="TV / Cable" onBack="default" />
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">

        {/* Provider */}
        <p className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Provider</p>
        <div className="grid grid-cols-2 gap-2 mb-5">
          {PROVIDERS.map(p => (
            <button key={p.id} onClick={() => { setValue('serviceId', p.id); setValue('variationCode', ''); setVerifyResult(null) }}
              className="py-3 rounded-[14px] border-2 text-white text-[13px] font-bold transition-all pressable"
              style={{ borderColor: serviceId === p.id ? 'var(--brand-primary)' : 'transparent', background: p.bg }}>
              {p.label}
            </button>
          ))}
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          {/* Smartcard or phone */}
          {isShowMax ? (
            <Input label="Phone Number" type="tel" inputMode="tel" placeholder="08012345678"
              error={errors.billersCode?.message} {...register('billersCode')} />
          ) : (
            <div>
              <Input label="Smartcard / IUC Number" type="tel" inputMode="numeric" placeholder="e.g. 1212121212"
                error={errors.billersCode?.message} {...register('billersCode')} />
              <p className="text-[11px] text-gray-400 mt-1 px-1">Demo: use 1212121212</p>
              <Button type="button" variant="outline" size="sm" className="mt-2 w-full" onClick={handleVerify} loading={verifying}>
                Verify Smartcard
              </Button>
            </div>
          )}

          {!isShowMax && verifyError && <p className="text-ios-red text-[13px]">{verifyError}</p>}
          {!isShowMax && verifyResult && (
            <div className="bg-green-50 rounded-[14px] px-4 py-3 flex gap-3 items-start">
              <CheckCircleIcon className="w-5 h-5 text-brand shrink-0 mt-0.5" />
              <div>
                <p className="font-semibold text-gray-900">{verifyResult.customerName}</p>
                {verifyResult.currentBouquet && <p className="text-[12px] text-gray-500">Current: {verifyResult.currentBouquet}</p>}
              </div>
            </div>
          )}

          {/* Subscription type */}
          {!isShowMax && (
            <div className="flex bg-white rounded-[12px] p-1 gap-1 shadow-sm">
              {(['renew', 'change'] as const).map(t => (
                <button key={t} type="button" onClick={() => { setValue('subscriptionType', t); setValue('variationCode', '') }}
                  className="flex-1 py-2 rounded-[10px] text-[14px] font-semibold transition-all pressable"
                  style={subscriptionType === t ? { background: 'var(--brand-primary)', color: 'white' } : { color: '#8E8E93' }}>
                  {t === 'renew' ? 'Renew' : 'Change Plan'}
                </button>
              ))}
            </div>
          )}

          {/* Package (for change or ShowMax) */}
          {(subscriptionType === 'change' || isShowMax) && (
            <div>
              <p className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Select Package</p>
              {varsLoading ? <Spinner /> : (
                <div className="flex flex-col gap-2 max-h-[240px] overflow-y-auto pr-1">
                  {variations.map(v => {
                    const code = v.variationCode ?? (v as any).variation_code
                    const amount = v.variationAmount ?? (v as any).variation_amount
                    return (
                      <button key={code} type="button" onClick={() => setValue('variationCode', code)}
                        className="flex items-center justify-between bg-white rounded-[14px] px-4 py-3 border-2 transition-all pressable"
                        style={{ borderColor: variationCode === code ? 'var(--brand-primary)' : 'transparent' }}>
                        <span className="text-[15px] font-medium text-gray-800 text-left mr-2">{v.name}</span>
                        <span className="text-[15px] font-bold shrink-0" style={{ color: 'var(--brand-primary)' }}>₦{parseAmount(amount).toLocaleString()}</span>
                      </button>
                    )
                  })}
                </div>
              )}
              {errors.variationCode && <p className="text-ios-red text-[13px] mt-1">{errors.variationCode.message}</p>}
            </div>
          )}

          <PaymentMethodSelector value={paymentMethod} onChange={setPaymentMethod} disabled={isProcessing} />

          <Button type="submit" fullWidth className="mt-2" disabled={(!isShowMax && !verifyResult) || isProcessing}>
            {isShowMax && chosen
              ? `Pay ₦${parseAmount(chosen.variationAmount ?? (chosen as any).variation_amount).toLocaleString()}`
              : verifyResult && subscriptionType === 'renew'
                ? `Pay ₦${(verifyResult.renewalAmount ?? 0).toLocaleString()}`
                : chosen ? `Pay ₦${parseAmount(chosen.variationAmount ?? (chosen as any).variation_amount).toLocaleString()}` : 'Continue'}
          </Button>
        </form>
      </div>

      <BottomSheet open={showPin} onClose={() => setShowPin(false)} title="Enter PIN" dismissible={!isProcessing}>
        <div className="px-2 pt-2 pb-4">
          <PinPad value={pin} onChange={handlePinChange} error={pinError} disabled={isProcessing} scrambled />
        </div>
      </BottomSheet>
    </div>
  )
}
