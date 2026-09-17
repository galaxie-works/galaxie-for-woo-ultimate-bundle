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

import { fits, MAX_ITEMS } from './gift-packing.ts'
import type { Box, Candle, PackingOptions } from './gift-packing.ts'
import { cleanMessage } from './gift-groups.ts'

/** Longest kit name, in characters. */
export const NAME_MAX = 40

/** Mixed combinations shown after the single sizes. */
export const MIXES = 2

/** Fit checks one combos() call may make. */
export const CHECK_LIMIT = 600

export interface ComboEntry {
  size: string
  count: number
}

export interface Combos {
  singles: ComboEntry[][]
  mixes: ComboEntry[][]
}

export type RoomState = 'full' | 'one' | 'many'

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
export function combos(box: Box, candles: Candle[], sizes: Candle[], options: PackingOptions = {}): Combos {
  const list = ordered(sizes)
  const k = list.length
  let limit = MAX_ITEMS
  const max = Math.trunc(Number(box.max ?? 0))

  if (max > 0) limit = Math.min(limit, max)

  const room = limit - candles.length

  if (k === 0 || room < 1) return { singles: [], mixes: [] }

  let checks = 0
  const memo = new Map<string, boolean>()

  const check = (v: number[]): boolean => {
    const key = v.join(',')
    const known = memo.get(key)
    if (known !== undefined) return known

    if (sum(v) > room || checks >= CHECK_LIMIT) {
      memo.set(key, false)
      return false
    }

    checks++
    const group = [...candles]
    v.forEach((c, i) => {
      for (let j = 0; j < c; j++) group.push(list[i])
    })

    const result = fits(box, group, options)
    memo.set(key, result)
    return result
  }

  const singles = new Map<number, number[]>()
  const mixes: number[][] = []
  const queue: number[][] = [new Array<number>(k).fill(0)]
  const seen = new Set<string>([queue[0].join(',')])

  for (let q = 0; q < queue.length; q++) {
    const v = queue[q]
    let maximal = true

    for (let i = 0; i < k; i++) {
      const next = [...v]
      next[i]++

      if (!check(next)) continue

      maximal = false
      const key = next.join(',')

      if (!seen.has(key)) {
        seen.add(key)
        queue.push(next)
      }
    }

    const used = v.filter((c) => c > 0).length

    if (used === 1) {
      const i = v.findIndex((c) => c > 0)
      const next = [...v]
      next[i]++

      if (!check(next)) singles.set(i, v)
    } else if (used > 1 && maximal) {
      mixes.push(v)
    }
  }

  const volumes = list.map(volume)

  mixes.sort((a, b) => {
    const va = sum(a.map((c, i) => c * volumes[i]))
    const vb = sum(b.map((c, i) => c * volumes[i]))
    return vb - va || sum(b) - sum(a) || compareDesc(a, b)
  })

  const row = (v: number[]): ComboEntry[] =>
    v.flatMap((c, i) => (c > 0 ? [{ size: String(list[i].size), count: c }] : []))

  return {
    singles: Array.from(singles.keys())
      .sort((a, b) => a - b)
      .map((i) => row(singles.get(i) as number[])),
    mixes: mixes.slice(0, MIXES).map(row),
  }
}

/** combos() in words. See GiftKit::wording(). */
export function wording(found: Combos, labels: Record<string, string> = {}, or = ' ou ', plus = ' + '): Wording {
  const rows = [...found.singles, ...found.mixes]

  if (!rows.length) return { state: 'full', combos: '' }

  const text = rows.map((entries) => entries.map((entry) => `${entry.count} × ${labels[entry.size] ?? entry.size}`).join(plus)).join(or)
  const one = rows.length === 1 && sum(rows[0].map((entry) => entry.count)) === 1

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
