import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { railApi, type PaymentRailConfig, type FeeSchedule } from './railApi'
import { ProcessorConfigWizard } from './ProcessorConfigWizard'
import { FeeScheduleForm } from './FeeScheduleForm'
import { AuditLogView } from './AuditLogView'
import { cn } from '@/lib/utils'

type PanelView = 'fees' | 'audit' | null

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
    queryFn: () => railApi.listFees(rail.id),
    enabled: panel === 'fees',
  })

  const toggleEnabled    = useMutation({ mutationFn: (e: boolean) => railApi.toggleEnabled(rail.id, e),    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'rails'] }) })
  const toggleMaintenance = useMutation({ mutationFn: (m: boolean) => railApi.setMaintenance(rail.id, m), onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'rails'] }) })
  const deleteFee         = useMutation({ mutationFn: (feeId: string) => railApi.deleteFee(rail.id, feeId), onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'fees', rail.id] }) })
  const deleteRail        = useMutation({ mutationFn: () => railApi.deleteRail(rail.id),                   onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'rails'] }) })

  const statusBadge = rail.maintenance
    ? <span className="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">Maintenance</span>
    : rail.enabled
    ? <span className="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">Enabled</span>
    : <span className="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">Disabled</span>

  return (
    <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex items-start justify-between px-5 py-4">
        <div className="space-y-0.5">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="font-medium text-sm text-slate-900">{rail.processorId}</span>
            <span className="text-xs text-slate-400">{rail.paymentMethod}</span>
            <span className="text-xs text-slate-400">priority {rail.priority}</span>
            {statusBadge}
          </div>
          {rail.configSchema && (
            <p className="text-xs text-slate-500">{rail.configSchema.displayName} — {rail.configSchema.category}</p>
          )}
        </div>
        <div className="flex items-center gap-1.5 shrink-0 flex-wrap justify-end">
          <button onClick={() => setShowWizard(true)} className="text-xs px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100">Configure</button>
          <button onClick={() => toggleEnabled.mutate(!rail.enabled)} disabled={toggleEnabled.isPending} className="text-xs px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-50">{rail.enabled ? 'Disable' : 'Enable'}</button>
          <button onClick={() => toggleMaintenance.mutate(!rail.maintenance)} disabled={toggleMaintenance.isPending} className="text-xs px-2.5 py-1 rounded-lg bg-amber-50 text-amber-700 hover:bg-amber-100 disabled:opacity-50">{rail.maintenance ? 'End maint.' : 'Maintenance'}</button>
          <button onClick={() => { if (window.confirm(`Delete rail for ${rail.processorId}?`)) deleteRail.mutate() }} disabled={deleteRail.isPending} className="text-xs px-2.5 py-1 rounded-lg bg-red-50 text-red-700 hover:bg-red-100 disabled:opacity-50">Delete</button>
        </div>
      </div>

      <div className="flex gap-1 px-5 pb-3">
        {(['fees', 'audit'] as PanelView[]).map(p => (
          <button key={p} onClick={() => setPanel(panel === p ? null : p)}
            className={cn('text-xs px-2.5 py-0.5 rounded-lg border', panel === p ? 'bg-indigo-600 text-white border-indigo-600' : 'text-slate-600 hover:bg-slate-50')}>
            {p === 'fees' ? 'Fees' : 'Audit log'}
          </button>
        ))}
      </div>

      {panel === 'fees' && (
        <div className="border-t px-5 py-4 space-y-3">
          <div className="flex items-center justify-between">
            <h4 className="text-xs font-semibold text-slate-700">Fee Schedules</h4>
            <button onClick={() => setShowFeeForm(true)} className="text-xs px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">+ Add Fee</button>
          </div>
          {fees.length === 0 && <p className="text-xs text-slate-500 italic">No fee schedules. Transactions are free.</p>}
          {(fees as FeeSchedule[]).map(fee => (
            <div key={fee.id} className="flex items-center justify-between text-xs border rounded-lg px-3 py-2">
              <div className="space-y-0.5">
                <div className="font-medium">{fee.feeType}</div>
                <div className="text-slate-500">
                  {fee.fixedFeeKobo > 0 && `Fixed: ${naira(fee.fixedFeeKobo)} `}
                  {fee.percentageFee > 0 && `${(fee.percentageFee * 100).toFixed(4)}% `}
                  {fee.minFeeKobo > 0 && `min amount ${naira(fee.minFeeKobo)} `}
                  {fee.capKobo != null && `cap ${naira(fee.capKobo)}`}
                </div>
                <div className="text-slate-400">{fee.effectiveFrom} → {fee.effectiveTo ?? 'ongoing'}</div>
              </div>
              <button onClick={() => { if (window.confirm('Delete fee?')) deleteFee.mutate(fee.id) }} className="text-red-600 hover:underline ml-2">Remove</button>
            </div>
          ))}
        </div>
      )}

      {panel === 'audit' && (
        <div className="border-t px-5 py-4"><AuditLogView railId={rail.id} /></div>
      )}

      {showWizard && <ProcessorConfigWizard rail={rail} onClose={() => setShowWizard(false)} />}
      {showFeeForm && <FeeScheduleForm railId={rail.id} onClose={() => setShowFeeForm(false)} />}
    </div>
  )
}

