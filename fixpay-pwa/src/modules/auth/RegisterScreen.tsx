import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline'
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
  password: z.string()
    .min(8, 'At least 8 characters')
    .regex(/[A-Z]/, 'At least one uppercase letter')
    .regex(/[a-z]/, 'At least one lowercase letter')
    .regex(/[0-9]/, 'At least one number')
    .regex(/[^A-Za-z0-9]/, 'At least one special character'),
  confirmPassword: z.string().min(1, 'Please confirm your password'),
}).refine(data => data.password === data.confirmPassword, {
  message: 'Passwords do not match',
  path: ['confirmPassword'],
})
type FormData = z.infer<typeof schema>

function PasswordStrength({ password }: { password: string }) {
  const checks = [
    { label: '8+ characters', met: password.length >= 8 },
    { label: 'Uppercase', met: /[A-Z]/.test(password) },
    { label: 'Lowercase', met: /[a-z]/.test(password) },
    { label: 'Number', met: /[0-9]/.test(password) },
    { label: 'Special char', met: /[^A-Za-z0-9]/.test(password) },
  ]
  const score = checks.filter(c => c.met).length
  const barWidth = `${(score / checks.length) * 100}%`
  const color = score <= 2 ? '#FF3B30' : score <= 4 ? '#FF9500' : '#34C759'

  if (!password) return null

  return (
    <div className="mt-1">
      <div className="h-1 w-full bg-gray-200 rounded-full overflow-hidden mb-2">
        <div className="h-full rounded-full transition-all duration-300" style={{ width: barWidth, background: color }} />
      </div>
      <div className="flex flex-wrap gap-x-3 gap-y-0.5">
        {checks.map(c => (
          <span key={c.label} className="text-[10px] flex items-center gap-1" style={{ color: c.met ? '#34C759' : '#8E8E93' }}>
            {c.met ? '✓' : '○'} {c.label}
          </span>
        ))}
      </div>
    </div>
  )
}

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
      // Sanctum CSRF cookie — direct fetch to /sanctum (Vite proxy forwards to Laravel)
      await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
      await api.post('/auth/register', {
        tenantId,
        first_name: data.first_name,
        last_name: data.last_name,
        phone: toE164(data.phone),
        email: data.email,
        password: data.password,
      })
      localStorage.setItem('fixpay_onboarded', '1')
      setPending(toE164(data.phone), data.email)
      navigate('/auth/otp')
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setServerError(msg ?? 'Registration failed. Try again.')
    }
  }

  const confirmSuffix = confirmPassword.length > 0
    ? passwordsMatch
      ? <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#34C759" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
      : <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FF3B30" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
    : undefined

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Create Account" onBack="default" />
      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-8 animate-slide-up">
        <p className="text-[13px] text-gray-500 mb-6">Join FixPay to send money and pay bills.</p>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="flex gap-3">
            <Input label="First Name" type="text" placeholder="Ada"
              error={errors.first_name?.message} {...register('first_name')} />
            <Input label="Last Name" type="text" placeholder="Obi"
              error={errors.last_name?.message} {...register('last_name')} />
          </div>

          <div className="bg-amber-50 rounded-[14px] px-3 py-2.5 flex gap-2 items-start border border-amber-100">
            <ExclamationTriangleIcon className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
            <p className="text-[11px] text-amber-800 leading-relaxed">
              Please enter your name <strong>exactly</strong> as it appears on your BVN and NIN records to avoid verification issues later.
            </p>
          </div>

          <Input label="Phone Number" type="tel" placeholder="08012345678"
            error={errors.phone?.message} {...register('phone')} />
          <Input label="Email Address" type="email" placeholder="you@example.com"
            error={errors.email?.message} {...register('email')} />
          <div>
            <Input label="Password" type="password" placeholder="At least 8 characters"
              error={errors.password?.message} {...register('password')} />
            <PasswordStrength password={password} />
          </div>
          <Input label="Confirm Password" type="password" placeholder="Re-enter your password"
            suffix={confirmSuffix}
            hint={passwordsMatch ? 'Passwords match' : undefined}
            error={errors.confirmPassword?.message} {...register('confirmPassword')} />
          {serverError && <p className="text-[13px] text-ios-red text-center">{serverError}</p>}
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