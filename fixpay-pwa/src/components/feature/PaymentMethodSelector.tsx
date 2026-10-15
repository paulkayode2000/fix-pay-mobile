import React from 'react'

export type PaymentMethod = 'wallet' | 'payfixy' | 'bank_mandate'

interface PaymentMethodSelectorProps {
  value: PaymentMethod
  onChange: (method: PaymentMethod) => void
  disabled?: boolean
  /**
   * Whether the user has an ACTIVE wallet. Wallets are optional, so the wallet
   * rail is disabled (not hidden) when there is none — the gateway rail works
   * with or without a wallet.
   */
  walletAvailable?: boolean
}

interface MethodOption {
  id: PaymentMethod
  label: string
  icon: string
  hint?: string
}

const METHODS: MethodOption[] = [
  { id: 'wallet', label: 'Wallet', icon: '💳' },
  { id: 'payfixy', label: 'PayFixy', icon: '🌐', hint: 'Card, transfer or USSD — no wallet needed' },
  { id: 'bank_mandate', label: 'Bank Mandate', icon: '🏦', hint: 'Coming soon' },
]

export function PaymentMethodSelector({ value, onChange, disabled, walletAvailable = true }: PaymentMethodSelectorProps) {
  const unavailableReason = (method: PaymentMethod): string | null => {
    // Bank mandate moves through the separate NIBSS mandate flow, not "pay now".
    if (method === 'bank_mandate') return 'Coming soon'
    if (method === 'wallet' && !walletAvailable) return 'Open a wallet to use this'
    return null
  }

  return (
    <div className="mb-4">
      <p className="text-[13px] font-semibold text-gray-500 uppercase tracking-wide mb-2">Payment Method</p>
      <div className="flex flex-col gap-2">
        {METHODS.map(method => {
          const isSelected = value === method.id
          const reason = unavailableReason(method.id)
          const isDisabled = disabled || reason !== null
          return (
            <button
              key={method.id}
              type="button"
              disabled={isDisabled}
              onClick={() => onChange(method.id)}
              className={`flex items-center justify-between p-3 rounded-[12px] border-2 transition-all ${isDisabled ? 'opacity-50 cursor-not-allowed' : 'pressable'}`}
              style={{
                borderColor: isSelected ? 'var(--brand-primary)' : '#E5E5EA',
                backgroundColor: isSelected ? 'rgba(var(--brand-primary-rgb), 0.05)' : '#FFF',
              }}
            >
              <div className="flex items-center gap-3 min-w-0">
                <span className="text-xl">{method.icon}</span>
                <span className="flex flex-col items-start min-w-0">
                  <span className="text-[15px] font-semibold text-gray-800">{method.label}</span>
                  {(reason ?? method.hint) && (
                    <span className="text-[11px] text-gray-400">{reason ?? method.hint}</span>
                  )}
                </span>
              </div>
              <div className={`w-5 h-5 shrink-0 rounded-full border-2 flex items-center justify-center ${isSelected ? 'border-[var(--brand-primary)]' : 'border-gray-300'}`}>
                {isSelected && <div className="w-2.5 h-2.5 rounded-full bg-[var(--brand-primary)]" />}
              </div>
            </button>
          )
        })}
      </div>
      <p className="text-[11px] text-gray-400 mt-2">
        A wallet is optional — PayFixy settles the bill without one.
      </p>
    </div>
  )
}