function CreateRailForm({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const [tenantId, setTenantId] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('')
  const [processorId, setProcessorId] = useState('')
  const [priority, setPriority] = useState('10')
  const [error, setError] = useState<string | null>(null)

  const { data: processorIds = [] } = useQuery({
    queryKey: ['admin', 'processorIds'],
    queryFn: railApi.listProcessorIds,
  })

  const PAYMENT_METHODS = ['AIRTIME', 'DATA', 'TV_SUBSCRIPTION', 'ELECTRICITY', 'EDUCATION', 'INSURANCE', 'WALLET_FUNDING', 'BANK_TRANSFER']

  const create = useMutation({
    mutationFn: () => railApi.createRail({ tenantId, paymentMethod, processorId, priority: parseInt(priority, 10), configJson: '{}' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin', 'rails'] }); onClose() },
    onError: (e: unknown) => setError(e instanceof Error ? e.message : 'Failed to create rail'),
  })

  const inputCls = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm bg-white'

  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4">
        <h2 className="text-base font-semibold">New Payment Rail</h2>
        <div className="flex flex-col gap-1"><label className="text-xs font-medium text-slate-600">Tenant ID</label><input type="text" value={tenantId} onChange={e => setTenantId(e.target.value)} placeholder="UUID" className={inputCls} /></div>
        <div className="flex flex-col gap-1"><label className="text-xs font-medium text-slate-600">Payment Method</label>
          <select value={paymentMethod} onChange={e => setPaymentMethod(e.target.value)} className={inputCls}>
            <option value="">— select —</option>
            {PAYMENT_METHODS.map(m => <option key={m} value={m}>{m}</option>)}
          </select>
        </div>
        <div className="flex flex-col gap-1"><label className="text-xs font-medium text-slate-600">Processor</label>
          <select value={processorId} onChange={e => setProcessorId(e.target.value)} className={inputCls}>
            <option value="">— select —</option>
            {(processorIds as string[]).map(id => <option key={id} value={id}>{id}</option>)}
          </select>
        </div>
        <div className="flex flex-col gap-1"><label className="text-xs font-medium text-slate-600">Priority</label><input type="number" min={1} value={priority} onChange={e => setPriority(e.target.value)} className={inputCls} /></div>
        {error && <p className="text-sm text-red-600">{error}</p>}
        <div className="flex gap-2 justify-end pt-2">
          <button onClick={onClose} className="px-4 py-2 text-sm rounded-lg border">Cancel</button>
          <button disabled={create.isPending || !tenantId || !paymentMethod || !processorId} onClick={() => create.mutate()} className="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white disabled:opacity-50">{create.isPending ? 'Creating…' : 'Create Rail'}</button>
        </div>
      </div>
    </div>
  )
}

export function RailsAdminScreen() {
  const [showCreate, setShowCreate] = useState(false)
  const [tenantFilter, setTenantFilter] = useState('')
  const { data: rails = [], isLoading } = useQuery({
    queryKey: ['admin', 'rails', tenantFilter],
    queryFn: () => railApi.listRails(tenantFilter || undefined),
  })

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3 flex-wrap">
        <input
          type="text" value={tenantFilter} onChange={e => setTenantFilter(e.target.value)}
          placeholder="Filter by Tenant ID"
          className="flex-1 min-w-48 rounded-lg border border-slate-200 px-3 py-2 text-sm"
        />
        <button onClick={() => setShowCreate(true)} className="text-sm px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">+ New Rail</button>
      </div>
      {isLoading && <p className="text-sm text-slate-500">Loading rails…</p>}
      {(rails as PaymentRailConfig[]).map(r => <RailRow key={r.id} rail={r} />)}
      {!isLoading && rails.length === 0 && <p className="text-sm text-slate-500 text-center py-8">No payment rails configured.</p>}
      {showCreate && <CreateRailForm onClose={() => setShowCreate(false)} />}
    </div>
  )
}
