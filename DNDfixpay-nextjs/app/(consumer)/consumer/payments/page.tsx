'use client'

import { useState, useMemo, useEffect } from 'react'
import { paymentsService } from '@/lib/services'
import { formatNaira } from '@/lib/format'
import {
  type ValidityCategory,
  type GroupedVariations,
  CATEGORY_LABELS,
  groupVariations,
  activeCategories,
} from '@/lib/categorize-variations'

interface Variation {
  variation_code: string
  name: string
  variation_amount: string
}

const SERVICE_CATEGORIES = [
  { id: 'mtn-data', label: 'MTN Data' },
  { id: 'airtel-data', label: 'Airtel Data' },
  { id: 'ikeja-electric', label: 'Ikeja Electric' },
  { id: 'eko-electric', label: 'Eko Electric' },
  { id: 'gotv', label: 'GOtv' },
  { id: 'dstv', label: 'DStv' },
]

export default function PaymentsPage() {
  const [serviceId, setServiceId] = useState('')
  const [variations, setVariations] = useState<Variation[]>([])
  const [selected, setSelected] = useState<Variation | null>(null)
  const [customAmount, setCustomAmount] = useState('')
  const [identifier, setIdentifier] = useState('')  // meter/smartcard/phone
  const [pin, setPin] = useState('')
  const [loading, setLoading] = useState(false)
  const [success, setSuccess] = useState<string | null>(null)
  const [error, setError] = useState('')

  // ── Categorisation tab state ───────────────────────────────────────────────
  const [activeTab, setActiveTab] = useState<ValidityCategory>('daily')
  const grouped: GroupedVariations = useMemo(() => groupVariations(variations), [variations])
  const tabs = useMemo(() => activeCategories(grouped), [grouped])

  useEffect(() => {
    if (tabs.length > 0 && !tabs.includes(activeTab)) {
      setActiveTab(tabs[0])
    }
  }, [tabs, activeTab])

  async function loadVariations(id: string) {
    setServiceId(id)
    setVariations([])
    setSelected(null)
    try {
      const res = await paymentsService.variations(id) as { variations?: Variation[] }
      setVariations(res.variations ?? [])
    } catch {
      // no variations (fixed amount service)
    }
  }

  async function handlePay(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setSuccess(null)
    setLoading(true)
    const amount = selected
      ? parseFloat(selected.variation_amount) * 100
      : parseFloat(customAmount) * 100
    try {
      await paymentsService.pay({
        serviceID: serviceId,
        billersCode: identifier,
        variation_code: selected?.variation_code,
        amount_kobo: Math.round(amount),
        pin,
      })
      setSuccess(`Payment of ${formatNaira(Math.round(amount))} successful!`)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Payment failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="mx-auto max-w-lg px-4 py-8">
      <h1 className="mb-6 text-xl font-bold text-gray-900">Pay Bills</h1>

      {/* Service selector */}
      <div className="mb-4 grid grid-cols-3 gap-2">
        {SERVICE_CATEGORIES.map(s => (
          <button
            key={s.id}
            onClick={() => loadVariations(s.id)}
            className={
              'rounded-xl border py-3 text-xs font-medium ' +
              (serviceId === s.id ? 'border-green-500 bg-green-50 text-green-700' : 'bg-white text-gray-700')
            }
          >
            {s.label}
          </button>
        ))}
      </div>

      {/* Variations - grouped by validity category */}
      {variations.length > 0 && (
        <div className="mb-4">
          {/* Category tabs — only show when there's more than one */}
          {tabs.length > 1 && (
            <div className="flex gap-1.5 mb-3 overflow-x-auto">
              {tabs.map(cat => (
                <button
                  key={cat}
                  type="button"
                  onClick={() => setActiveTab(cat)}
                  className={
                    'px-4 py-1.5 rounded-full text-xs font-semibold transition-colors whitespace-nowrap ' +
                    (activeTab === cat
                      ? 'bg-green-600 text-white'
                      : 'bg-white text-gray-500 border border-gray-200')
                  }
                >
                  {CATEGORY_LABELS[cat]}
                </button>
              ))}
            </div>
          )}
          <div className="space-y-1">
            {grouped[activeTab].map(v => (
              <button
                key={v.variation_code}
                onClick={() => setSelected(v)}
                className={
                  'flex w-full items-center justify-between rounded-lg border px-3 py-2 text-sm ' +
                  (selected?.variation_code === v.variation_code
                    ? 'border-green-500 bg-green-50 text-green-700'
                    : 'bg-white text-gray-700')
                }
              >
                <span>{v.name}</span>
                <span className="font-semibold">{formatNaira(parseFloat(v.variation_amount) * 100)}</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {serviceId && (
        <form onSubmit={handlePay} className="space-y-4 rounded-2xl bg-white p-6 shadow">
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              {serviceId.includes('electric') ? 'Meter Number' : serviceId.includes('tv') || serviceId.includes('go') || serviceId.includes('ds') ? 'Smart Card Number' : 'Phone Number'}
            </label>
            <input
              type="text"
              required
              value={identifier}
              onChange={e => setIdentifier(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
            />
          </div>
          {!selected && (
            <div>
              <label className="mb-1 block text-sm font-medium text-gray-700">Amount (NGN)</label>
              <input
                type="number"
                required
                value={customAmount}
                onChange={e => setCustomAmount(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
              />
            </div>
          )}
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">Transaction PIN</label>
            <input
              type="password"
              required
              maxLength={6}
              value={pin}
              onChange={e => setPin(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
            />
          </div>
          {error && <p className="text-sm text-red-600">{error}</p>}
          {success && <p className="text-sm text-green-600">{success}</p>}
          <button
            type="submit"
            disabled={loading}
            className="w-full rounded-lg bg-green-600 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
          >
            {loading ? 'Processing…' : 'Pay Now'}
          </button>
        </form>
      )}
    </div>
  )
}
