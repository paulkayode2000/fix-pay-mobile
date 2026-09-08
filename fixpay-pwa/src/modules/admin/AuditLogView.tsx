import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { adminApi, type AuditLogEntry } from './adminApi'

interface Props {
  railId?: string
}

type DiffKind = 'added' | 'removed' | 'changed'

interface FieldChange {
  key: string
  kind: DiffKind
  before?: unknown
  after?: unknown
}

// Derived / identity keys that add no signal to a change view.
const IGNORED_KEYS = new Set(['id', 'createdAt', 'updatedAt', 'configSchema'])

function parseState(raw: string | null): Record<string, unknown> | null {
  if (!raw) return null
  try {
    const value: unknown = JSON.parse(raw)
    return value && typeof value === 'object' ? (value as Record<string, unknown>) : null
  } catch {
    return null
  }
}

/**
 * Flatten a rail/fee DTO into a diff-friendly flat map.
 * configJson is stored as an opaque JSON string â€” it is parsed and exposed as
 * `config.<field>` paths so config edits produce granular differences.
 */
function flatten(record: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(record)) {
    if (IGNORED_KEYS.has(key)) continue
    if (key === 'configJson') {
      let config: unknown = value
      if (typeof config === 'string') {
        try {
          config = JSON.parse(config)
        } catch {
          config = null
        }
      }
      if (config && typeof config === 'object') {
        for (const [configKey, configValue] of Object.entries(config as Record<string, unknown>)) {
          out[`config.${configKey}`] = configValue
        }
      }
      continue
    }
    out[key] = value
  }
  return out
}

function changesOf(before: Record<string, unknown> | null, after: Record<string, unknown> | null): FieldChange[] {
  const a = flatten(before ?? {})
  const b = flatten(after ?? {})
  const keys = new Set([...Object.keys(a), ...Object.keys(b)])
  const changes: FieldChange[] = []
  for (const key of keys) {
    const hasBefore = Object.prototype.hasOwnProperty.call(a, key)
    const hasAfter = Object.prototype.hasOwnProperty.call(b, key)
    if (!hasBefore) changes.push({ key, kind: 'added', after: b[key] })
    else if (!hasAfter) changes.push({ key, kind: 'removed', before: a[key] })
    else if (JSON.stringify(a[key]) !== JSON.stringify(b[key])) {
      changes.push({ key, kind: 'changed', before: a[key], after: b[key] })
    }
  }
  return changes
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) return 'â€”'
  if (typeof value === 'string') return value
  if (typeof value === 'boolean' || typeof value === 'number') return String(value)
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

function AuditEntryRow({ entry }: { entry: AuditLogEntry }) {
  const before = parseState(entry.beforeStateJson)
  const after = parseState(entry.afterStateJson)
  const changes = changesOf(before, after)

  const added = changes.filter(c => c.kind === 'added').length
  const removed = changes.filter(c => c.kind === 'removed').length
  const changed = changes.filter(c => c.kind === 'changed').length
  const summaryBits: string[] = []
  if (changed > 0) summaryBits.push(`${changed} changed`)
  if (added > 0) summaryBits.push(`${added} added`)
  if (removed > 0) summaryBits.push(`${removed} removed`)

  return (
    <div className="px-3 py-2 space-y-1">
      <div className="flex items-center justify-between gap-2">
        <span className="font-medium">{entry.action}</span>
        <span className="text-gray-400">{new Date(entry.createdAt).toLocaleString()}</span>
      </div>
      <div className="text-gray-500 flex gap-4 flex-wrap">
        <span>{entry.entityType}{entry.entityId ? ` Â· ${entry.entityId.slice(0, 8)}â€¦` : ''}</span>
        {entry.ipAddress && <span>from {entry.ipAddress}</span>}
        {summaryBits.length > 0 && <span className="font-medium text-gray-600">{summaryBits.join(', ')}</span>}
      </div>

      {(before || after) && changes.length === 0 && (
        <p className="text-gray-400 italic">No field differences captured.</p>
      )}
      {changes.length > 0 && (
        <details className="mt-1">
          <summary className="cursor-pointer text-blue-600 hover:underline">View changes</summary>
          <ul className="mt-1 space-y-1 p-2 bg-gray-50 rounded text-[11px]">
            {changes.map(change => (
              <li key={change.key} className="break-words">
                {change.kind === 'added' && (
                  <span>
                    <span className="text-green-700 font-medium">+ {change.key}</span>
                    <span className="text-gray-600"> = {formatValue(change.after)}</span>
                  </span>
                )}
                {change.kind === 'removed' && (
                  <span>
                    <span className="text-red-600 font-medium">âˆ’ {change.key}</span>
                    <span className="text-gray-600"> = {formatValue(change.before)}</span>
                  </span>
                )}
                {change.kind === 'changed' && (
                  <span className="block">
                    <span className="text-gray-700 font-medium">{change.key}</span>:{' '}
                    <span className="text-red-600 line-through">{formatValue(change.before)}</span>
                    {' â†’ '}
                    <span className="text-green-700">{formatValue(change.after)}</span>
                  </span>
                )}
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  )
}

export function AuditLogView({ railId }: Props) {
  const [page, setPage] = useState(0)
  const pageSize = 20

  const { data, isLoading, error } = useQuery({
    queryKey: ['admin', 'audit', railId ?? 'all', page],
    queryFn: () =>
      railId
        ? adminApi.getEntityAuditLog(railId, page, pageSize)
        : adminApi.getAuditLog(page, pageSize),
  })

  const entries: AuditLogEntry[] = data?.content ?? []
  const total = data?.totalElements ?? 0
  const totalPages = Math.ceil(total / pageSize)

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">
          {railId ? 'Rail Audit Log' : 'All Changes'} ({total})
        </h3>
      </div>

      {isLoading && <p className="text-xs text-gray-500">Loadingâ€¦</p>}

      {error && (
        <p className="text-xs text-red-600">
          Failed to load audit log â€” {(error as Error).message}
        </p>
      )}

      {!isLoading && !error && (
        <div className="divide-y rounded border text-xs">
          {entries.map(entry => <AuditEntryRow key={entry.id} entry={entry} />)}
          {entries.length === 0 && (
            <p className="px-3 py-4 text-gray-500 text-center">No audit records.</p>
          )}
        </div>
      )}

      {!error && totalPages > 1 && (
        <div className="flex items-center gap-2 justify-center pt-1">
          <button
            disabled={page === 0}
            onClick={() => setPage(p => p - 1)}
            className="px-3 py-1 rounded border text-xs disabled:opacity-40"
          >â† Prev</button>
          <span className="text-xs text-gray-600">{page + 1} / {totalPages}</span>
          <button
            disabled={page >= totalPages - 1}
            onClick={() => setPage(p => p + 1)}
            className="px-3 py-1 rounded border text-xs disabled:opacity-40"
          >Next â†’</button>
        </div>
      )}
    </div>
  )
}

