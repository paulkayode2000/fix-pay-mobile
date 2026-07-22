import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { PageHeader } from '@/components/layout/PageHeader'
import { OTPInput } from '@/components/ui/OTPInput'
import { Button } from '@/components/ui/Button'
import type { User } from '@/types'

export function OtpScreen() {
  const navigate = useNavigate()
  const { pendingPhone, pendingEmail, setToken, setUser, pinCreated } = useAuthStore()
  const [otp, setOtp] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const email = pendingEmail ?? ''
  const display = email || (pendingPhone ?? 'your email')

  const handleVerify = async () => {
    if (otp.length < 4) { setError('Enter the 4-digit code'); return }
    setError(''); setLoading(true)
    try {
      const res = await api.post('/auth/verify-otp', {
        identifier: email,
        purpose: 'verification',
        code: otp,
      })
      const payload: { accessToken?: string; user?: User } = res.data.data ?? res.data
      if (payload.accessToken && payload.user) {
        setToken(payload.accessToken)
        setUser(payload.user)
        if (!pinCreated) navigate('/auth/pin', { replace: true })
        else navigate('/home', { replace: true })
      } else {
        // Live mode — email verified, now sign in
        navigate('/auth/login', { replace: true, state: { verified: true } })
      }
    } catch {
      setError('Incorrect or expired OTP. Try again.')
    } finally {
      setLoading(false)
    }
  }

  const handleResend = async () => {
    setError('')
    try {
      await api.post('/auth/resend-otp', {
        identifier: email,
        purpose: 'verification',
      })
    } catch {
      setError('Could not resend code. Try again.')
    }
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Verify" onBack="default" />
      <div className="flex-1 flex flex-col items-center px-6 pt-8 gap-6 animate-slide-up">
        <p className="text-[13px] text-gray-500 text-center">
          Enter the 4-digit code sent to <strong className="text-gray-900">{display}</strong>
        </p>
        <OTPInput length={4} value={otp} onChange={setOtp} autoFocus />
        {error && <p className="text-[12px] text-ios-red text-center">{error}</p>}
        <Button fullWidth loading={loading} onClick={handleVerify}>Verify</Button>
        <button className="text-[13px]" style={{ color: 'var(--brand-primary)' }} onClick={handleResend}>Resend code</button>
      </div>
    </div>
  )
}