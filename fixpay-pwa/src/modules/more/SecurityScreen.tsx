import { useState } from 'react'
import {
  ShieldCheckIcon, LockClosedIcon, FaceSmileIcon,
  DevicePhoneMobileIcon, ChevronRightIcon, EyeSlashIcon,
} from '@heroicons/react/24/outline'
import { CheckCircleIcon } from '@heroicons/react/24/solid'
import { AnimatePresence, motion } from 'motion/react'
import { useAuthStore } from '@/store/auth.store'
import { authService } from '@/lib/services/auth.service'
import { PageHeader } from '@/components/layout/PageHeader'
import { PinPad } from '@/components/ui/PinPad'
import { Spinner } from '@/components/ui/Spinner'
import { cn, vibrate } from '@/lib/utils'

// ─── Local types ─────────────────────────────────────────────────────────────

type PinFlow = 'idle' | 'verify-current' | 'set-new' | 'confirm-new' | 'success'

// ─── Toggle (iOS-style) ──────────────────────────────────────────────────────

function Toggle({ value, onChange }: { value: boolean; onChange: (v: boolean) => void }) {
  return (
    <button
      role="switch"
      aria-checked={value}
      onClick={() => { onChange(!value); vibrate(10) }}
      className={cn(
        'relative w-12 h-7 rounded-full transition-colors duration-200 shrink-0 pressable',
        value ? 'bg-ios-green' : 'bg-ios-gray4'
      )}
    >
      <span className={cn(
        'absolute top-0.5 w-6 h-6 bg-white rounded-full shadow-md transition-transform duration-200',
        value ? 'translate-x-5' : 'translate-x-0.5'
      )} />
    </button>
  )
}

// ─── Section / Row helpers ───────────────────────────────────────────────────

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-[12px] font-semibold text-gray-400 uppercase tracking-wider mb-2 px-1">
      {children}
    </p>
  )
}

function MenuRow({
  icon: Icon, iconBg, label, sub, onPress, last, right,
}: {
  icon: React.FC<React.SVGProps<SVGSVGElement>>
  iconBg: string
  label: string
  sub?: string
  onPress?: () => void
  last?: boolean
  right?: React.ReactNode
}) {
  return (
    <button
      onClick={onPress}
      disabled={!onPress && !right}
      className={cn(
        'w-full flex items-center gap-3 px-4 py-3.5 bg-white text-left',
        !last && 'border-b border-black/[0.06]',
        onPress && 'pressable active:bg-gray-50',
      )}
    >
      <div className="w-9 h-9 rounded-[10px] flex items-center justify-center shrink-0" style={{ background: iconBg }}>
        <Icon className="w-5 h-5 text-white" />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-[14px] font-medium text-gray-900 leading-tight">{label}</p>
        {sub && <p className="text-[11px] text-gray-400 mt-0.5">{sub}</p>}
      </div>
      {right ?? (onPress && <ChevronRightIcon className="w-3.5 h-3.5 text-gray-300 shrink-0" />)}
    </button>
  )
}

// ─── PIN change flow ─────────────────────────────────────────────────────────

