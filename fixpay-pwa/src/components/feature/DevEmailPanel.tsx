import { useState, useEffect } from 'react'
import { EnvelopeIcon, XMarkIcon, ClipboardIcon } from '@heroicons/react/24/outline'
import { emailService } from '@/lib/email-service'
import { cn } from '@/lib/utils'

/**
 * Dev-only floating panel that displays sent OTP codes.
 * Replaces the need to open browser console to find test OTPs.
 *
 * Only renders in import.meta.env.DEV mode.
 * In production, this component returns null and is tree-shaken.
 */
export function DevEmailPanel() {
  // Always return null in production — this component is dev-only
  if (!import.meta.env.DEV) return null

  return <DevEmailPanelInner />
}

function DevEmailPanelInner() {
  const [open, setOpen] = useState(false)
  const [entries, setEntries] = useState<Array<{ to: string; code: string; timestamp: number }>>([])
  const [copied, setCopied] = useState<string | null>(null)

  useEffect(() => {
    // Poll for new OTP entries every 500ms while panel is open
    if (!open) return
    const interval = setInterval(() => {
      setEntries(emailService.getAllSent())
    }, 500)
    return () => clearInterval(interval)
  }, [open])

  const handleCopy = async (code: string) => {
    await navigator.clipboard.writeText(code)
    setCopied(code)
    setTimeout(() => setCopied(null), 2000)
  }

  const formatTime = (ts: number) => {
    const d = new Date(ts)
    return d.toLocaleTimeString('en-NG', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  }

  return (
    <>
      {/* Toggle badge */}
      <button
        onClick={() => { setOpen(!open); if (!open) setEntries(emailService.getAllSent()) }}
        className={cn(
          'fixed bottom-20 right-3 z-50 w-9 h-9 rounded-full flex items-center justify-center shadow-lg pressable transition-all',
          entries.length > 0 ? 'bg-ios-green text-white' : 'bg-white text-gray-400 border border-black/10'
        )}
        title="Dev Email Panel"
      >
        <EnvelopeIcon className="w-4 h-4" />
        {entries.length > 0 && (
          <span className="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-ios-red text-white text-[9px] font-bold flex items-center justify-center">
            {entries.length > 9 ? '9+' : entries.length}
          </span>
        )}
      </button>

      {/* Panel */}
      {open && (
        <div className="fixed bottom-32 right-3 z-50 w-72 max-h-80 bg-white rounded-[16px] shadow-2xl border border-black/10 overflow-hidden animate-slide-up">
          {/* Header */}
          <div className="flex items-center justify-between px-4 py-3 border-b border-black/5 bg-gray-50">
            <h3 className="text-[12px] font-bold text-gray-900 flex items-center gap-1.5">
              <EnvelopeIcon className="w-3.5 h-3.5 text-brand" />
              Dev Email Panel
            </h3>
            <button onClick={() => setOpen(false)} className="w-5 h-5 rounded-full bg-gray-200 flex items-center justify-center pressable">
              <XMarkIcon className="w-3 h-3 text-brand" />
            </button>
          </div>

          {/* Entries */}
          <div className="overflow-y-auto max-h-64 no-scrollbar">
            {entries.length === 0 ? (
              <div className="px-4 py-8 text-center text-[11px] text-gray-400">
                No OTPs sent yet. Register to trigger one.
              </div>
            ) : (
              entries.reverse().map((entry, i) => (
                <div
                  key={`${entry.timestamp}-${i}`}
                  className="flex items-center justify-between px-4 py-2.5 border-b border-black/5 last:border-0 hover:bg-gray-50 transition-colors"
                >
                  <div className="flex-1 min-w-0">
                    <p className="text-[11px] font-semibold text-gray-900 truncate">{entry.to}</p>
                    <p className="text-[10px] text-gray-400">{formatTime(entry.timestamp)}</p>
                  </div>
                  <button
                    onClick={() => handleCopy(entry.code)}
                    className="flex items-center gap-1 px-2 py-1 rounded-[8px] bg-brand-light text-brand pressable ml-2 shrink-0"
                    style={{ background: 'var(--brand-light)' }}
                  >
                    <span className="text-[13px] font-bold tracking-wider">{entry.code}</span>
                    <ClipboardIcon className="w-3 h-3" />
                  </button>
                </div>
              ))
            )}
          </div>

          {copied && (
            <div className="absolute bottom-2 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-[10px] px-3 py-1 rounded-full animate-fade-in">
              Copied "{copied}" to clipboard
            </div>
          )}
        </div>
      )}
    </>
  )
}