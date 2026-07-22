import { useRef, useEffect, useCallback } from 'react'
import { BackspaceIcon } from '@heroicons/react/24/outline'
import { Spinner } from '@/components/ui/Spinner'
import { cn } from '@/lib/utils'

interface PinPadProps {
  value: string
  onChange: (val: string) => void
  maxLength?: number
  label?: string
  hint?: string
  error?: string
  disabled?: boolean
}

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'del'] as const

export function PinPad({ value, onChange, maxLength = 6, label, hint, error, disabled }: PinPadProps) {
  const submitted = useRef(false)

  const handleKey = useCallback((key: string) => {
    if (disabled) return
    if (key === 'del') { onChange(value.slice(0, -1)); submitted.current = false; return }
    if (key === '' || value.length >= maxLength) return
    const next = value + key
    onChange(next)
  }, [value, onChange, maxLength, disabled])

  // Keyboard support
  useEffect(() => {
    const h = (e: KeyboardEvent) => {
      if (e.key >= '0' && e.key <= '9') handleKey(e.key)
      else if (e.key === 'Backspace') handleKey('del')
    }
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
  }, [handleKey])

  return (
    <div className="flex flex-col items-center gap-6 pb-safe">
      {/* Label */}
      {label && <p className="text-[15px] font-semibold text-gray-900 text-center">{label}</p>}

      {/* Dots */}
      <div className="flex gap-3">
        {Array.from({ length: maxLength }).map((_, i) => (
          <div key={i} className={cn(
            'w-3.5 h-3.5 rounded-full border-2 transition-all duration-150',
            i < value.length ? 'border-transparent scale-110' : 'border-gray-300 scale-100'
          )} style={i < value.length ? { background: 'var(--brand-primary)' } : undefined} />
        ))}
      </div>

      {/* Error / hint */}
      {error && <p className="text-[12px] text-ios-red text-center -mt-2">{error}</p>}
      {hint && !error && <p className="text-[11px] text-gray-400 text-center -mt-2">{hint}</p>}

      {/* Keypad */}
      {disabled ? (
        <div className="flex flex-col items-center justify-center w-full" style={{ height: '292px' }}>
          <Spinner size="lg" />
          <p className="text-[13px] text-gray-500 mt-6 font-medium animate-pulse">Processing...</p>
        </div>
      ) : (
        <div className="grid grid-cols-3 gap-3 w-full px-8">
          {KEYS.map((key, idx) => {
            if (key === '') return <div key={idx} />
            if (key === 'del') {
              return (
                <button key={idx} onPointerDown={() => handleKey('del')} disabled={disabled || value.length === 0}
                  className="h-16 rounded-[16px] flex items-center justify-center pressable active:bg-gray-200 transition-colors disabled:opacity-30">
                  <BackspaceIcon className="w-5 h-5 text-brand" />
                </button>
              )
            }
            return (
              <button key={idx} onPointerDown={() => handleKey(key)} disabled={disabled}
                className="h-16 bg-white rounded-[16px] flex items-center justify-center text-[22px] font-medium text-gray-900 shadow-sm pressable active:bg-gray-100 transition-colors disabled:opacity-30">
                {key}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}