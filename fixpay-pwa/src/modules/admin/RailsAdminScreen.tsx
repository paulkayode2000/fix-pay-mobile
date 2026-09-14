import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { adminApi, type PaymentRailConfig, type FeeSchedule } from './adminApi'
import { ProcessorConfigWizard } from './ProcessorConfigWizard'
import { FeeScheduleForm } from './FeeScheduleForm'
import { AuditLogView } from './AuditLogView'

type PanelView = 'fees' | 'audit' | null

/** Formats kobo as ₦ with 2 decimal places */
function naira(kobo: number) {
  return `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}`
}

function RailRow({ rail }: { rail: PaymentRailConfig }) {
  const qc = useQueryClient()
  const [showWizard, setShowWizard] = useState(false)
  const [panel, setPanel] = useState<PanelView>(null)
  const [showFeeForm, setShowFeeForm] = useState(false)

  const { data: fees = [] } = useQuery({
    queryKey: ['admin', 'fees', rail.id],
    queryFn: () => adminApi.listFees(rail.id),
    enabled: panel === 'fees',
  })

  const toggleEnabled = useMutation({
    mutationFn: (e: boolean) => adminApi.toggleEnabled(rail.id, e),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'rails'] }),
  })

  const toggleMaintenance = useMutation({
    mutationFn: (m: boolean) => adminApi.setMaintenance(rail.id, m),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'rails'] }),
  })

  const deleteFee = useMutation({
    mutationFn: (feeId: string) => adminApi.deleteFee(rail.id, feeId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'fees', rail.id] }),
  })

  const deleteRail = useMutation({
    mutationFn: () => adminApi.deleteRail(rail.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'rails'] }),
  })

  const statusBadge = rail.maintenance
    ? <span className="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-800">Maintenance</span>
    : rail.enabled
    ? <span className="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-800">Enabled</span>
    : <span className="text-xs px-2 py-0.5 rounded-full bg-gray-200 text-gray-600">Disabled</span>

  return (
    <div className="rounded-lg border bg-white shadow-sm">
      {/* Rail header */}
      <div className="flex items-start justify-between px-4 py-3">
        <div className="space-y-0.5">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="font-medium text-sm">{rail.processorId}</span>
            <span className="text-xs text-gray-400">{rail.paymentMethod}</span>
            <span className="text-xs text-gray-400">priority {rail.priority}</span>
            {statusBadge}
          </div>
          {rail.configSchema && (
            <p className="text-xs text-gray-500">{rail.configSchema.displayName} — {rail.configSchema.category}</p>
          )}
        </div>

        {/* Action buttons */}
        <div className="flex items-center gap-1 shrink-0">
          <button
            title="Configure processor"
            onClick={() => setShowWizard(true)}
            className="text-xs px-2 py-1 rounded bg-blue-50 text-blue-700 hover:bg-blue-100"
          >
            Configure
          </button>
          <button
            title={rail.enabled ? 'Disable' : 'Enable'}
            onClick={() => toggleEnabled.mutate(!rail.enabled)}
            disabled={toggleEnabled.isPending}
            className="text-xs px-2 py-1 rounded bg-gray-100 text-gray-700 hover:bg-gray-200 disabled:opacity-50"
          >
            {rail.enabled ? 'Disable' : 'Enable'}
          </button>
          <button
            title={rail.maintenance ? 'End maintenance' : 'Maintenance mode'}
            onClick={() => toggleMaintenance.mutate(!rail.maintenance)}
            disabled={toggleMaintenance.isPending}
            className="text-xs px-2 py-1 rounded bg-yellow-50 text-yellow-700 hover:bg-yellow-100 disabled:opacity-50"
          >
            {rail.maintenance ? 'End maint.' : 'Maintenance'}
          </button>
          <button
            title="Delete rail"
            onClick={() => {
              if (window.confirm(`Delete rail for ${rail.processorId}?`)) {
                deleteRail.mutate()
              }
            }}
            disabled={deleteRail.isPending}
            className="text-xs px-2 py-1 rounded bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50"
          >
            Delete
          </button>
        </div>
      </div>

      {/* Expandable panels */}
      <div className="flex gap-1 px-4 pb-2">
        <button
          onClick={() => setPanel(panel === 'fees' ? null : 'fees')}
          className={`text-xs px-2 py-0.5 rounded border ${panel === 'fees' ? 'bg-blue-600 text-white border-blue-600' : 'text-gray-600 hover:bg-gray-50'}`}
        >
          Fees
        </button>
        <button
          onClick={() => setPanel(panel === 'audit' ? null : 'audit')}
          className={`text-xs px-2 py-0.5 rounded border ${panel === 'audit' ? 'bg-blue-600 text-white border-blue-600' : 'text-gray-600 hover:bg-gray-50'}`}
        >
          Audit log
        </button>
      </div>

      {panel === 'fees' && (
        <div className="border-t px-4 py-3 space-y-3">
          <div className="flex items-center justify-between">
            <h4 className="text-xs font-semibold text-gray-700">Fee Schedules</h4>
            <button
              onClick={() => setShowFeeForm(true)}
              className="text-xs px-2 py-0.5 rounded bg-green-600 text-white hover:bg-green-700"
            >
              + Add Fee
            </button>
          </div>

          {fees.length === 0 && (
            <p className="text-xs text-gray-500 italic">No fee schedules. Transactions are free.</p>
          )}

          {(fees as FeeSchedule[]).map(fee => (
            <div key={fee.id} className="flex items-center justify-between text-xs border rounded px-3 py-2">
              <div className="space-y-0.5">
                <div className="font-medium">{fee.feeType}</div>
                <div className="text-gray-500">
                  {fee.fixedFeeKobo > 0 && `Fixed: ${naira(fee.fixedFeeKobo)} `}
                  {fee.percentageFee > 0 && `${(fee.percentageFee * 100).toFixed(4)}% `}
                  {fee.minFeeKobo > 0 && `min amount ${naira(fee.minFeeKobo)} `}
                  {fee.capKobo != null && `cap ${naira(fee.capKobo)}`}
                </div>
                <div className="text-gray-400">
                  {fee.effectiveFrom} → {fee.effectiveTo ?? 'ongoing'}
                </div>
              </div>
              <button
                onClick={() => {
                  if (window.confirm('Delete this fee schedule?')) {
                    deleteFee.mutate(fee.id)
                  }
                }}
                className="text-red-600 hover:underline ml-2"
              >
                Remove
              </button>
            </div>
          ))}
        </div>
      )}

      {panel === 'audit' && (
        <div className="border-t px-4 py-3">
          <AuditLogView railId={rail.id} />
        </div>
      )}

      {showWizard && (
        <ProcessorConfigWizard rail={rail} onClose={() => setShowWizard(false)} />
      )}
      {showFeeForm && (
        <FeeScheduleForm railId={rail.id} onClose={() => setShowFeeForm(false)} />
      )}
    </div>
  )
}

