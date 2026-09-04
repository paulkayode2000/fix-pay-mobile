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
  const { pendingPhone, pendingEmail, setToken, setUser, pinCreated, kycCompleted } = useAuthStore()
  // Hardcoded to 1234 just for testing purposes in the cloud
  const [otp, setOtp] = useState('1234')
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
        otp, // compatibility fallback for mock
      })
      // Backend wraps in ApiResponse; MSW mocks return the flat payload
      const payload: { accessToken?: string; user?: User } = res.data.data ?? res.data
      if (payload.accessToken && payload.user) {
        // Mock mode returns token directly — continue to pin/kyc/home
        setToken(payload.accessToken)
        setUser(payload.user)
        if (!pinCreated) navigate('/auth/pin', { replace: true })
        else if (!kycCompleted) navigate('/kyc', { replace: true })
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

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Verify Email" onBack="default" />
      <div className="flex-1 flex flex-col items-center px-6 pt-8 gap-6 animate-slide-up">
        <p className="text-[15px] text-gray-500 text-center">
          Enter the 4-digit code sent to <strong className="text-gray-900">{display}</strong>
        </p>
        <OTPInput length={4} value={otp} onChange={setOtp} autoFocus />
        {error && <p className="text-[14px] text-ios-red text-center">{error}</p>}
        <Button fullWidth loading={loading} onClick={handleVerify}>Verify</Button>
        <button className="text-[14px]" style={{ color: 'var(--brand-primary)' }}>Resend code</button>
      </div>
    </div>
  )
}
