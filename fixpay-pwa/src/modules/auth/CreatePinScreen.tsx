import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { PageHeader } from '@/components/layout/PageHeader'
import { PinPad } from '@/components/ui/PinPad'
import { Spinner } from '@/components/ui/Spinner'

type Step = 'create' | 'confirm'

export function CreatePinScreen() {
  const navigate = useNavigate()
  const { setPinCreated } = useAuthStore()
  const [step, setStep] = useState<Step>('create')
  const [firstPin, setFirstPin] = useState('')
  const [pin, setPin] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const handlePinChange = async (val: string) => {
    setPin(val); setError('')
    if (val.length < 6) return

    if (step === 'create') {
      setFirstPin(val)
      setTimeout(() => { setPin(''); setStep('confirm') }, 150)
      return
    }

    // Confirm step
    if (val !== firstPin) {
      setError("PINs don't match. Try again.")
      setTimeout(() => { setPin(''); setStep('create'); setFirstPin('') }, 1200)
      return
    }

    setLoading(true)
    try {
      await api.post('/auth/pin/set', { pin: val, pin_confirmation: val })
      setPinCreated(true)
      navigate('/home', { replace: true })
    } catch {
      setError('Failed to save PIN. Try again.')
    } finally {
      setLoading(false)
    }
  }

  if (loading) return (
    <div className="h-[100dvh] flex flex-col items-center justify-center gap-4">
      <Spinner size="lg" />
      <p className="text-gray-500">Saving PIN…</p>
    </div>
  )

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Create PIN" />
      <div className="flex-1 flex items-center justify-center animate-slide-up">
        <div className="w-full pt-4 pb-8">
          <PinPad
            value={pin}
            onChange={handlePinChange}
            label={step === 'create' ? 'Create a 6-digit PIN' : 'Confirm your PIN'}
            hint={step === 'create' ? 'This PIN protects every transaction' : 'Re-enter your PIN to confirm'}
            error={error}
          />
        </div>
      </div>
    </div>
  )
}