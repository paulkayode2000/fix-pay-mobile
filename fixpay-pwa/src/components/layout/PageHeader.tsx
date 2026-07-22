import { useNavigate } from 'react-router-dom'
import { ChevronLeftIcon } from '@heroicons/react/24/outline'
import { cn } from '@/lib/utils'

interface PageHeaderProps {
  title: string
  onBack?: (() => void) | 'default'
  right?: React.ReactNode
  className?: string
  transparent?: boolean
}

export function PageHeader({ title, onBack, right, className, transparent }: PageHeaderProps) {
  const navigate = useNavigate()
  const handleBack = onBack === 'default' ? () => navigate(-1) : onBack
  const rightSlot = right ?? null
  return (
    <header className={cn('sticky top-0 z-20 shrink-0 flex items-center gap-2 h-14 px-4', transparent ? 'bg-transparent' : 'bg-[#F2F2F7]', className)}>
      {handleBack ? (
        <button onClick={handleBack} className="w-9 h-9 flex items-center justify-center rounded-full bg-white/70 pressable -ml-1 shrink-0">
          <ChevronLeftIcon className="w-5 h-5 text-brand stroke-[2.5]" />
        </button>
      ) : (
        <div className="w-9 shrink-0" />
      )}
      <h1 className="flex-1 text-[17px] font-semibold text-gray-900 text-center truncate">{title}</h1>
      <div className="w-9 flex items-center justify-end shrink-0">{rightSlot}</div>
    </header>
  )
}