function PinChangeSheet({
  onClose,
}: {
  onClose: () => void
}) {
  const [flow, setFlow] = useState<PinFlow>('verify-current')
  const [pin, setPin] = useState('')
  const [newPin, setNewPin] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const stepTitle: Record<PinFlow, string> = {
    'idle':           '',
    'verify-current': 'Enter Current PIN',
    'set-new':        'Enter New PIN',
    'confirm-new':    'Confirm New PIN',
    'success':        'PIN Changed',
  }

  const handlePin = async (val: string) => {
    setPin(val)
    setError('')
    if (val.length < 6) return

    if (flow === 'verify-current') {
      setLoading(true)
      try {
        await authService.verifyPin(val)
        vibrate([10, 30, 10])
        setTimeout(() => { setPin(''); setFlow('set-new') }, 150)
      } catch {
        setError('Incorrect PIN. Try again.')
        vibrate([60, 30, 60])
        setTimeout(() => setPin(''), 800)
      } finally {
        setLoading(false)
      }
      return
    }

    if (flow === 'set-new') {
      setNewPin(val)
      vibrate(10)
      setTimeout(() => { setPin(''); setFlow('confirm-new') }, 150)
      return
    }

    if (flow === 'confirm-new') {
      if (val !== newPin) {
        setError("PINs don't match. Try again.")
        vibrate([60, 30, 60])
        setTimeout(() => { setPin(''); setFlow('set-new'); setNewPin('') }, 1000)
        return
      }
      setLoading(true)
      try {
        await authService.createPin(val)
        vibrate([10, 30, 10])
        setFlow('success')
      } catch {
        setError('Failed to save PIN. Try again.')
        setTimeout(() => setPin(''), 800)
      } finally {
        setLoading(false)
      }
    }
  }

  if (flow === 'success') {
    return (
      <div className="flex flex-col items-center justify-center pt-8 pb-4 gap-4 animate-scale-in">
        <div className="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center">
          <CheckCircleIcon className="w-10 h-10 text-ios-green" />
        </div>
        <p className="text-[18px] font-bold text-gray-900">PIN Updated</p>
        <p className="text-[13px] text-gray-500 text-center px-6">Your transaction PIN has been changed successfully.</p>
        <button
          onClick={onClose}
          className="mt-2 h-12 px-10 rounded-[14px] text-[14px] font-semibold text-white pressable"
          style={{ background: 'var(--brand-primary)' }}
        >
          Done
        </button>
      </div>
    )
  }

  return (
    <div className="flex flex-col pb-safe">
      {/* Step indicator */}
      <div className="flex items-center justify-center gap-1.5 mb-6">
        {(['verify-current', 'set-new', 'confirm-new'] as const).map((s, i) => (
          <div
            key={s}
            className={cn(
              'h-1 rounded-full transition-all duration-300',
              flow === s ? 'w-8' : (
                ['verify-current', 'set-new', 'confirm-new'].indexOf(flow) > i ? 'w-4 bg-ios-green' : 'w-4 bg-gray-200'
              )
            )}
            style={flow === s ? { background: 'var(--brand-primary)', width: 32 } : undefined}
          />
        ))}
      </div>

      <div className="relative min-h-[320px]">
        <AnimatePresence mode="wait">
          <motion.div
            key={flow}
            initial={{ opacity: 0, x: 24 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: -24 }}
            transition={{ duration: 0.18, ease: 'easeInOut' }}
          >
            {loading ? (
              <div className="flex flex-col items-center justify-center py-16 gap-4">
                <Spinner size="lg" />
                <p className="text-[13px] text-gray-400">
                  {flow === 'confirm-new' ? 'Saving new PIN…' : 'Verifying…'}
                </p>
              </div>
            ) : (
              <PinPad
                value={pin}
                onChange={handlePin}
                label={stepTitle[flow]}
                hint={flow === 'set-new' ? "Choose a 6-digit PIN you'll remember" : undefined}
                error={error}
                disabled={loading}
              />
            )}
          </motion.div>
        </AnimatePresence>
      </div>
    </div>
  )
}

// ─── Main screen ─────────────────────────────────────────────────────────────

