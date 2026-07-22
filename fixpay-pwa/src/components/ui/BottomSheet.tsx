import { useEffect, useRef } from 'react'
import { AnimatePresence, motion } from 'motion/react'
import { XMarkIcon } from '@heroicons/react/24/outline'
import { cn } from '@/lib/utils'

interface BottomSheetProps {
  open: boolean
  onClose: () => void
  title?: string
  children: React.ReactNode
  className?: string
  dismissible?: boolean
  height?: 'auto' | 'half' | 'full'
}

export function BottomSheet({ open, onClose, title, children, className, dismissible = true, height = 'auto' }: BottomSheetProps) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape' && dismissible) onClose() }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [open, onClose, dismissible])

  const heightClass = height === 'full' ? 'max-h-[96dvh]' : height === 'half' ? 'max-h-[60dvh]' : 'max-h-[96dvh]'

  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div
            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="fixed inset-0 bg-black/40 z-40 backdrop-blur-overlay"
            onClick={dismissible ? onClose : undefined}
          />
          <motion.div
            ref={ref}
            initial={{ y: '100%' }} animate={{ y: 0 }} exit={{ y: '100%' }}
            transition={{ type: 'spring', damping: 30, stiffness: 400 }}
            className={cn(
              'fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-[480px] bg-white rounded-t-[24px] z-50 flex flex-col overflow-hidden',
              heightClass, className
            )}
          >
            {/* Drag indicator */}
            <div className="flex justify-center pt-3 pb-1 shrink-0">
              <div className="w-10 h-1 rounded-full bg-gray-200" />
            </div>
            {/* Header */}
            {title && (
              <div className="flex items-center justify-between px-5 pt-1 pb-3 shrink-0">
                <h3 className="text-[17px] font-semibold text-gray-900">{title}</h3>
                {dismissible && (
                  <button onClick={onClose} className="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center pressable">
                    <XMarkIcon className="w-4 h-4 text-brand" />
                  </button>
                )}
              </div>
            )}
            <div className="flex-1 overflow-y-auto no-scrollbar pb-safe">
              {children}
            </div>
          </motion.div>
        </>
      )}
    </AnimatePresence>
  )
}
