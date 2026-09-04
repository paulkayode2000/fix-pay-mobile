import type { ServiceVariation } from '@/types'

// ── Category types ────────────────────────────────────────────────────────────

export type ValidityCategory = 'daily' | 'weekly' | 'monthly' | 'other'

export const CATEGORY_LABELS: Record<ValidityCategory, string> = {
  daily:   'Daily',
  weekly:  'Weekly',
  monthly: 'Monthly',
  other:   'Other',
}

export interface CategorizedBundle extends ServiceVariation {
  category: ValidityCategory
}

export interface GroupedVariations {
  daily:   CategorizedBundle[]
  weekly:  CategorizedBundle[]
  monthly: CategorizedBundle[]
  other:   CategorizedBundle[]
}

// ── Name → category detection ─────────────────────────────────────────────────
//
// VTpass returns data-bundle names like:
//   "MTN 100MB Daily"         → daily
//   "MTN 500MB 24hrs"         → daily
//   "Airtel 750MB 1-Day"      → daily
//   "MTN 2GB 7-Day"           → weekly
//   "MTN 500MB Weekly"        → weekly
//   "MTN 1GB (30 days)"       → monthly
//   "MTN 5GB 1 Month"          → monthly
//   "MTN 10GB 3 Months"        → monthly
//   "Smile 500MB"             → other  (no duration keyword)

/**
 * Heuristic parser that extracts a validity category from a VTpass
 * variation `name` string.  Relies on regex keyword matching because
 * VTpass does not expose a separate `validity` / `duration` field.
 */
export function categorizeName(name: string | null | undefined): ValidityCategory {
  if (!name) return 'other'
  const lower = name.toLowerCase()

  // Hour-based plans and 1-day → daily
  if (/\bdaily\b|\b24[- ]?hrs?\b|\b1[- ]?day\b|\b\d{1,2}[- ]?hrs?\b/.test(lower)) return 'daily'
  // 7-day and explicit weekly → weekly
  if (/\bweekly\b|\b7[- ]day/.test(lower)) return 'weekly'
  // 30-day, month, monthly, months → monthly
  if (/\bmonth(ly|s)?\b|\b30[- ]day/.test(lower)) return 'monthly'

  return 'other'
}

// ── Grouping ───────────────────────────────────────────────────────────────────

/**
 * Takes a flat list of VTpass variations and buckets them into
 * daily / weekly / monthly / other groups.
 */
export function groupVariations(variations: ServiceVariation[]): GroupedVariations {
  const grouped: GroupedVariations = { daily: [], weekly: [], monthly: [], other: [] }

  for (const v of variations) {
    const category = categorizeName(v.name)
    grouped[category].push({ ...v, category })
  }

  return grouped
}

/**
 * Returns the ordered list of category keys that actually contain bundles.
 * Helpful for rendering only relevant tab buttons.
 */
export function activeCategories(grouped: GroupedVariations): ValidityCategory[] {
  return (['daily', 'weekly', 'monthly', 'other'] as const)
    .filter(cat => grouped[cat].length > 0)
}