export function SecurityScreen() {
  const { user } = useAuthStore()
  const [showPinSheet, setShowPinSheet] = useState(false)
  const [biometricEnabled, setBiometricEnabled] = useState(false)
  const [txNotifications, setTxNotifications] = useState(true)
  const [loginAlerts, setLoginAlerts] = useState(true)

  const lastLogin = new Date().toLocaleDateString('en-NG', { day: 'numeric', month: 'long', year: 'numeric' })

  return (
    <div className="flex flex-col h-[100dvh] bg-[#F2F2F7]">
      <PageHeader title="Security" onBack="default" />

      <div className="flex-1 overflow-y-auto no-scrollbar px-4 pt-4 pb-10 animate-slide-up">

        {/* ── Shield hero ── */}
        <div className="bg-white rounded-[16px] p-5 mb-5 flex items-center gap-4 border border-black/5">
          <div className="w-12 h-12 rounded-[14px] bg-blue-50 flex items-center justify-center shrink-0">
            <ShieldCheckIcon className="w-6 h-6 text-ios-blue" />
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-[14px] font-bold text-gray-900">Your account is protected</p>
            <p className="text-[12px] text-gray-500 mt-0.5">
              {user?.phone ?? user?.email ?? 'Manage your security settings below.'}
            </p>
            <p className="text-[11px] text-gray-400 mt-1">Last login: {lastLogin}</p>
          </div>
        </div>

        {/* ── PIN & Authentication ── */}
        <SectionLabel>PIN & Authentication</SectionLabel>
        <div className="bg-white rounded-[16px] overflow-hidden mb-5 border border-black/5">
          <MenuRow
            icon={LockClosedIcon}
            iconBg="#007AFF"
            label="Transaction PIN"
            sub="Change your 6-digit payment PIN"
            onPress={() => setShowPinSheet(true)}
          />
          <MenuRow
            icon={FaceSmileIcon}
            iconBg="#34C759"
            label="Biometric Login"
            sub={biometricEnabled ? 'Face ID / Fingerprint enabled' : 'Use Face ID or fingerprint to log in'}
            last
            right={
              <Toggle value={biometricEnabled} onChange={setBiometricEnabled} />
            }
          />
        </div>

        {/* ── Notifications ── */}
        <SectionLabel>Security Alerts</SectionLabel>
        <div className="bg-white rounded-[16px] overflow-hidden mb-5 border border-black/5">
          <MenuRow
            icon={DevicePhoneMobileIcon}
            iconBg="#FF9500"
            label="Transaction Alerts"
            sub="Get notified on every debit or credit"
            right={
              <Toggle value={txNotifications} onChange={setTxNotifications} />
            }
          />
          <MenuRow
            icon={EyeSlashIcon}
            iconBg="#8E8E93"
            label="Login Notifications"
            sub="Alert me when a new session starts"
            last
            right={
              <Toggle value={loginAlerts} onChange={setLoginAlerts} />
            }
          />
        </div>

        {/* ── Account info ── */}
        <SectionLabel>Account</SectionLabel>
        <div className="bg-white rounded-[16px] overflow-hidden mb-5 border border-black/5">
          <div className="flex items-center justify-between px-4 py-3 border-b border-black/[0.06]">
            <span className="text-[13px] text-gray-500">Phone</span>
            <span className="text-[13px] font-semibold text-gray-900">{user?.phone ?? '—'}</span>
          </div>
          <div className="flex items-center justify-between px-4 py-3 border-b border-black/[0.06]">
            <span className="text-[13px] text-gray-500">KYC Tier</span>
            <span className="text-[13px] font-semibold text-gray-900">Tier {user?.tier ?? 1}</span>
          </div>
          <div className="flex items-center justify-between px-4 py-3">
            <span className="text-[13px] text-gray-500">Account Status</span>
            <span className="text-[13px] font-semibold text-ios-green">Active</span>
          </div>
        </div>

        {/* ── Tips ── */}
        <div className="bg-blue-50 rounded-[16px] p-4 flex gap-3">
          <ShieldCheckIcon className="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
          <p className="text-[12px] text-blue-700 leading-relaxed">
            <strong>Security tip:</strong> FixPay will <strong>never</strong> ask for your PIN, OTP, or password via phone or email. Do not share these with anyone.
          </p>
        </div>
      </div>

      {/* ── Change PIN bottom sheet ── */}
      {showPinSheet && (
        <>
          {/* Backdrop */}
          <motion.div
            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
            className="fixed inset-0 bg-black/40 z-40 backdrop-blur-sm"
            onClick={() => setShowPinSheet(false)}
          />
          {/* Sheet */}
          <motion.div
            initial={{ y: '100%' }} animate={{ y: 0 }} exit={{ y: '100%' }}
            transition={{ type: 'spring', damping: 30, stiffness: 400 }}
            className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-[480px] bg-white rounded-t-[24px] z-50 pb-safe"
          >
            {/* Handle */}
            <div className="flex justify-center pt-3 pb-2">
              <div className="w-10 h-1 rounded-full bg-gray-200" />
            </div>
            {/* Title */}
            <div className="flex items-center justify-between px-5 pb-3 border-b border-black/[0.06]">
              <h3 className="text-[15px] font-semibold text-gray-900">Change Transaction PIN</h3>
              <button
                onClick={() => setShowPinSheet(false)}
                className="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center pressable"
              >
                <span className="text-[13px] text-gray-500 font-medium">✕</span>
              </button>
            </div>
            <div className="px-5 pt-5">
              <PinChangeSheet onClose={() => setShowPinSheet(false)} />
            </div>
          </motion.div>
        </>
      )}
    </div>
  )
}