// ─── Create Rail form ────────────────────────────────────────────────────────

function CreateRailForm({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const [tenantId, setTenantId] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('')
  const [processorId, setProcessorId] = useState('')
  const [priority, setPriority] = useState('10')
  const [error, setError] = useState<string | null>(null)

  const { data: processorIds = [] } = useQuery({
    queryKey: ['admin', 'processorIds'],
    queryFn: adminApi.listProcessorIds,
  })

  const PAYMENT_METHODS = [
    'AIRTIME', 'DATA', 'TV_SUBSCRIPTION', 'ELECTRICITY', 'EDUCATION',
    'INSURANCE', 'WALLET_FUNDING', 'BANK_TRANSFER',
  ]

  const create = useMutation({
    mutationFn: () => adminApi.createRail({
      tenantId,
      paymentMethod,
      processorId,
      priority: parseInt(priority, 10),
      configJson: '{}',
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'rails'] })
      onClose()
    },
    onError: (e: unknown) => {
      setError(e instanceof Error ? e.message : 'Failed to create rail')
    },
  })

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 className="text-lg font-semibold">New Payment Rail</h2>

        <div className="space-y-1">
          <label className="block text-sm font-medium">Tenant ID</label>
          <input
            type="text" value={tenantId} onChange={e => setTenantId(e.target.value)}
            placeholder="UUID"
            className="w-full rounded border px-3 py-2 text-sm"
          />
        </div>

        <div className="space-y-1">
          <label className="block text-sm font-medium">Payment Method</label>
          <select
            value={paymentMethod} onChange={e => setPaymentMethod(e.target.value)}
            className="w-full rounded border px-3 py-2 text-sm"
          >
            <option value="">— select —</option>
            {PAYMENT_METHODS.map(m => <option key={m} value={m}>{m}</option>)}
          </select>
        </div>

        <div className="space-y-1">
          <label className="block text-sm font-medium">Processor</label>
          <select
            value={processorId} onChange={e => setProcessorId(e.target.value)}
            className="w-full rounded border px-3 py-2 text-sm"
          >
            <option value="">— select —</option>
            {(processorIds as string[]).map(id => <option key={id} value={id}>{id}</option>)}
          </select>
        </div>

        <div className="space-y-1">
          <label className="block text-sm font-medium">Priority</label>
          <input
            type="number" min={1} value={priority}
            onChange={e => setPriority(e.target.value)}
            className="w-full rounded border px-3 py-2 text-sm"
          />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex gap-2 justify-end pt-2">
          <button onClick={onClose} className="px-4 py-2 text-sm rounded border">Cancel</button>
          <button
            disabled={create.isPending || !tenantId || !paymentMethod || !processorId}
            onClick={() => create.mutate()}
            className="px-4 py-2 text-sm rounded bg-blue-600 text-white disabled:opacity-50"
          >
            {create.isPending ? 'Creating…' : 'Create Rail'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ─── Rails list screen ───────────────────────────────────────────────────────

export function RailsAdminScreen() {
  const [showCreate, setShowCreate] = useState(false)
  const [tenantFilter, setTenantFilter] = useState('')

  const { data: rails = [], isLoading } = useQuery({
    queryKey: ['admin', 'rails', tenantFilter],
    queryFn: () => adminApi.listRails(tenantFilter || undefined),
  })

  return (
    <div className="p-4 space-y-4">
      <div className="flex items-center gap-2 justify-between flex-wrap">
        <h2 className="text-lg font-semibold">Payment Rails</h2>
        <button
          onClick={() => setShowCreate(true)}
          className="text-sm px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700"
        >
          + New Rail
        </button>
      </div>

      <input
        type="text"
        placeholder="Filter by tenant UUID…"
        value={tenantFilter}
        onChange={e => setTenantFilter(e.target.value)}
        className="w-full rounded border px-3 py-2 text-sm"
      />

      {isLoading && <p className="text-sm text-gray-500">Loading…</p>}

      <div className="space-y-3">
        {(rails as PaymentRailConfig[]).map(rail => (
          <RailRow key={rail.id} rail={rail} />
        ))}
        {!isLoading && rails.length === 0 && (
          <p className="text-sm text-gray-500">No rails configured.</p>
        )}
      </div>

      {showCreate && <CreateRailForm onClose={() => setShowCreate(false)} />}
    </div>
  )
}
