import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { PageHeader } from '@/components/ui'
import { alertsApi, TMS_UI_BASE, type RiskAlert } from './alertsApi'
import { cn } from '@/lib/utils'
import { BellAlertIcon, CheckCircleIcon, XCircleIcon, ArrowTopRightOnSquareIcon, FlagIcon } from '@heroicons/react/24/outline'

const SEVERITY_STYLE: Record<string, string> = {
  LOW: 'bg-slate-100 text-slate-700',
  MEDIUM: 'bg-amber-100 text-amber-800',
  HIGH: 'bg-orange-100 text-orange-800',
  CRITICAL: 'bg-red-100 text-red-800',
}

const STATUS_STYLE: Record<string, string> = {
  NEW: 'bg-blue-100 text-blue-800',
  REVIEWED: 'bg-emerald-100 text-emerald-800',
  DISMISSED: 'bg-slate-100 text-slate-600',
  ESCALATED: 'bg-red-100 text-red-800',
}

const TYPE_STYLE: Record<string, string> = {
  AML: 'bg-purple-100 text-purple-800',
  ANTIFRAUD: 'bg-cyan-100 text-cyan-800',
}

function tmsLink(alert: RiskAlert): string {
  if (alert.tmsCaseRef) return `${TMS_UI_BASE}/cases?search=${encodeURIComponent(alert.tmsCaseRef)}`
  if (alert.tmsCallRef) return `${TMS_UI_BASE}/screening?search=${encodeURIComponent(alert.tmsCallRef)}`
  return TMS_UI_BASE
}

