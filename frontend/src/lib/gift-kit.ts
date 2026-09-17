/**
 * Gift kits — the TypeScript twin of `src/Support/GiftKit.php`: what still
 * fits in a kit's box, in words, and the kit's name.
 *
 * Held to `tests/kit/fixtures.json` with the PHP twin (`php tests/kit/run.php`,
 * `node tests/kit/run.ts`): change one, change the other, run both.
 *
 * Plain functions over plain data, erasable TypeScript only (Node runs the
 * test straight from source).
 */

import { fits, MAX_ITEMS, withDeadline } from './gift-packing.ts'
import type { Box, Candle, PackingOptions } from './gift-packing.ts'
import { cleanMessage } from './gift-groups.ts'

/** Longest kit name, in characters. */
export const NAME_MAX = 40

/** Mixed combinations shown after the single sizes. */
export const MIXES = 2

/** Fit checks one combos() call may make. */
export const CHECK_LIMIT = 600

/** Wall-clock budget of one combos() call, in ms (GiftKit::BUDGET_MS). */
export const BUDGET_MS = 250

export interface ComboEntry {
  size: string
  count: number
}

export interface Combos {
  singles: ComboEntry[][]
  mixes: ComboEntry[][]
  /** False when some size's most could not be settled in the budget. */
  complete?: boolean
}

export type RoomState = 'full' | 'one' | 'many' | 'unknown'

export interface Wording {
  state: RoomState
  combos: string
}

function units(cm: number): number {
  return Number(cm) > 0 ? Math.floor(Number(cm) * 100 + 0.5) : 0
}

function volume(candle: Candle): number {
  return units(candle.length) * units(candle.width) * units(candle.height)
}

/** Distinct sizes, largest first (first seen on a tie). */
function ordered(sizes: Candle[]): Candle[] {
  const seen = new Map<string, Candle>()

  for (const size of sizes) {
    const key = String(size.size ?? '')
    if (!seen.has(key) && volume(size) > 0) seen.set(key, size)
  }

  const list = Array.from(seen.values())
  const order = list.map((_, i) => i)
  order.sort((a, b) => volume(list[b]) - volume(list[a]) || a - b)

  return order.map((i) => list[i])
}

function sum(values: number[]): number {
  return values.reduce((total, value) => total + value, 0)
}

function compareDesc(a: number[], b: number[]): number {
  for (let i = 0; i < a.length; i++) {
    if (a[i] !== b[i]) return b[i] - a[i]
  }
  return 0
}

/** What still fits, as rows of { size, count } in size order. See GiftKit::combos(). */
export function combos(box: Box, candles: Candle[], sizes: Candle[], options: PackingOptions = {}, budgetMs = BUDGET_MS): Combos {
  const list = ordered(sizes)
  const k = list.length
  let limit = MAX_ITEMS
  const max = Math.trunc(Number(box.max ?? 0))

  if (max > 0) limit = Math.min(limit, max)

  const room = limit - candles.length

  if (k === 0 || room < 1) return { singles: [], mixes: [], complete: true }

  const found = withDeadline(budgetMs, () => {
    let checks = 0
    const memo = new Map<string, boolean | null>()

    // true / false, or null when the answer is not known (budget, limit).
    const check = (v: number[]): boolean | null => {
      const key = v.join(',')
      if (memo.has(key)) return memo.get(key) as boolean | null

      if (sum(v) > room) {
        memo.set(key, false)
        return false
      }

      if (checks >= CHECK_LIMIT) return null

      checks++
      const group = [...candles]
      v.forEach((c, i) => {
        for (let j = 0; j < c; j++) group.push(list[i])
      })

      const result = withDeadline(3600000, () => fits(box, group, options))
      // A "no" the clock gave is not an answer.
      const answer = result.expired && !result.value ? null : result.value
      memo.set(key, answer)
      return answer
    }

    // The most of each size alone: one size, so each check is quick.
    const singles = new Map<number, number[]>()
    let complete = true

    for (let i = 0; i < k; i++) {
      let v = new Array<number>(k).fill(0)
      let answer: boolean | null = true

      for (;;) {
        const next = [...v]
        next[i]++
        answer = check(next)
        if (answer !== true) break
        v = next
      }

      if (answer === null) {
        complete = false
        continue
      }

      if (v[i] > 0) singles.set(i, v)
    }

    // Mixes that leave no room for one more candle of any size.
    const mixes: number[][] = []
    let settled = complete
    const queue: number[][] = [new Array<number>(k).fill(0)]
    const seen = new Set<string>([queue[0].join(',')])

    for (let q = 0; settled && q < queue.length; q++) {
      const v = queue[q]
      let maximal = true

      for (let i = 0; i < k; i++) {
        const next = [...v]
        next[i]++
        const answer = check(next)

        if (answer === null) {
          settled = false
          break
        }

        if (!answer) continue

        maximal = false
        const key = next.join(',')

        if (!seen.has(key)) {
          seen.add(key)
          queue.push(next)
        }
      }

      if (settled && maximal && v.filter((c) => c > 0).length > 1) mixes.push(v)
    }

    return { singles, mixes: settled ? mixes : [], complete }
  }).value

  const volumes = list.map(volume)

  found.mixes.sort((a, b) => {
    const va = sum(a.map((c, i) => c * volumes[i]))
    const vb = sum(b.map((c, i) => c * volumes[i]))
    return vb - va || sum(b) - sum(a) || compareDesc(a, b)
  })

  const row = (v: number[]): ComboEntry[] =>
    v.flatMap((c, i) => (c > 0 ? [{ size: String(list[i].size), count: c }] : []))

  return {
    singles: Array.from(found.singles.keys())
      .sort((a, b) => a - b)
      .map((i) => row(found.singles.get(i) as number[])),
    mixes: found.mixes.slice(0, MIXES).map(row),
    complete: found.complete,
  }
}

