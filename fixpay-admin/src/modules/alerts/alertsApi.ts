import { api } from '@/lib/api'

export type AlertType = 'AML' | 'ANTIFRAUD'
export type AlertSeverity = 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL'
export type AlertStatus = 'NEW' | 'REVIEWED' | 'DISMISSED' | 'ESCALATED'

export interface RiskAlertUser {
  id: string
  email: string | null
  phone: string | null
  first_name: string | null
  last_name: string | null
}

export interface RiskAlert {
  id: string
  type: AlertType
  severity: AlertSeverity
  status: AlertStatus
  summary: string
  detail: Record<string, unknown> | null
  userId: string | null
  user: RiskAlertUser | null
  assessableType: string | null
  assessableId: string | null
  tmsCaseRef: string | null
  tmsCallRef: string | null
  createdAt: string | null
  reviewedAt: string | null
}

export interface AlertPage {
  data: RiskAlert[]
  current_page: number
  last_page: number
  total: number
}

export const alertsApi = {
  list: (params: { status?: string; type?: string; page?: number; per_page?: number } = {}) =>
    api.get<AlertPage>('/admin/alerts', { params }).then(r => r.data),

  unreadCount: () => api.get<{ count: number }>('/admin/alerts/unread-count').then(r => r.data),

  markSeen: () => api.post<{ marked: number }>('/admin/alerts/seen').then(r => r.data),

  update: (id: string, action: Extract<AlertStatus, 'REVIEWED' | 'DISMISSED' | 'ESCALATED'>, note?: string) =>
    api.patch<RiskAlert>(`/admin/alerts/${id}`, { action, note }).then(r => r.data),
}

// Base URL of the TMS UI (Vite dev server) for "Open in TMS" deep links.
export const TMS_UI_BASE = import.meta.env.VITE_TMS_UI_URL ?? 'http://localhost:5173'