export function AlertsScreen() {
  const qc = useQueryClient()
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<RiskAlert | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'alerts', status, type, page],
    queryFn: () =>
      alertsApi.list({
        status: status || undefined,
        type: type || undefined,
        page,
        per_page: 20,
      }),
  })

  const { data: unread } = useQuery({
    queryKey: ['admin', 'alerts', 'unread'],
    queryFn: alertsApi.unreadCount,
    refetchInterval: 15_000,
  })

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['admin', 'alerts'] })
  }

  const markSeen = useMutation({
    mutationFn: alertsApi.markSeen,
    onSuccess: invalidate,
  })

  const takeAction = useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'REVIEWED' | 'DISMISSED' | 'ESCALATED' }) =>
      alertsApi.update(id, action),
    onSuccess: () => {
      invalidate()
      setSelected(null)
    },
  })

  const escalate = (alert: RiskAlert) => {
    if (window.confirm(`Escalate this alert and suspend the flagged user?\n\n${alert.summary}`)) {
      takeAction.mutate({ id: alert.id, action: 'ESCALATED' })
    }
  }

  return (
    <div className="p-6 animate-fade-in">
      <PageHeader
        title="Risk Alerts"
        subtitle="AML & antifraud catches flagged by TMS — flag-only, review and act"
        action={
          <button
            onClick={() => markSeen.mutate()}
            disabled={markSeen.isPending || (unread?.count ?? 0) === 0}
            className="flex items-center gap-1.5 text-sm px-3 py-2 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
          >
            <CheckCircleIcon className="w-4 h-4" />
            Mark all seen
          </button>
        }
      />

      {/* Unread banner */}
      <div className="mt-4 flex items-center gap-2 text-sm rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-blue-800">
        <BellAlertIcon className="w-4 h-4" />
        <span>
          <strong>{(unread?.count ?? 0).toLocaleString()}</strong> unread alert{(unread?.count ?? 0) === 1 ? '' : 's'} — flagged by TMS, waiting for review.
        </span>
      </div>

      {/* Filters */}
      <div className="mt-5 flex gap-3 flex-wrap items-center">
        <select
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1) }}
          className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white"
        >
          <option value="">All statuses</option>
          <option value="NEW">New</option>
          <option value="REVIEWED">Reviewed</option>
          <option value="DISMISSED">Dismissed</option>
          <option value="ESCALATED">Escalated</option>
        </select>
        <select
          value={type}
          onChange={(e) => { setType(e.target.value); setPage(1) }}
          className="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white"
        >
          <option value="">All types</option>
          <option value="AML">AML</option>
          <option value="ANTIFRAUD">Antifraud</option>
        </select>
      </div>

      {/* Alert list */}
      <div className="mt-4 glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-slate-500 animate-pulse">Loading alerts…</div>
        ) : (data?.data.length ?? 0) === 0 ? (
          <div className="p-12 text-center text-slate-500">No alerts match the current filters.</div>
        ) : (
          <div className="divide-y divide-slate-100">
            {data?.data.map((alert) => (
              <button
                key={alert.id}
                onClick={() => setSelected(alert)}
                className="w-full text-left px-5 py-3.5 flex items-center gap-3 hover:bg-slate-50 transition-colors"
              >
                <span className={cn('px-2 py-1 text-[11px] font-semibold rounded-full shrink-0', SEVERITY_STYLE[alert.severity])}>
                  {alert.severity}
                </span>
                <span className={cn('px-2 py-1 text-[11px] font-semibold rounded-full shrink-0', TYPE_STYLE[alert.type])}>
                  {alert.type}
                </span>
                <span className="flex-1 min-w-0">
                  <span className="block text-sm text-slate-800 truncate">{alert.summary}</span>
                  <span className="block text-xs text-slate-400">
                    {alert.user ? `${alert.user.first_name ?? ''} ${alert.user.last_name ?? ''}`.trim() || alert.user.email || alert.user.phone : '—'}
                    {alert.tmsCaseRef ? ` · case ${alert.tmsCaseRef}` : ''}
                  </span>
                </span>
                <span className={cn('px-2 py-0.5 text-[11px] font-medium rounded-full shrink-0', STATUS_STYLE[alert.status])}>
                  {alert.status}
                </span>
                <span className="text-xs text-slate-400 shrink-0">
                  {alert.createdAt ? new Date(alert.createdAt).toLocaleString() : ''}
                </span>
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Pagination */}
      {data && data.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm">
          <span className="text-slate-600">Page {data.current_page} of {data.last_page} ({data.total} total)</span>
          <div className="flex gap-2">
            <button
              disabled={data.current_page === 1}
              onClick={() => setPage(p => p - 1)}
              className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white disabled:opacity-40 hover:bg-slate-50"
            >
              Previous
            </button>
            <button
              disabled={data.current_page >= data.last_page}
              onClick={() => setPage(p => p + 1)}
              className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white disabled:opacity-40 hover:bg-slate-50"
            >
              Next
            </button>
          </div>
        </div>
      )}

      {/* Detail drawer */}
      {selected && (
        <div className="fixed inset-0 bg-black/40 z-50 flex justify-end">
          <div className="w-full max-w-xl bg-white h-full overflow-y-auto shadow-xl">
            <div className="p-6 space-y-5">
              <div className="flex items-start justify-between">
                <div>
                  <div className="flex items-center gap-2">
                    <span className={cn('px-2 py-1 text-[11px] font-semibold rounded-full', SEVERITY_STYLE[selected.severity])}>{selected.severity}</span>
                    <span className={cn('px-2 py-1 text-[11px] font-semibold rounded-full', TYPE_STYLE[selected.type])}>{selected.type}</span>
                    <span className={cn('px-2 py-1 text-[11px] font-semibold rounded-full', STATUS_STYLE[selected.status])}>{selected.status}</span>
                  </div>
                  <h2 className="mt-3 text-lg font-semibold text-slate-900">{selected.summary}</h2>
                  <p className="text-xs text-slate-400 mt-1">
                    {selected.createdAt ? new Date(selected.createdAt).toLocaleString() : ''}
                  </p>
                </div>
                <button onClick={() => setSelected(null)} className="text-slate-400 hover:text-slate-700">
                  <XCircleIcon className="w-6 h-6" />
                </button>
              </div>

              {selected.user && (
                <div className="rounded-lg border border-slate-200 p-4 space-y-1">
                  <p className="text-xs font-medium text-slate-500">Flagged user</p>
                  <p className="text-sm font-medium text-slate-800">
                    {`${selected.user.first_name ?? ''} ${selected.user.last_name ?? ''}`.trim() || '—'}
                  </p>
                  <p className="text-xs text-slate-500">{selected.user.email ?? '—'} · {selected.user.phone ?? '—'}</p>
                </div>
              )}

              <div className="rounded-lg border border-slate-200 p-4 space-y-2">
                <p className="text-xs font-medium text-slate-500">TMS references</p>
                <div className="flex flex-wrap gap-2">
                  {selected.tmsCaseRef && (
                    <span className="px-2 py-1 rounded bg-slate-100 text-xs font-mono text-slate-700">case: {selected.tmsCaseRef}</span>
                  )}
                  {selected.tmsCallRef && (
                    <span className="px-2 py-1 rounded bg-slate-100 text-xs font-mono text-slate-700">call: {selected.tmsCallRef}</span>
                  )}
                  <a
                    href={tmsLink(selected)}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-indigo-50 text-indigo-700 text-xs font-medium hover:bg-indigo-100"
                  >
                    <ArrowTopRightOnSquareIcon className="w-3.5 h-3.5" />
                    Open in TMS
                  </a>
                </div>
              </div>

              {selected.detail && (
                <details className="rounded-lg border border-slate-200 p-4">
                  <summary className="cursor-pointer text-xs font-medium text-indigo-600 hover:underline">View full TMS response</summary>
                  <pre className="mt-2 p-3 bg-slate-50 rounded text-[11px] overflow-x-auto whitespace-pre-wrap max-h-72 overflow-y-auto">
                    {JSON.stringify(selected.detail, null, 2)}
                  </pre>
                </details>
              )}

              <div className="flex flex-wrap gap-2 pt-2 border-t border-slate-100">
                <button
                  onClick={() => takeAction.mutate({ id: selected.id, action: 'REVIEWED' })}
                  disabled={takeAction.isPending || selected.status === 'REVIEWED'}
                  className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-40"
                >
                  <CheckCircleIcon className="w-4 h-4" /> Mark reviewed
                </button>
                <button
                  onClick={() => takeAction.mutate({ id: selected.id, action: 'DISMISSED' })}
                  disabled={takeAction.isPending || selected.status === 'DISMISSED'}
                  className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm rounded-lg border border-slate-200 text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                >
                  <XCircleIcon className="w-4 h-4" /> Dismiss (false positive)
                </button>
                <button
                  onClick={() => escalate(selected)}
                  disabled={takeAction.isPending || selected.status === 'ESCALATED'}
                  className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm rounded-lg bg-red-600 text-white hover:bg-red-700 disabled:opacity-40"
                >
                  <FlagIcon className="w-4 h-4" /> Escalate & suspend
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
