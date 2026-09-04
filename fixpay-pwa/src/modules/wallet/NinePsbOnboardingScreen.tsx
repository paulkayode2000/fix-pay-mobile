import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { CheckCircleIcon } from '@heroicons/react/24/solid'
import { PageHeader } from '@/components/layout/PageHeader'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { api } from '@/lib/api'
import { queryClient } from '@/lib/query-client'

interface TermsData {
  title: string
  content: string
  version: string
  last_updated: string
}

export function NinePsbOnboardingScreen() {
  const navigate = useNavigate()
  const [terms, setTerms] = useState<TermsData | null>(null)
  const [accepted, setAccepted] = useState(false)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [successData, setSuccessData] = useState<{
    account_number: string
    bank: string
    note?: string
  } | null>(null)

  useEffect(() => {
    const fetchTerms = async () => {
      try {
        const res = await api.get('/wallet/ninepsb/terms')
        setTerms(res.data.data)
      } catch {
        setError('Failed to load terms. Please try again.')
      } finally {
        setLoading(false)
      }
    }
    fetchTerms()
  }, [])

  const handleCreateWallet = async () => {
    if (!accepted) return

    setSubmitting(true)
    setError('')

    try {
      // Generate device ID from browser fingerprint
      const deviceId = [
        navigator.userAgent,
        navigator.hardwareConcurrency,
        screen.width + 'x' + screen.height,
        navigator.language,
      ].join('|')

      // Get geolocation
      const position = await new Promise<GeolocationPosition>((resolve, reject) => {
        if (!navigator.geolocation) {
          reject(new Error('Location not available'))
          return
        }
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          timeout: 10000,
          maximumAge: 60000,
        })
      })

      const lat = position.coords.latitude
      const lng = position.coords.longitude

      const idempotencyKey = self.crypto.randomUUID()

      const res = await api.post('/wallet/ninepsb/create', 
        { terms_accepted: true },
        {
          headers: {
            'X-Device-ID': deviceId,
            'X-Location-Lat': String(lat),
            'X-Location-Lng': String(lng),
            'X-Idempotency-Key': idempotencyKey,
          },
        }
      )

      // Invalidate wallet queries so the wallet screen refreshes
      queryClient.invalidateQueries({ queryKey: ['wallet'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['kyc'] })

      const data = res.data.data
      // Show success confirmation before redirecting
      setSuccessData({
        account_number: data.account_number,
        bank: data.bank,
      })

      // Auto-redirect to dashboard after 2.5s
      setTimeout(() => {
        navigate('/home', { 
          replace: true, 
          state: { 
            wallet_created: true, 
            account_number: data.account_number,
            bank: data.bank,
          } 
        })
      }, 2500)
    } catch (err: any) {
      const status = err?.response?.status
      const data = err?.response?.data

      // 409 means BVN already has a wallet — treat as success with existing account
      if (status === 409 && data?.data?.account_number) {
        queryClient.invalidateQueries({ queryKey: ['wallet'] })
        queryClient.invalidateQueries({ queryKey: ['transactions'] })
        setSuccessData({
          account_number: data.data.account_number,
          bank: '9 Payment Service Bank',
          note: data.data.note || 'Wallet already exists on 9PSB.',
        })

        setTimeout(() => {
          navigate('/home', {
            replace: true,
            state: {
              wallet_created: true,
              account_number: data.data.account_number,
              bank: '9 Payment Service Bank',
              note: data.data.note || 'Wallet already exists on 9PSB.',
            },
          })
        }, 2500)
        return
      }

      const message = data?.message || 'Failed to create wallet. Please try again.'
      setError(message)
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
        <PageHeader title="9PSB Wallet" />
        <div className="flex-1 flex items-center justify-center">
          <Spinner size="lg" />
          <p className="text-[13px] text-gray-500 ml-3">Loading terms...</p>
        </div>
      </div>
    )
  }

  // Success confirmation screen — shown after wallet creation before redirect
  if (successData) {
    return (
      <div className="h-[100dvh] flex flex-col items-center justify-center bg-[#F2F2F7] px-6 animate-scale-in">
        <div className="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mb-4">
          <CheckCircleIcon className="w-12 h-12 text-ios-green" />
        </div>
        <h2 className="text-[22px] font-bold text-gray-900 mb-1">Wallet Created!</h2>
        {successData.note && (
          <p className="text-[13px] text-ios-orange bg-orange-50 px-3 py-1.5 rounded-full mb-3">{successData.note}</p>
        )}
        <div className="bg-white rounded-[16px] p-5 shadow-sm border border-black/5 w-full max-w-sm mb-4">
          <p className="text-[12px] text-gray-400 uppercase tracking-wide mb-2">Your NUBAN Account</p>
          <p className="text-[28px] font-bold text-gray-900 tracking-[2px] mb-1">{successData.account_number}</p>
          <p className="text-[13px] text-gray-500">{successData.bank}</p>
        </div>
        <p className="text-[13px] text-gray-400 mb-2">Your wallet is ready — balance starts at ₦0.00</p>
        <p className="text-[12px] text-gray-400">Redirecting to dashboard…</p>
        <div className="mt-4 w-10 h-1 bg-gray-200 rounded-full overflow-hidden">
          <div className="h-full bg-brand rounded-full animate-shrink" />
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="9PSB Wallet" onBack="default" />

      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">
        {error && !submitting && (
          <div className="bg-red-50 border border-red-200 rounded-[14px] p-4 mb-4">
            <p className="text-[13px] text-red-700">{error}</p>
          </div>
        )}

        {/* Terms & Conditions */}
        <div className="bg-white rounded-[20px] p-5 shadow-sm border border-black/5 mb-5">
          <h2 className="text-[16px] font-bold text-gray-900 mb-1">{terms?.title || 'Terms & Conditions'}</h2>
          {terms?.version && (
            <p className="text-[11px] text-gray-400 mb-4">Version {terms.version} • Last updated {terms.last_updated}</p>
          )}
          
          <div className="max-h-[400px] overflow-y-auto text-[13px] text-gray-600 leading-relaxed whitespace-pre-line pr-2 
            [&::-webkit-scrollbar]:w-1 [&::-webkit-scrollbar-track]:bg-gray-100 [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full">
            {terms?.content || 'Loading...'}
          </div>
        </div>

        {/* Consent checkbox */}
        <div className="bg-white rounded-[16px] p-4 shadow-sm border border-black/5 mb-5">
          <label className="flex items-start gap-3 cursor-pointer pressable">
            <div className="relative mt-0.5">
              <input
                type="checkbox"
                checked={accepted}
                onChange={(e) => setAccepted(e.target.checked)}
                className="sr-only"
              />
              <div className={`w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all 
                ${accepted ? 'border-transparent' : 'border-gray-300'}`}
                style={accepted ? { background: 'var(--brand-primary)' } : undefined}>
                {accepted && (
                  <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                  </svg>
                )}
              </div>
            </div>
            <span className="text-[13px] text-gray-700 leading-relaxed flex-1">
              I have read and agree to the <strong>9 Payment Service Bank (9PSB) Wallet Terms & Conditions</strong>. 
              I understand that my BVN/NIN will be used to open a wallet account and that I am bound by the terms outlined above.
            </span>
          </label>
        </div>

        {/* CTA */}
        <Button
          fullWidth
          disabled={!accepted || submitting}
          onClick={handleCreateWallet}
          loading={submitting}
          className="text-[15px] h-[52px]"
        >
          {submitting ? 'Creating Wallet...' : 'Open 9PSB Wallet'}
        </Button>

        <p className="text-[11px] text-gray-400 text-center mt-3 px-4">
          By opening a wallet, your temporary balance and history will be cleared and replaced with your new 9PSB account. 
          This cannot be undone. Location access is required for security purposes.
        </p>
      </div>
    </div>
  )
}