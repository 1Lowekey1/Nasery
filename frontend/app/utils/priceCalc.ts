// Price list / calculator math shared by the public calculator, the organizer's
// preview and the offer builder (one place, so they always agree). Amounts are
// worked in centavos to avoid floating-point drift.

import type { PriceOption, PricingUnit } from '~/composables/useQuotations'

export const pricingUnitLabels: Record<PricingUnit, string> = {
  flat: 'Fixed price',
  per_guest: 'Per guest',
  per_hour: 'Per hour',
  per_day: 'Per day',
  per_unit: 'Per item',
}

/** True when the client chooses how many (hours, days, items). */
export function picksQuantity(option: Pick<PriceOption, 'pricing_unit'>): boolean {
  return option.pricing_unit === 'per_hour' || option.pricing_unit === 'per_day' || option.pricing_unit === 'per_unit'
}

/** "hour", "day", "table"… (singular/plural by count). */
export function unitName(option: Pick<PriceOption, 'pricing_unit' | 'unit_label'>, count = 1): string {
  const base = option.pricing_unit === 'per_hour' ? 'hour'
    : option.pricing_unit === 'per_day' ? 'day'
      : option.pricing_unit === 'per_guest' ? 'guest'
        : (option.unit_label?.trim() || 'item')
  return count === 1 || /s$/i.test(base) ? base : `${base}s`
}

/** " / guest", " / hour", " / table"; nothing for a fixed price. */
export function priceSuffix(option: Pick<PriceOption, 'pricing_unit' | 'unit_label'>): string {
  return option.pricing_unit === 'flat' ? '' : ` / ${unitName(option)}`
}

/** Text added to a quotation line's description. */
export function unitSuffix(option: Pick<PriceOption, 'pricing_unit' | 'unit_label'>): string {
  return option.pricing_unit === 'flat' ? '' : ` (per ${unitName(option)})`
}

/** Guest counts the calculator accepts: whole numbers, 1 to 100,000 (the inquiry limit). */
export const MAX_GUESTS = 100000
export function clampGuests(raw: string | number): number {
  return Math.min(MAX_GUESTS, Math.max(1, Math.floor(Number(raw)) || 1))
}

export function quantityBounds(option: Pick<PriceOption, 'min_quantity' | 'max_quantity'>): { min: number, max: number | null } {
  const min = Math.max(1, option.min_quantity ?? 1)
  const max = option.max_quantity ? Math.max(min, option.max_quantity) : null
  return { min, max }
}

/** How many of the option are charged. */
export function optionQuantity(option: PriceOption, guests: number | null, chosen?: number | null): number {
  if (option.pricing_unit === 'per_guest') return Math.max(guests ?? 1, option.min_guests ?? 1, 1)
  if (!picksQuantity(option)) return 1
  const { min, max } = quantityBounds(option)
  const want = Math.floor(Number(chosen ?? min)) || min
  return Math.min(max ?? Number.MAX_SAFE_INTEGER, Math.max(min, want))
}

const cents = (v: string | number | null | undefined) => Math.round(Number(v || 0) * 100)

export interface EstimateLine { id: string, label: string, detail: string, quantity: number, unitPrice: number, subtotal: number }
export interface EstimateSettings { service_charge_percent?: number | null, tax_percent?: number | null, note?: string | null }
export interface Estimate { lines: EstimateLine[], subtotal: number, serviceCharge: number, tax: number, total: number, serviceChargePercent: number, taxPercent: number }

/** Line-by-line estimate for the chosen options (quantities keyed by option id). */
export function buildEstimate(options: PriceOption[], chosen: Record<string, number>, guests: number | null, settings: EstimateSettings = {}): Estimate {
  const lines = options.filter(o => o.id in chosen).map((o) => {
    const quantity = optionQuantity(o, guests, chosen[o.id])
    const subtotalCents = cents(o.price) * quantity
    const detail = o.pricing_unit === 'flat'
      ? 'Fixed price'
      : `${quantity.toLocaleString('en-PH')} ${unitName(o, quantity)} × ${formatPeso(o.price)}`
        + (o.pricing_unit === 'per_guest' && o.min_guests && (guests ?? 0) < o.min_guests ? ` (minimum ${o.min_guests})` : '')
    return { id: o.id, label: o.name, detail, quantity, unitPrice: Number(o.price), subtotal: subtotalCents / 100 }
  })
  const subtotalCents = lines.reduce((sum, l) => sum + Math.round(l.subtotal * 100), 0)
  const serviceChargePercent = Number(settings.service_charge_percent ?? 0) || 0
  const taxPercent = Number(settings.tax_percent ?? 0) || 0
  const serviceChargeCents = Math.round(subtotalCents * serviceChargePercent / 100)
  const taxCents = Math.round((subtotalCents + serviceChargeCents) * taxPercent / 100)
  return {
    lines,
    subtotal: subtotalCents / 100,
    serviceCharge: serviceChargeCents / 100,
    tax: taxCents / 100,
    total: (subtotalCents + serviceChargeCents + taxCents) / 100,
    serviceChargePercent,
    taxPercent,
  }
}

/** "id:qty,id:qty" for links (quantity only where the client chose it). */
/** A service's options are alternatives (pick one); options without a service are add-ons (pick any), listed last. */
export interface OptionGroup { key: string, label: string, single: boolean, options: PriceOption[] }
export function groupOptions(options: PriceOption[], services: { id: string, name: string }[] = []): OptionGroup[] {
  const sorted = [...options].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
  const byService = new Map<string | null, PriceOption[]>()
  for (const o of sorted) byService.set(o.organization_service_id, [...(byService.get(o.organization_service_id) ?? []), o])
  return [...byService.entries()]
    .map(([serviceId, list]) => ({
      key: serviceId ?? 'addons',
      label: serviceId ? services.find(s => s.id === serviceId)?.name ?? 'Options' : 'Add-ons',
      single: serviceId !== null,
      options: list,
    }))
    .sort((a, b) => Number(!a.single) - Number(!b.single))
}

export function encodePicks(options: PriceOption[], chosen: Record<string, number>): string {
  return options.filter(o => o.id in chosen).map(o => (picksQuantity(o) ? `${o.id}:${chosen[o.id]}` : o.id)).join(',')
}

export function decodePicks(value: unknown): Record<string, number> {
  if (typeof value !== 'string' || !value) return {}
  return Object.fromEntries(value.split(',').filter(Boolean).map((part) => {
    const [id, qty] = part.split(':')
    return [id!, Number(qty) || 1]
  }))
}
