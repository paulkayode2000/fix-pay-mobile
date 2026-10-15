import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion } from 'motion/react'
import { useAuthStore } from '@/store/auth.store'
import { sleep } from '@/lib/utils'

const spring = { type: 'spring' as const, damping: 18, stiffness: 190 }
const fade = (delay: number) => ({
  initial: { opacity: 0, y: 10 },
  animate: { opacity: 1, y: 0 },
  transition: { delay, duration: 0.55, ease: [0.22, 1, 0.36, 1] as [number, number, number, number] },
})

export function SplashScreen() {
  const navigate = useNavigate()
  const { isAuthenticated, pinCreated, _hasHydrated } = useAuthStore()
  const [exiting, setExiting] = useState(false)

  useEffect(() => {
    if (!_hasHydrated) return

    let cancelled = false

    const run = async () => {
      await sleep(3800)
      if (cancelled) return
      setExiting(true)
      await sleep(700)
      if (cancelled) return
      sessionStorage.setItem('splash_shown', '1')
      // KYC and wallets are both optional entry-wise (bills work through the
      // Payfixy gateway rail without either) — only the PIN gates the home.
      if (isAuthenticated && pinCreated) navigate('/home', { replace: true })
      else if (isAuthenticated) navigate('/auth/pin', { replace: true })
      else if (localStorage.getItem('fixpay_onboarded')) navigate('/auth/login', { replace: true })
      else navigate('/welcome', { replace: true })
    }

    run()
    return () => { cancelled = true }
  }, [_hasHydrated, isAuthenticated, pinCreated, navigate])

  return (
    <motion.div
      className="h-[100dvh] flex flex-col items-center justify-center overflow-hidden select-none"
      style={{ background: 'var(--brand-primary)' }}
      initial={false}
      animate={exiting
        ? { opacity: 0, filter: 'blur(18px)', scale: 1.06 }
        : { opacity: 1, filter: 'blur(0px)', scale: 1 }}
      transition={{ duration: 0.7, ease: [0.4, 0, 0.2, 1] }}
    >
      <motion.div
        className="absolute rounded-full pointer-events-none"
        style={{
          width: 200, height: 200,
          background: 'radial-gradient(circle, rgba(255,255,255,0.13) 0%, transparent 70%)',
        }}
        initial={{ scale: 0.6, opacity: 0 }}
        animate={{ scale: 1, opacity: 1 }}
        transition={{ delay: 0.3, duration: 1.0, ease: 'easeOut' }}
      />
      <motion.div
        className="absolute rounded-full pointer-events-none"
        style={{
          width: 130, height: 130,
          border: '1.5px solid rgba(255,255,255,0.22)',
          background: 'radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 65%)',
        }}
        initial={{ scale: 0.8, opacity: 0 }}
        animate={{ scale: [1, 1.65, 1], opacity: [0.6, 0, 0.6] }}
        transition={{ delay: 0.7, duration: 2.6, repeat: Infinity, ease: 'easeInOut' }}
      />
      <motion.div
        className="absolute rounded-full pointer-events-none"
        style={{
          width: 130, height: 130,
          border: '1px solid rgba(255,255,255,0.11)',
          background: 'radial-gradient(circle, rgba(255,255,255,0.03) 0%, transparent 65%)',
        }}
        initial={{ scale: 0.8, opacity: 0 }}
        animate={{ scale: [1, 2.2, 1], opacity: [0.45, 0, 0.45] }}
        transition={{ delay: 1.1, duration: 2.6, repeat: Infinity, ease: 'easeInOut' }}
      />

      <motion.div
        className="relative w-24 h-24 rounded-[28px] flex items-center justify-center z-10 overflow-hidden"
        style={{
          background: 'rgba(255,255,255,0.96)',
          boxShadow: '0 8px 40px rgba(0,0,0,0.20), inset 0 1px 0 rgba(255,255,255,0.9)',
        }}
        initial={{ scale: 0, rotate: -14, opacity: 0 }}
        animate={{ scale: 1, rotate: 0, opacity: 1 }}
        transition={{ ...spring, delay: 0.1 }}
      >
        <div className="absolute inset-0 bg-gradient-to-br from-white/55 via-transparent to-transparent pointer-events-none" />
        <span className="relative text-[46px] font-black leading-none" style={{ color: 'var(--brand-primary)' }}>F</span>
      </motion.div>

      <div className="flex items-baseline mt-5 overflow-hidden z-10">
        <motion.span
          className="text-[38px] font-black text-white tracking-tight leading-none"
          initial={{ x: -44, opacity: 0 }}
          animate={{ x: 0, opacity: 1 }}
          transition={{ delay: 0.55, duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
        >Fix</motion.span>
        <motion.span
          className="text-[38px] font-black tracking-tight leading-none"
          style={{ color: 'rgba(255,255,255,0.60)' }}
          initial={{ x: 44, opacity: 0 }}
          animate={{ x: 0, opacity: 1 }}
          transition={{ delay: 0.65, duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
        >Pay</motion.span>
      </div>

      <motion.p className="text-white/50 text-[14px] mt-2 z-10 tracking-wide" {...fade(1.0)}>
        Payments. Fixed.
      </motion.p>

      <motion.div
        initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ delay: 1.4 }}
        className="absolute bottom-16 flex gap-2"
      >
        {[0, 0.2, 0.4].map((d, i) => (
          <motion.div
            key={i}
            animate={{ opacity: [0.2, 1, 0.2], scale: [0.75, 1.2, 0.75] }}
            transition={{ duration: 1.2, delay: d, repeat: Infinity, ease: 'easeInOut' }}
            className="w-2 h-2 rounded-full"
            style={{ background: 'rgba(255,255,255,0.65)' }}
          />
        ))}
      </motion.div>
    </motion.div>
  )
}
