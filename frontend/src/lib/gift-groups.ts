/**
 * Gifts as groups — the TypeScript twin of `src/Support/GiftGroups.php`: how
 * full a box is, what a planned gift may not do, and what it costs.
 *
 * The builder popup paints its fill bars, stock refusals and running total with
 * these; the server runs the PHP twin on the same plan before anything reaches
 * the cart. Both are held to `tests/gift-packing/fixtures.json` (`php
 * tests/gift-packing/run.php`, `node tests/gift-packing/run.ts`): change one,
 * change the other, run both.
 *
 * Space is always `fits()` from gift-packing.ts with the options given, so the
 * orientation and gap settings reach every answer here unchanged.
 *
 * Plain functions over plain data, erasable TypeScript only (Node runs the
 * test straight from source).
 */

import { arrange, fits, MAX_ITEMS } from './gift-packing.ts'
import type { Box, Candle, Gift, PackingOptions } from './gift-packing.ts'

export interface PlanCandle extends Candle {
  id: string | number
  /** False for a candle already in the cart: checked for fit, asks no stock. */
  added?: boolean
}

export interface PlanBox extends Box {
  /** False for the box a gift already has. */
  added?: boolean
}

export interface PlanItem {
  id: string | number
  kind: 'ribbon' | 'card'
  quantity: number
  message?: string
}

export interface PlanGroup {
  candles: PlanCandle[]
  box: PlanBox | null
  items: PlanItem[]
}

export interface Plan {
  groups: PlanGroup[]
  /** Units left per product id; null or missing = not limited. */
  stock: Record<string, number | null>
  /** Units of each product id already in the cart. */
  in_cart: Record<string, number>
  message_max: number
}

export type PlanErrorCode = 'no_candles' | 'box_too_small' | 'bad_quantity' | 'message_too_long' | 'out_of_stock'

export interface PlanError {
  code: PlanErrorCode
  /** Index into plan.groups, or -1 for stock. */
  group: number
  id: string | null
}

/**
 * How full a box is, 0–100: the candles in it against the candles it could
 * hold, filling what is left with the smallest size on offer. Counted through
 * `fits()`, so it follows whatever orientation the options say.
 */
export function fill(box: Box, candles: Candle[], sizes: Candle[] = [], options: PackingOptions = {}): number {
  const n = candles.length

  if (n === 0) return 0
  if (!fits(box, candles, options)) return 100

  const smallest = smallestOf(sizes.length ? sizes : candles)
  const withMore = candles.slice()
  let extra = 0

  while (smallest && withMore.length < MAX_ITEMS) {
    withMore.push(smallest)
    if (!fits(box, withMore, options)) break
    extra++
  }

  return Math.floor((n * 100 + Math.floor((n + extra) / 2)) / (n + extra))
}

/**
 * Shares any number of candles out over boxes, leaving loose only what no box
 * can take at all: exact `arrange()` for MAX_ITEMS or fewer, boxes filled one
 * at a time (largest candles first, exact fit per box, the box taking the most,
 * then the cheaper, then the earlier) past that. See GiftGroups::arrange_all().
 */
export function arrangeAll<C extends Candle, B extends Box>(candles: C[], boxes: B[], options: PackingOptions = {}): { gifts: Gift<C, B>[]; loose: C[] } {
  const loose: C[] = []
  const rest: C[] = []

  for (const candle of candles) {
    if (boxes.some((box) => fits(box, [candle], options))) rest.push(candle)
    else loose.push(candle)
  }

  const order = rest.map((_, i) => i).sort((p, q) => volume(rest[q]) - volume(rest[p]) || p - q)
  const left = new Map<number, C>(order.map((i, n) => [n, rest[i]]))
  const gifts: Gift<C, B>[] = []

  while (left.size > MAX_ITEMS) {
    let best: { box: B; taken: number[]; candles: C[] } | null = null

    for (const box of boxes) {
      const taken: number[] = []
      const chosen: C[] = []

      for (const [i, candle] of left) {
        if (chosen.length >= MAX_ITEMS) break
        if (fits(box, [...chosen, candle], options)) {
          chosen.push(candle)
          taken.push(i)
        }
      }

      if (!taken.length) continue

      if (!best || taken.length > best.taken.length || (taken.length === best.taken.length && cents(box.price) < cents(best.box.price))) {
        best = { box, taken, candles: chosen }
      }
    }

    if (!best) break
    for (const i of best.taken) left.delete(i)
    gifts.push({ box: best.box, candles: best.candles })
  }

  if (left.size) gifts.push(...arrange([...left.values()], boxes, options))

  return { gifts, loose }
}

