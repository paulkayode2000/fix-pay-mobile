import { useState } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { PageHeader } from '@/components/layout/PageHeader'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'

import type { User } from '@/types'

const schema = z.object({
  identifier: z.string().min(5, 'Enter phone or email'),
  password:   z.string().min(4, 'Enter your password'),
})
type FormData = z.infer<typeof schema>

export function LoginScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const verified = (location.state as { verified?: boolean } | null)?.verified === true
  const { setToken, setUser, setPinCreated, setKycCompleted } = useAuthStore()
  const [serverError, setServerError] = useState('')

  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: { identifier: '', password: '' },
  })

  const onSubmit = async (data: FormData) => {
    setServerError('')
    try {
      // Sanctum CSRF cookie — direct fetch to /sanctum (Vite proxy forwards to Laravel)
      await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
      const res = await api.post('/auth/login', {
        identifier: data.identifier,
        password: data.password,
      })
      const raw = res.data.data ?? res.data
      const accessToken: string = raw.access_token ?? raw.accessToken
      const rawUser = raw.user
      const user: User = {
        id:        rawUser.id,
        phone:     rawUser.phone,
        email:     rawUser.email,
        firstName: rawUser.first_name ?? rawUser.firstName,
        lastName:  rawUser.last_name  ?? rawUser.lastName,
        tier:      rawUser.tier,
        kycStatus: rawUser.kyc_status ?? rawUser.kycStatus,
        createdAt: rawUser.created_at ?? rawUser.createdAt,
      }
      setToken(accessToken)
      setUser(user)

      const hasPinFromServer: boolean = raw.has_pin === true
      const isKycVerified = user.kycStatus === 'verified'
      setPinCreated(hasPinFromServer)
      setKycCompleted(isKycVerified)

      const tenantSlug: string | null = raw.tenant_slug ?? null
      if (tenantSlug) {
        localStorage.setItem('tenant_slug', tenantSlug)
      } else {
        localStorage.removeItem('tenant_slug')
      }
      localStorage.setItem('fixpay_onboarded', '1')

      if (hasPinFromServer) navigate('/home', { replace: true })
      else                   navigate('/auth/pin', { replace: true })
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setServerError(msg ?? 'Invalid credentials. Try again.')
    }
  }

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Sign In" onBack="default" />
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">
        {verified && (
          <p className="text-[13px] bg-green-50 text-green-700 rounded-[10px] px-3 py-2 text-center mb-4">
            Email verified! Sign in to continue.
          </p>
        )}
        <p className="text-[13px] text-gray-500 mb-6">Enter your phone or email to continue.</p>
        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" autoComplete="new-password">
          <input type="text" name="fakeusername" style={{ display: 'none' }} aria-hidden="true" tabIndex={-1} readOnly />
          <input type="password" name="fakepassword" style={{ display: 'none' }} aria-hidden="true" tabIndex={-1} readOnly />
          <Input label="Phone or Email" type="text" placeholder="Enter phone or email"
            autoComplete="new-password" error={errors.identifier?.message} {...register('identifier')} />
          <Input label="Password" type="password" placeholder="Enter password"
            autoComplete="new-password" error={errors.password?.message} {...register('password')} />
          {serverError && <p className="text-[13px] text-ios-red text-center">{serverError}</p>}
          <Button type="submit" fullWidth loading={isSubmitting} className="mt-2">Sign In</Button>
        </form>
        <p className="text-center text-[13px] text-gray-400 mt-6">
          Don't have an account?{' '}
          <button className="font-semibold" style={{ color: 'var(--brand-primary)' }} onClick={() => navigate('/auth/register')}>Create One</button>
        </p>
      </div>
    </div>
  )
}