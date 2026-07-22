import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { motion, AnimatePresence } from 'motion/react'
import { CheckCircleIcon } from '@heroicons/react/24/solid'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { PageHeader } from '@/components/layout/PageHeader'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/utils'

type Method = 'NIN' | 'BVN' | 'Selfie'
const METHODS: Method[] = ['NIN', 'BVN', 'Selfie']

const ninSchema = z.object({ nin: z.string().length(11, 'NIN must be exactly 11 digits').regex(/^\d+$/, 'Digits only') })
const bvnSchema = z.object({
  bvn: z.string().length(11, 'BVN must be exactly 11 digits').regex(/^\d+$/, 'Digits only'),
  dob: z.string().min(1, 'Date of birth is required')
})

function NinStep({ onDone }: { onDone: () => void }) {
  const [err, setErr] = useState('')
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<{ nin: string }>({ resolver: zodResolver(ninSchema) })
  const onSubmit = async (data: { nin: string }) => {
    setErr('')
    try { await api.post('/kyc/nin', data); onDone() }
    catch { setErr('Could not verify NIN. Check the number and retry.') }
  }
  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
      <div className="text-center">
        <p className="text-[32px]">🪪</p>
        <h2 className="text-[18px] font-bold text-gray-900 mt-2">National Identity Number</h2>
        <p className="text-[12px] text-gray-500 mt-1">Enter your 11-digit NIN for identity verification.</p>
      </div>
      <Input label="NIN" type="tel" inputMode="numeric" maxLength={11} placeholder="12345678901"
        error={errors.nin?.message} {...register('nin')} />
      {err && <p className="text-ios-red text-[12px] text-center">{err}</p>}
      <Button type="submit" fullWidth loading={isSubmitting}>Verify NIN</Button>
      <p className="text-[11px] text-center text-gray-400">Demo: use any 11-digit number</p>
    </form>
  )
}

function BvnStep({ onDone }: { onDone: () => void }) {
  const [err, setErr] = useState('')
  const [awaiting, setAwaiting] = useState(false)
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<{ bvn: string; dob: string }>({ resolver: zodResolver(bvnSchema) })

  const startPolling = async (attempt = 0) => {
    const delays = [10000, 240000, 600000, 1200000, 1500000]
    if (attempt >= delays.length) {
      setErr('BVN verification timed out. Please try again.')
      setAwaiting(false)
      return
    }
    setTimeout(async () => {
      try {
        const res = await api.get('/kyc/status')
        const bvnStatus = res.data.verifications?.find((v: any) => v.type === 'BVN_CONSENT' || v.type === 'BVN')?.status
        if (bvnStatus === 'VERIFIED') {
          onDone()
        } else if (bvnStatus === 'FAILED') {
          setErr('BVN verification failed at NIBSS.')
          setAwaiting(false)
        } else {
          startPolling(attempt + 1)
        }
      } catch { startPolling(attempt + 1) }
    }, delays[attempt])
  }

  const onSubmit = async (data: { bvn: string; dob: string }) => {
    setErr('')
    try {
      const res = await api.post('/kyc/bvn/consent/initiate', data)
      if (res.data.status === 'PENDING') {
        setAwaiting(true)
        if (res.data.consentUrl) window.open(res.data.consentUrl, '_blank')
        startPolling(0)
      } else if (res.data.status === 'VERIFIED') {
        onDone()
      }
    }
    catch { setErr('Could not verify BVN. Check the number and retry.') }
  }

  if (awaiting) {
    return (
      <div className="flex flex-col items-center gap-4 pt-6">
        <p className="text-[32px]">⏳</p>
        <h2 className="text-[18px] font-bold text-gray-900">Awaiting NIBSS Response</h2>
        <p className="text-[12px] text-gray-500 text-center px-4">BVN validation is ongoing with NIBSS. You will be informed as soon as details are received.</p>
        <p className="text-[11px] text-gray-400 text-center px-4">Please complete the authentication on the NIBSS portal that opened in a new tab.</p>
        <div className="flex flex-col gap-2 w-full mt-2">
          <Button variant="outline" onClick={() => setAwaiting(false)} className="w-full">Cancel & Retry</Button>
        </div>
      </div>
    )
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
      <div className="text-center">
        <p className="text-[32px]">🏦</p>
        <h2 className="text-[18px] font-bold text-gray-900 mt-2">Bank Verification Number</h2>
        <p className="text-[12px] text-gray-500 mt-1">Enter your 11-digit BVN linked to your bank account.</p>
      </div>
      <Input label="Date of Birth" type="date" error={errors.dob?.message} {...register('dob')} />
      <Input label="BVN" type="tel" inputMode="numeric" maxLength={11} placeholder="00000000000"
        error={errors.bvn?.message} {...register('bvn')} />
      {err && <p className="text-ios-red text-[12px] text-center">{err}</p>}
      <Button type="submit" fullWidth loading={isSubmitting}>Verify BVN</Button>
      <p className="text-[11px] text-center text-gray-400">Demo: use any 11-digit number</p>
    </form>
  )
}