function volume(candle: Candle): number {
  return units(candle.length) * units(candle.width) * units(candle.height)
}

/** The most of one candle line a boxed gift can take, never less than it has. */
export function maxQuantity(box: Box, others: Candle[], candle: Candle, current: number, options: PackingOptions = {}): number {
  let n = Math.max(0, current)

  while (others.length + n < MAX_ITEMS) {
    const withMore = others.slice()
    for (let i = 0; i <= n; i++) withMore.push(candle)

    if (!fits(box, withMore, options)) break
    n++
  }

  return n
}

/**
 * Everything wrong with a planned gift, in a fixed order: each group's own
 * problems, group by group, then stock by product in the order first asked.
 */
export function validate(plan: Plan, options: PackingOptions = {}): PlanError[] {
  const errors: PlanError[] = []
  const demand = new Map<string, number>()
  const max = Math.max(0, Math.trunc(plan.message_max || 0))

  const ask = (id: string | number, quantity: number): void => {
    const key = String(id)
    demand.set(key, (demand.get(key) ?? 0) + quantity)
  }

  plan.groups.forEach((group, g) => {
    const candles = group.candles ?? []
    const box = group.box ?? null

    if (!candles.length) errors.push(error('no_candles', g))

    for (const candle of candles) {
      if (candle.added !== false) ask(candle.id ?? '', 1)
    }

    if (box) {
      if (candles.length && !fits(box, candles, options)) errors.push(error('box_too_small', g, box.id ?? ''))
      if (box.added !== false) ask(box.id ?? '', 1)
    }

    for (const item of group.items ?? []) {
      const quantity = item.quantity

      if (!Number.isInteger(quantity) || quantity < 1) {
        errors.push(error('bad_quantity', g, item.id ?? ''))
        continue
      }

      if (item.kind === 'card' && max > 0 && messageLength(item.message ?? '') > max) {
        errors.push(error('message_too_long', g, item.id ?? ''))
      }

      ask(item.id ?? '', quantity)
    }
  })

  for (const [id, quantity] of demand) {
    const limit = plan.stock?.[id]

    if (limit !== null && limit !== undefined && quantity + Math.trunc(plan.in_cart?.[id] ?? 0) > Math.trunc(limit)) {
      errors.push(error('out_of_stock', -1, id))
    }
  }

  return errors
}

/** The sum of price × quantity, in cents. */
export function total(lines: { price: number; quantity: number }[]): number {
  return lines.reduce((sum, line) => sum + cents(line.price) * Math.max(0, Math.trunc(line.quantity || 0)), 0)
}

/** Characters, not UTF-16 units, with a Windows line break counted once. */
export function messageLength(message: string): number {
  return Array.from(message.replace(/\r\n/g, '\n')).length
}

function error(code: PlanErrorCode, group: number, id: string | number | null = null): PlanError {
  return { code, group, id: id === null ? null : String(id) }
}

function smallestOf(candles: Candle[]): Candle | null {
  let best: Candle | null = null
  let low = Infinity

  for (const candle of candles) {
    const volume = units(candle.length) * units(candle.width) * units(candle.height)

    if (volume > 0 && volume < low) {
      low = volume
      best = candle
    }
  }

  return best
}

function units(cm: number): number {
  return Number(cm) > 0 ? Math.floor(Number(cm) * 100 + 0.5) : 0
}

function cents(price: number): number {
  return Number(price) > 0 ? Math.floor(Number(price) * 100 + 0.5) : 0
}
