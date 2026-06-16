import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { api } from '@/lib/api'
import { useAuthStore } from '@/store/auth.store'
import { useTenant } from '@/store/tenant.store'
import { PageHeader } from '@/components/layout/PageHeader'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'

function toE164(phone: string): string {
  const digits = phone.replace(/\D/g, '')
  if (digits.startsWith('0')) return '+234' + digits.slice(1)
  return '+' + digits
}

const schema = z.object({
  first_name: z.string().min(1, 'First name is required').max(80),
  last_name:  z.string().min(1, 'Last name is required').max(80),
  phone: z.string().regex(/^0[789]\d{9}$/, 'Enter a valid 11-digit phone number'),
  email: z.string().email('Enter a valid email address'),
  password: z.string().min(8, 'At least 8 characters'),
  confirmPassword: z.string().min(1, 'Please confirm your password'),
}).refine(data => data.password === data.confirmPassword, {
  message: 'Passwords do not match',
  path: ['confirmPassword'],
})
type FormData = z.infer<typeof schema>

export function RegisterScreen() {
  const navigate = useNavigate()
  const { setPending } = useAuthStore()
  const { tenantId } = useTenant()
  const [serverError, setServerError] = useState('')

  const { register, handleSubmit, watch, formState: { errors, isSubmitting } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: { first_name: '', last_name: '', phone: '', email: '', password: '', confirmPassword: '' },
    mode: 'onChange',
  })

  const password = watch('password')
  const confirmPassword = watch('confirmPassword')
  const passwordsMatch = confirmPassword.length > 0 && password === confirmPassword

  const onSubmit = async (data: FormData) => {
    setServerError('')
    try {
      // Sanctum SPA authentication requires fetching the CSRF cookie first.
      // Axios will then automatically send the XSRF-TOKEN as X-XSRF-TOKEN header.
      await api.get('/sanctum/csrf-cookie', { baseURL: import.meta.env.VITE_API_URL?.replace('/api', '') })
      await api.post('/auth/register', {
        tenantId,
        first_name: data.first_name,
        last_name: data.last_name,
        phone: toE164(data.phone),
        email: data.email,
        password: data.password,
      })
      localStorage.setItem('fixpay_onboarded', '1')
      setPending(data.phone, data.email)
      navigate('/auth/otp')
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setServerError(msg ?? 'Registration failed. Try again.')
    }
  }

  const confirmSuffix = confirmPassword.length > 0
    ? passwordsMatch
      ? <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#34C759" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
      : <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FF3B30" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
    : undefined

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Create Account" onBack="default" />
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">
        <p className="text-[15px] text-gray-500 mb-6">Join FixPay to send money and pay bills.</p>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="flex gap-3">
            <Input label="First Name" type="text" placeholder="Ada"
              error={errors.first_name?.message} {...register('first_name')} />
            <Input label="Last Name" type="text" placeholder="Obi"
              error={errors.last_name?.message} {...register('last_name')} />
          </div>
          <Input label="Phone Number" type="tel" placeholder="08012345678"
            error={errors.phone?.message} {...register('phone')} />
          <Input label="Email Address" type="email" placeholder="you@example.com"
            error={errors.email?.message} {...register('email')} />
          <Input label="Password" type="password" placeholder="At least 8 characters"
            error={errors.password?.message} {...register('password')} />
          <Input label="Confirm Password" type="password" placeholder="Re-enter your password"
            suffix={confirmSuffix}
            hint={passwordsMatch ? 'Passwords match' : undefined}
            error={errors.confirmPassword?.message} {...register('confirmPassword')} />
          {serverError && <p className="text-[14px] text-ios-red text-center">{serverError}</p>}
          <Button type="submit" fullWidth loading={isSubmitting} disabled={!passwordsMatch} className="mt-2">Continue</Button>
        </form>

        <p className="text-center text-[13px] text-gray-400 mt-6">
          Already have an account?{' '}
          <button className="font-semibold" style={{ color: 'var(--brand-primary)' }} onClick={() => navigate('/auth/login')}>Sign In</button>
        </p>
      </div>
    </div>
  )
}
