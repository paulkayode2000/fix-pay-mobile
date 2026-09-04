// ── Category types ────────────────────────────────────────────────────────────

export type ValidityCategory = 'daily' | 'weekly' | 'monthly' | 'other'

export const CATEGORY_LABELS: Record<ValidityCategory, string> = {
  daily:   'Daily',
  weekly:  'Weekly',
  monthly: 'Monthly',
  other:   'Other',
}

export interface CategorizedBundle {
  variation_code: string
  name: string
  variation_amount: string
  category: ValidityCategory
}

export interface GroupedVariations {
  daily:   CategorizedBundle[]
  weekly:  CategorizedBundle[]
  monthly: CategorizedBundle[]
  other:   CategorizedBundle[]
}

// ── Name → category detection ─────────────────────────────────────────────────

export function categorizeName(name: string | null | undefined): ValidityCategory {
  if (!name) return 'other'
  const lower = name.toLowerCase()

  // Hour-based plans and 1-day → daily
  if (/\bdaily\b|\b24[- ]?hrs?\b|\b1[- ]?day\b|\b\d{1,2}[- ]?hrs?\b/.test(lower)) return 'daily'
  // 7-day and explicit weekly → weekly
  if (/\bweekly\b|\b7[- ]day/.test(lower)) return 'weekly'
  // 30-day and explicit monthly → monthly
  // 30-day, month, monthly, months → monthly
  if (/\bmonth(ly|s)?\b|\b30[- ]day/.test(lower)) return 'monthly'

  return 'other'
}

// ── Grouping ───────────────────────────────────────────────────────────────────

export function groupVariations(variations: { variation_code: string; name: string; variation_amount: string }[]): GroupedVariations {
  const grouped: GroupedVariations = { daily: [], weekly: [], monthly: [], other: [] }

  for (const v of variations) {
    const category = categorizeName(v.name)
    grouped[category].push({ ...v, category })
  }

  return grouped
}

export function activeCategories(grouped: GroupedVariations): ValidityCategory[] {
  return (['daily', 'weekly', 'monthly', 'other'] as const)
    .filter(cat => grouped[cat].length > 0)
}
