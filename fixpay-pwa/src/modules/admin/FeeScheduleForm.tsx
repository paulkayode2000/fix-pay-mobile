import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { adminApi, type FeeType } from './adminApi'

interface Props {
  railId: string
  onClose: () => void
}

export function FeeScheduleForm({ railId, onClose }: Props) {
  const qc = useQueryClient()
  const [feeType, setFeeType] = useState<FeeType>('FIXED')
  const [fixedFeeKobo, setFixedFeeKobo] = useState('')
  const [percentageFee, setPercentageFee] = useState('')
  const [capKobo, setCapKobo] = useState('')
  const [minFeeKobo, setMinFeeKobo] = useState('0')
  const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().split('T')[0])
  const [effectiveTo, setEffectiveTo] = useState('')
  const [error, setError] = useState<string | null>(null)

  const addFee = useMutation({
    mutationFn: () => adminApi.addFee(railId, {
      feeType,
      fixedFeeKobo: parseInt(fixedFeeKobo || '0', 10),
      percentageFee: parseFloat(percentageFee || '0') / 100,
      capKobo: capKobo ? parseInt(capKobo, 10) : null,
      minFeeKobo: parseInt(minFeeKobo || '0', 10),
      effectiveFrom,
      effectiveTo: effectiveTo || null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'fees', railId] })
      onClose()
    },
    onError: (e: unknown) => {
      setError(e instanceof Error ? e.message : 'Failed to add fee')
    },
  })

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 className="text-lg font-semibold">Add Fee Schedule</h2>
        <p className="text-xs text-gray-500">
          Fee = max(minFee, min(cap, fixedFee + amount × percentage))
        </p>

        <div className="space-y-1">
          <label className="block text-sm font-medium">Fee Type</label>
          <select
            value={feeType}
            onChange={e => setFeeType(e.target.value as FeeType)}
            className="w-full rounded border px-3 py-2 text-sm"
          >
            <option value="FIXED">Fixed (flat fee)</option>
            <option value="PERCENTAGE">Percentage</option>
            <option value="TIERED">Tiered (fixed + percentage)</option>
          </select>
        </div>

        {(feeType === 'FIXED' || feeType === 'TIERED') && (
          <div className="space-y-1">
            <label className="block text-sm font-medium">Fixed Fee (kobo)</label>
            <input
              type="number" min="0" value={fixedFeeKobo}
              onChange={e => setFixedFeeKobo(e.target.value)}
              placeholder="e.g. 10000 for ₦100"
              className="w-full rounded border px-3 py-2 text-sm"
            />
          </div>
        )}

        {(feeType === 'PERCENTAGE' || feeType === 'TIERED') && (
          <div className="space-y-1">
            <label className="block text-sm font-medium">Percentage (%)</label>
            <input
              type="number" min="0" step="0.000001" value={percentageFee}
              onChange={e => setPercentageFee(e.target.value)}
              placeholder="e.g. 1.5 for 1.5%"
              className="w-full rounded border px-3 py-2 text-sm"
            />
          </div>
        )}

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1">
            <label className="block text-sm font-medium">Min Fee (kobo)</label>
            <input
              type="number" min="0" value={minFeeKobo}
              onChange={e => setMinFeeKobo(e.target.value)}
              className="w-full rounded border px-3 py-2 text-sm"
            />
          </div>
          <div className="space-y-1">
            <label className="block text-sm font-medium">Cap (kobo, optional)</label>
            <input
              type="number" min="0" value={capKobo}
              onChange={e => setCapKobo(e.target.value)}
              placeholder="Leave blank for no cap"
              className="w-full rounded border px-3 py-2 text-sm"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1">
            <label className="block text-sm font-medium">Effective From</label>
            <input
              type="date" value={effectiveFrom}
              onChange={e => setEffectiveFrom(e.target.value)}
              className="w-full rounded border px-3 py-2 text-sm"
            />
          </div>
          <div className="space-y-1">
            <label className="block text-sm font-medium">Effective To (optional)</label>
            <input
              type="date" value={effectiveTo}
              onChange={e => setEffectiveTo(e.target.value)}
              className="w-full rounded border px-3 py-2 text-sm"
            />
          </div>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex gap-2 justify-end pt-2">
          <button onClick={onClose} className="px-4 py-2 text-sm rounded border">Cancel</button>
          <button
            disabled={addFee.isPending}
            onClick={() => addFee.mutate()}
            className="px-4 py-2 text-sm rounded bg-blue-600 text-white disabled:opacity-50"
          >
            {addFee.isPending ? 'Saving…' : 'Add Fee'}
          </button>
        </div>
      </div>
    </div>
  )
}
