import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useQuery } from '@tanstack/react-query'
import { CheckCircleIcon, ShieldExclamationIcon } from '@heroicons/react/24/outline'
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { api } from '@/lib/api'
import { queryClient } from '@/lib/query-client'
import { formatCurrency } from '@/lib/utils'
import type { NipBank, NameEnquiry } from '@/types'
import { PageHeader } from '@/components/layout/PageHeader'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { useTransactionStore } from '@/store/transaction.store'
import { useAuthStore } from '@/store/auth.store'
import { PinPad } from '@/components/ui/PinPad'
import { Spinner } from '@/components/ui/Spinner'

const FEE = 5250 // ₦52.50 in kobo

// Tier 1 limit: ₦50,000 per day (5,000,000 kobo)
const TIER1_DAILY_LIMIT_KOBO = 5_000_000
// Tier 2 limit: ₦200,000 per day
const TIER2_DAILY_LIMIT_KOBO = 20_000_000
// Tier 3: full limit ₦5,000,000
const TIER3_DAILY_LIMIT_KOBO = 500_000_000

const schema = z.object({
  accountNumber: z.string().length(10, 'Account number must be 10 digits'),
  bankCode:      z.string().min(1, 'Select a bank'),
  amountKobo:    z.coerce.number().min(100_00, 'Minimum ₦100').max(5_000_000_00, 'Maximum exceeded'),
  narration:     z.string().max(50).optional(),
})
type FormData = z.infer<typeof schema>

