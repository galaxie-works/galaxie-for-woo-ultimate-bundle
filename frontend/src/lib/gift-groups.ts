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

      if (item.kind === 'card' && max > 0 && messageLength(cleanMessage(item.message ?? '')) > max) {
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

export interface CardRow {
  id: number
  parent: number
  attrs: Record<string, string>
  stock: number | null
}

/**
 * Which card a gift gets from one card product, for its box (null = no box):
 * the first in stock with no box; a simple card for any box; the card whose
 * shared attribute values all equal the box's; with no label shared at all,
 * the one card whose values include one of the box's, only if exactly one
 * does. Case-insensitive. 0: none. See GiftGroups::card_for().
 */
export function cardFor(cards: CardRow[], parent: number, box: Record<string, string> | null): number {
  const lower = (attrs: Record<string, string>): Record<string, string> =>
    Object.fromEntries(Object.entries(attrs).map(([label, value]) => [label.toLowerCase(), String(value).toLowerCase()]))

  const candidates = cards.filter((card) => card.parent === parent && card.stock !== 0).map((card) => ({ id: card.id, attrs: lower(card.attrs ?? {}) }))

  if (box === null) return candidates.length ? candidates[0].id : 0

  const boxAttrs = lower(box)
  let labels = false

  for (const card of candidates) {
    const shared = Object.keys(card.attrs).filter((label) => label in boxAttrs)

    if (!Object.keys(card.attrs).length) return card.id
    if (!shared.length) continue

    labels = true
    if (shared.every((label) => card.attrs[label] === boxAttrs[label])) return card.id
  }

  const values = Object.values(boxAttrs)
  if (labels || !values.length) return 0

  const matches = candidates.filter((card) => Object.values(card.attrs).some((value) => values.includes(value)))
  return matches.length === 1 ? matches[0].id : 0
}

/**
 * How many more of each size still go in a box: each size on its own, added
 * one at a time while fits() says yes, stopping at MAX_ITEMS in all and at the
 * box's max. Sizes with room, in the order given. See GiftGroups::room_counts().
 */
export function roomCounts(box: Box, candles: Candle[], sizes: Candle[] = [], options: PackingOptions = {}): { size: string; count: number }[] {
  const max = Math.trunc(box.max ?? 0)
  const limit = max > 0 ? Math.min(MAX_ITEMS, max) : MAX_ITEMS
  const out: { size: string; count: number }[] = []
  const seen = new Set<string>()

  for (const size of sizes.length ? sizes : candles) {
    const key = String(size.size ?? '')
    if (seen.has(key)) continue
    seen.add(key)

    const withMore = candles.slice()
    let count = 0

    while (withMore.length < limit) {
      withMore.push(size)
      if (!fits(box, withMore, options)) break
      count++
    }

    if (count > 0) out.push({ size: key, count })
  }

  return out
}

/** The sum of price × quantity, in cents. */
export function total(lines: { price: number; quantity: number }[]): number {
  return lines.reduce((sum, line) => sum + cents(line.price) * Math.max(0, Math.trunc(line.quantity || 0)), 0)
}

/**
 * A card message as the server stores it (GiftGroups::clean_message()): lone
 * surrogates dropped, line breaks as \n, control characters other than \n and
 * \t removed, trimmed. "<3" and "100%" stay.
 */
export function cleanMessage(message: string): string {
  return message
    .replace(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/g, '')
    .replace(/\r\n?/g, '\n')
    .replace(/[\u0000-\u0008\u000B-\u001F\u007F]/g, '')
    .trim()
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