function SelfieStep({ onDone, loading }: { onDone: () => void; loading: boolean }) {
  return (
    <div className="flex flex-col items-center gap-4">
      <div className="text-center">
        <p className="text-[32px]">🤳</p>
        <h2 className="text-[18px] font-bold text-gray-900 mt-2">Selfie Verification</h2>
        <p className="text-[12px] text-gray-500 mt-1">Take a quick selfie to complete your identity check.</p>
      </div>
      <div className="w-32 h-32 rounded-full bg-gray-100 border-4 border-dashed border-gray-200 flex items-center justify-center">
        <span className="text-[48px]">📸</span>
      </div>
      <p className="text-[12px] text-gray-400 text-center">(Simulated in demo — tap button to proceed)</p>
      <Button fullWidth loading={loading} onClick={onDone}>Take Selfie & Continue</Button>
    </div>
  )
}

export function KycStepper() {
  const navigate = useNavigate()
  const { setKycCompleted, setKycDeferred } = useAuthStore()

  const [loadingStatus, setLoadingStatus] = useState(true)
  const [activeMethod, setActiveMethod] = useState<Method>('NIN')
  const [verifiedMethods, setVerifiedMethods] = useState<Set<Method>>(new Set())
  const [selfieLoading, setSelfieLoading] = useState(false)
  const [completing, setCompleting] = useState(false)

  useEffect(() => {
    async function checkStatus() {
      try {
        const res = await api.get('/kyc/status')
        const verifications: any[] = res.data.verifications || []
        const verified = new Set<Method>()
        if (verifications.some((v: any) => v.type === 'NIN' && v.status === 'VERIFIED')) verified.add('NIN')
        if (verifications.some((v: any) => (v.type === 'BVN' || v.type === 'BVN_CONSENT') && v.status === 'VERIFIED')) verified.add('BVN')
        if (verifications.some((v: any) => v.type === 'SELFIE' && v.status === 'VERIFIED')) verified.add('Selfie')
        setVerifiedMethods(verified)
        // Pick first unverified method, or default to NIN
        const unverified = METHODS.find(m => !verified.has(m))
        if (unverified) setActiveMethod(unverified)
      } catch {
        // use defaults
      } finally {
        setLoadingStatus(false)
      }
    }
    checkStatus()
  }, [])

  const handleMethodVerified = () => {
    setVerifiedMethods(prev => new Set(prev).add(activeMethod))
  }

  const handleSelfieComplete = async () => {
    setSelfieLoading(true)
    try {
      await new Promise(r => setTimeout(r, 800))
      // Determine tier based on verified methods
      const allVerified = verifiedMethods.has('NIN') && verifiedMethods.has('BVN') && verifiedMethods.has('Selfie')
      // If user verified at least one method + selfie, mark KYC as completed
      const hasPriorVerification = verifiedMethods.has('NIN') || verifiedMethods.has('BVN')
      if (hasPriorVerification || allVerified) {
        setKycCompleted(true)
        useAuthStore.getState().setKycDeferred(false)
      }
      setCompleting(true)
      setTimeout(() => navigate('/home', { replace: true }), 1500)
    } catch { /* ignore */ }
    finally { setSelfieLoading(false) }
  }

  const handleDone = () => {
    if (activeMethod === 'Selfie') {
      handleSelfieComplete()
    } else {
      handleMethodVerified()
    }
  }

  const handleDoneAndLeave = () => {
    setKycDeferred(true)
    navigate('/home')
  }

  if (loadingStatus) {
    return (
      <div className="h-[100dvh] flex items-center justify-center bg-[#F2F2F7]">
        <div className="w-6 h-6 rounded-full border-4 border-gray-200 border-t-brand animate-spin" />
      </div>
    )
  }

  if (completing) return (
    <div className="h-[100dvh] flex flex-col items-center justify-center gap-4 animate-scale-in">
      <CheckCircleIcon className="w-16 h-16 text-ios-green" />
      <h2 className="text-[20px] font-bold text-gray-900">Verification Complete!</h2>
      <p className="text-[13px] text-gray-500">Redirecting to your dashboard…</p>
    </div>
  )

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Verify Your Identity" onBack="default" />

      {/* Method tabs — user picks which verification to do */}
      <div className="flex gap-1 px-4 pb-4 shrink-0">
        {METHODS.map(m => {
          const isActive = activeMethod === m
          const isDone = verifiedMethods.has(m)
          return (
            <button
              key={m}
              onClick={() => setActiveMethod(m)}
              className={cn(
                'flex-1 py-2 rounded-full text-[12px] font-semibold transition-all pressable',
                isActive && 'text-white',
                isDone && !isActive && 'bg-green-50 text-ios-green border border-ios-green/20',
                !isActive && !isDone && 'bg-white text-gray-400 border border-black/5'
              )}
              style={isActive ? { background: 'var(--brand-primary)' } : undefined}
            >
              {isDone ? '✓ ' : ''}{m}
            </button>
          )
        })}
      </div>

      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pb-8">
        <AnimatePresence mode="wait">
          <motion.div key={activeMethod} initial={{ opacity: 0, x: 40 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -40 }}
            transition={{ duration: 0.25 }}>
            {activeMethod === 'NIN' && <NinStep onDone={handleDone} />}
            {activeMethod === 'BVN' && <BvnStep onDone={handleDone} />}
            {activeMethod === 'Selfie' && <SelfieStep onDone={handleDone} loading={selfieLoading} />}
          </motion.div>
        </AnimatePresence>

        {/* Skip / Continue Later */}
        <div className="mt-6 flex flex-col gap-2">
          <Button variant="ghost" onClick={handleDoneAndLeave} className="text-brand w-full">
            Continue Later — Skip Verification
          </Button>
        </div>

        {/* Tier info */}
        <div className="mt-6 bg-blue-50 rounded-[14px] p-4 border border-blue-100">
          <h3 className="text-[12px] font-bold text-blue-800 mb-2">KYC Tiers</h3>
          <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2">
              <span className="w-2 h-2 rounded-full bg-gray-400 shrink-0" />
              <p className="text-[11px] text-blue-700"><strong>Tier 1:</strong> No verification — ₦50,000/day limit</p>
            </div>
            <div className="flex items-center gap-2">
              <span className="w-2 h-2 rounded-full bg-ios-orange shrink-0" />
              <p className="text-[11px] text-blue-700"><strong>Tier 2:</strong> NIN or BVN verified — ₦200,000/day</p>
            </div>
            <div className="flex items-center gap-2">
              <span className="w-2 h-2 rounded-full bg-ios-green shrink-0" />
              <p className="text-[11px] text-blue-700"><strong>Tier 3:</strong> NIN + BVN + Selfie — ₦5,000,000/day</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}