export function SendScreen() {
  const navigate = useNavigate()
  const { user } = useAuthStore()
  const userTier = user?.tier ?? 1

  const dailyLimitKobo = userTier >= 3 ? TIER3_DAILY_LIMIT_KOBO
    : userTier >= 2 ? TIER2_DAILY_LIMIT_KOBO
    : TIER1_DAILY_LIMIT_KOBO

  const [showPin, setShowPin] = useState(false)
  const [pin, setPin] = useState('')
  const [pinError, setPinError] = useState('')
  const [nameEnquiry, setNameEnquiry] = useState<NameEnquiry | null>(null)
  const [enquiring, setEnquiring] = useState(false)
  const [enquiryError, setEnquiryError] = useState('')
  const [pending, setPending] = useState<FormData | null>(null)
  const { isProcessing, startProcessing, stopProcessing } = useTransactionStore()
  const [bankSearch, setBankSearch] = useState('')
  const [showBankSheet, setShowBankSheet] = useState(false)

  const { data: banks = [], isLoading: banksLoading } = useQuery<NipBank[]>({
    queryKey: ['banks'],
    queryFn: () => api.get('/transfers/banks').then(r => (r.data.data ?? r.data) as NipBank[]),
    staleTime: 10 * 60_000,
  })

  const filteredBanks = bankSearch
    ? banks.filter(b => b.bankName.toLowerCase().includes(bankSearch.toLowerCase()))
    : banks

  const { register, handleSubmit, setValue, watch, formState: { errors }, getValues } = useForm<FormData>({
    resolver: zodResolver(schema) as Resolver<FormData>,
    defaultValues: { accountNumber: '', bankCode: '', amountKobo: 0 },
  })
  const bankCode = watch('bankCode')
  const selectedBank = banks.find(b => b.bankCode === bankCode)

  const handleLookup = async () => {
    const acct = getValues('accountNumber')
    const bCode = getValues('bankCode')
    if (acct.length !== 10) { setEnquiryError('Enter 10-digit account number'); return }
    if (!bCode) { setEnquiryError('Select a bank first'); return }
    setEnquiring(true); setEnquiryError(''); setNameEnquiry(null)
    try {
      const res = await api.post('/transfers/verify-account', { accountNumber: acct, bankCode: bCode })
      setNameEnquiry(res.data.data ?? res.data)
    } catch {
      setEnquiryError('Could not verify account. (Demo: try 0123456789 or 1234567890)')
    } finally { setEnquiring(false) }
  }

  const onSubmit = (data: FormData) => { setPending(data); setPin(''); setPinError(''); setShowPin(true) }

  const handlePinChange = async (val: string) => {
    setPin(val); setPinError('')
    if (val.length < 6 || !pending || isProcessing) return
    startProcessing()
    try {
      await api.post('/auth/pin/verify', { pin: val })

      // Generate X-Idempotency-Key on the frontend
      const idempotencyKey = self.crypto.randomUUID()
      await api.post('/transfers/bank',
        { ...pending, narration: pending.narration ?? 'Transfer' },
        { headers: { 'X-Idempotency-Key': idempotencyKey } }
      )

      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      navigate('/home', { replace: true })
    } catch {
      setPinError('Incorrect PIN or transfer failed.')
      setPin('')
    } finally {
      stopProcessing()
    }
  }

  // Show upgrade prompt if user is below Tier 3
  const showUpgradePrompt = userTier < 3

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Send Money" />
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">

        {/* Tier upgrade prompt for non-verified users */}
        {showUpgradePrompt && (
          <div className="bg-blue-50 rounded-[14px] p-4 mb-4 flex gap-3 items-start border border-blue-100">
            <ShieldExclamationIcon className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
            <div className="flex-1 min-w-0">
              <p className="text-[12px] font-semibold text-blue-800">
                {userTier === 1 ? 'Tier 1 — Limited Transfers' : 'Tier 2 — Verified User'}
              </p>
              <p className="text-[11px] text-blue-700 mt-1">
                Your daily transfer limit is <strong>{formatCurrency(dailyLimitKobo)}</strong>.
                {userTier === 1 && ' Complete BVN or NIN verification to unlock ₦200,000/day.'}
                {userTier === 2 && ' Complete full KYC to unlock ₦5,000,000/day.'}
              </p>
              {userTier < 3 && (
                <button
                  onClick={() => navigate('/kyc')}
                  className="mt-2 text-[11px] font-semibold"
                  style={{ color: 'var(--brand-primary)' }}
                >
                  Upgrade KYC Tier →
                </button>
              )}
            </div>
          </div>
        )}

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">

          {/* Bank selector */}
          <div>
            <p className="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Bank</p>
            <button type="button" onClick={() => setShowBankSheet(true)}
              className="w-full h-[48px] bg-white rounded-[12px] px-4 flex items-center justify-between shadow-sm border border-black/5 pressable">
              {banksLoading ? <Spinner size="sm" /> : (
                <>
                  <span className={selectedBank ? 'text-[14px] text-gray-900' : 'text-[14px] text-gray-400'}>{selectedBank?.bankName ?? 'Select Bank'}</span>
                  <MagnifyingGlassIcon className="w-4 h-4 text-gray-400" />
                </>
              )}
            </button>
            {errors.bankCode && <p className="text-ios-red text-[12px] mt-1 px-1">{errors.bankCode.message}</p>}
          </div>

          {/* Account number + lookup */}
          <div>
            <Input label="Account Number" type="tel" inputMode="numeric" maxLength={10} placeholder="0123456789"
              error={errors.accountNumber?.message} {...register('accountNumber')} />
            <p className="text-[10px] text-gray-400 mt-1 px-1">Demo: 0123456789</p>
            <Button type="button" variant="outline" size="sm" className="mt-2 w-full" onClick={handleLookup} loading={enquiring}>
              Verify Account
            </Button>
          </div>

          {enquiryError && <p className="text-ios-red text-[12px]">{enquiryError}</p>}
          {nameEnquiry && (
            <div className="bg-green-50 rounded-[14px] px-4 py-3 flex gap-3 items-center">
              <CheckCircleIcon className="w-4 h-4 text-green-600 shrink-0" />
              <p className="font-bold text-[14px] text-gray-900">{nameEnquiry.accountName}</p>
            </div>
          )}

          {/* Amount */}
          <Input label="Amount (₦)" type="number" inputMode="numeric" placeholder="5000" prefix="₦"
            error={errors.amountKobo?.message} {...register('amountKobo')} />
          {watch('amountKobo') > 0 && (
            <p className="text-[12px] text-gray-400 -mt-3 px-1">
              Total: {formatCurrency(watch('amountKobo') * 100 + FEE)} (incl. ₦52.50 fee)
            </p>
          )}

          <Input label="Narration (optional)" type="text" placeholder="Transfer description" {...register('narration')} />

          <Button type="submit" fullWidth className="mt-2" disabled={!nameEnquiry || isProcessing}>Send Money</Button>
        </form>
      </div>

      {/* Bank search sheet */}
      <BottomSheet open={showBankSheet} onClose={() => setShowBankSheet(false)} title="Select Bank" height="full">
        <div className="px-4 pt-2 pb-4">
          <Input type="text" placeholder="Search banks…" value={bankSearch} onChange={e => setBankSearch(e.target.value)} />
          <div className="mt-3 bg-white rounded-[16px] overflow-hidden">
            {filteredBanks.map((b, i) => (
              <button key={b.bankCode} type="button"
                className={`w-full flex items-center px-4 py-3.5 pressable active:bg-gray-50 ${i < filteredBanks.length - 1 ? 'border-b border-black/5' : ''}`}
                onClick={() => { setValue('bankCode', b.bankCode); setNameEnquiry(null); setShowBankSheet(false); setBankSearch('') }}>
                <span className="text-[14px] font-medium text-gray-900">{b.bankName}</span>
                {bankCode === b.bankCode && <CheckCircleIcon className="w-4 h-4 ml-auto shrink-0" style={{ color: 'var(--brand-primary)' }} />}
              </button>
            ))}
          </div>
        </div>
      </BottomSheet>

      <BottomSheet open={showPin} onClose={() => setShowPin(false)} title="Enter PIN" dismissible={!isProcessing}>
        <div className="px-2 pt-2 pb-4">
          <PinPad value={pin} onChange={handlePinChange} error={pinError} disabled={isProcessing} />
        </div>
      </BottomSheet>
    </div>
  )
}