/** combos() in words. See GiftKit::wording(). */
export function wording(found: Combos, labels: Record<string, string> = {}, or = ' ou ', plus = ' + '): Wording {
  const rows = [...found.singles, ...found.mixes]
  const complete = found.complete !== false

  if (!rows.length) return { state: complete ? 'full' : 'unknown', combos: '' }

  const text = rows.map((entries) => entries.map((entry) => `${entry.count} × ${labels[entry.size] ?? entry.size}`).join(plus)).join(or)
  // "Only one fits" is only said when every size was settled.
  const one = complete && rows.length === 1 && sum(rows[0].map((entry) => entry.count)) === 1

  return { state: one ? 'one' : 'many', combos: text }
}

/** A merchant's text with `{name}` placeholders filled; unknown ones stay. */
export function fillText(text: string, values: Record<string, string>): string {
  return (text ?? '').replace(/\{([a-zà-ú_]+)\}/gu, (match, key: string) => (Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match))
}

/** A kit name as the server keeps it. See GiftKit::clean_name(). */
export function cleanName(name: string): string {
  const edges = /^[\t\n ]+|[\t\n ]+$/g
  const folded = cleanMessage(name).replace(/[\t\n ]+/g, ' ').replace(edges, '')
  const chars = Array.from(folded)

  return chars.length > NAME_MAX ? chars.slice(0, NAME_MAX).join('').replace(edges, '') : folded
}

/** "Kit N" with the first N no other name takes. */
export function defaultName(used: string[], format = 'Kit %d'): string {
  const taken = new Set(used.map((name) => name.toLowerCase()))

  for (let n = 1; ; n++) {
    const name = format.replace('%d', String(n))
    if (!taken.has(name.toLowerCase())) return name
  }
}

/**
 * The tags a merchant's title or text may carry, matching what the widget lets
 * through with wp_kses() when it prints the same slots on the server.
 */
const RICH_TAGS = new Set(['BR', 'STRONG', 'B', 'EM', 'I', 'U', 'SMALL', 'SPAN'])

/**
 * A title or a text written in the Elementor panel: line breaks and a little
 * emphasis survive, everything else is unwrapped to its words. Parsing happens
 * inside a <template>, so nothing in it loads or runs on the way.
 */
export function setRich(el: Element | null | undefined, html: string): void {
  if (!el) return

  const template = document.createElement('template')
  template.innerHTML = html

  const clean = (node: ParentNode): void => {
    Array.from(node.children).forEach((child) => {
      clean(child)

      if (!RICH_TAGS.has(child.tagName)) {
        child.replaceWith(...Array.from(child.childNodes))
        return
      }

      Array.from(child.attributes).forEach((attr) => {
        if (child.tagName !== 'SPAN' || attr.name !== 'class') child.removeAttribute(attr.name)
      })
    })
  }

  clean(template.content)

  const holder = document.createElement('div')
  holder.append(template.content.cloneNode(true))

  if (el.innerHTML !== holder.innerHTML) el.replaceChildren(template.content)
}
