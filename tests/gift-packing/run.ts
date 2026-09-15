/**
 * Gift packing tests for `frontend/src/lib/gift-packing.ts`:
 * `node tests/gift-packing/run.ts` (Node ≥ 22.18 runs TypeScript directly; also
 * `pnpm run test:gift-packing` from frontend/). Same fixtures as run.php, no
 * test framework, exits 1 on any failure.
 */

import { readFileSync } from 'node:fs'
import { isDeepStrictEqual } from 'node:util'

import { arrange, cover, fits, room, summary } from '../../frontend/src/lib/gift-packing.ts'
import type { Box, Candle, Gift, PackingOptions, SummaryRow } from '../../frontend/src/lib/gift-packing.ts'
import { arrangeAll, cardFor, cleanMessage, fill, maxQuantity, messageLength, total, validate } from '../../frontend/src/lib/gift-groups.ts'
import type { CardRow } from '../../frontend/src/lib/gift-groups.ts'
import type { PlanError, PlanGroup, PlanItem } from '../../frontend/src/lib/gift-groups.ts'

interface Fixtures {
  sizes: Record<string, Candle>
  boxes: Record<string, Box>
  fits: { name: string; box: string; candles: [string, number][]; options?: PackingOptions; expect: boolean }[]
  arrange: { name: string; candles: [string, number][]; boxes: string[]; options?: PackingOptions; expect: { box: string; candles: string[] }[] }[]
  room: { name: string; box: string; candles: [string, number][]; sizes: string[]; options?: PackingOptions; expect: string[] }[]
  summary: { name: string; box: string; sizes: string[]; options?: PackingOptions; expect: SummaryRow[] }[]
  cover: { name: string; candles: string[]; expect: Candle[]; box?: string; options?: PackingOptions; fits?: boolean }[]
  fill: { name: string; box: string; candles: [string, number][]; sizes: string[]; options?: PackingOptions; expect: number }[]
  max_quantity: { name: string; box: string; others: [string, number][]; candle: string; current: number; options?: PackingOptions; expect: number }[]
  validate: {
    name: string
    message_max: number
    stock: Record<string, number | null>
    in_cart: Record<string, number>
    groups: { candles: [string, number, string, boolean?][]; box: { id: string; added?: boolean } | null; items: PlanItem[] }[]
    options?: PackingOptions
    expect: PlanError[]
  }[]
  total: { name: string; lines: { price: number; quantity: number }[]; expect: number }[]
  card_for: { name: string; cards: CardRow[]; parent: number; box: Record<string, string> | null; expect: number }[]
  clean_message: { name: string; message: string; expect: string; length: number }[]
  arrange_all: { name: string; candles: [string, number][]; boxes: string[]; options?: PackingOptions; expect: { gifts: { box: string; candles: string[] }[]; loose: string[] } | null }[]
}

const fixtures = JSON.parse(readFileSync(new URL('./fixtures.json', import.meta.url), 'utf8')) as Fixtures
const { sizes, boxes } = fixtures
let passed = 0
let failed = 0

const expand = (pairs: [string, number][]): Candle[] => pairs.flatMap(([size, count]) => Array.from({ length: count }, () => sizes[size]))

// Key order matters for summary rows (PHP arrays keep insertion order, and so
// does JSON), so compare serialised as well as structurally.
function check(group: string, name: string, actual: unknown, expect: unknown): void {
  if (isDeepStrictEqual(actual, expect) && JSON.stringify(actual) === JSON.stringify(expect)) {
    passed++
    console.log(`  ok    ${group}: ${name}`)
    return
  }

  failed++
  console.log(`  FAIL  ${group}: ${name}\n        expected ${JSON.stringify(expect)}\n        got      ${JSON.stringify(actual)}`)
}

for (const c of fixtures.fits) {
  check('fits', c.name, fits(boxes[c.box], expand(c.candles), c.options ?? {}), c.expect)
}

const shape = (gifts: Gift[]) => gifts.map((gift) => ({ box: gift.box.id, candles: gift.candles.map((candle) => candle.size) }))

for (const c of fixtures.arrange) {
  const list = c.boxes.map((id) => boxes[id])
  const first = shape(arrange(expand(c.candles), list, c.options ?? {}))
  check('arrange', c.name, first, c.expect)
  check('arrange', `${c.name} (same again)`, shape(arrange(expand(c.candles), list, c.options ?? {})), first)
}

for (const c of fixtures.room) {
  check('room', c.name, room(boxes[c.box], expand(c.candles), c.sizes.map((s) => sizes[s]), c.options ?? {}), c.expect)
}

for (const c of fixtures.summary) {
  check('summary', c.name, summary(boxes[c.box], c.sizes.map((s) => sizes[s]), c.options ?? {}), c.expect)
}

for (const c of fixtures.cover) {
  const covered = cover(c.candles.map((s) => sizes[s]))
  check('cover', c.name, covered, c.expect)

  if (c.box !== undefined) check('cover', `${c.name} (fits ${c.box})`, fits(boxes[c.box], covered, c.options ?? {}), c.fits)
}

// gift-groups.ts: fill bars, stepper limits, plan validation, totals.
for (const c of fixtures.fill) {
  check('fill', c.name, fill(boxes[c.box], expand(c.candles), c.sizes.map((s) => sizes[s]), c.options ?? {}), c.expect)
}

for (const c of fixtures.max_quantity) {
  check('max_quantity', c.name, maxQuantity(boxes[c.box], expand(c.others), sizes[c.candle], c.current, c.options ?? {}), c.expect)
}

for (const c of fixtures.validate) {
  const groups: PlanGroup[] = c.groups.map((group) => ({
    candles: group.candles.flatMap(([size, count, id, added]) => Array.from({ length: count }, () => ({ ...sizes[size], id, added: added ?? true }))),
    box: group.box === null ? null : { ...boxes[group.box.id], added: group.box.added ?? true },
    items: group.items,
  }))

  check('validate', c.name, validate({ groups, stock: c.stock, in_cart: c.in_cart, message_max: c.message_max }, c.options ?? {}), c.expect)
}

for (const c of fixtures.total) {
  check('total', c.name, total(c.lines), c.expect)
}

for (const c of fixtures.arrange_all) {
  const result = arrangeAll(expand(c.candles), c.boxes.map((id) => boxes[id]), c.options ?? {})
  check('arrange_all', c.name, { gifts: shape(result.gifts), loose: result.loose.map((candle) => candle.size) }, c.expect)

  const sound = result.gifts.every((gift) => gift.candles.length <= 12 && fits(gift.box, gift.candles, c.options ?? {}))
  check('arrange_all', `${c.name} (every box fits)`, sound, true)
}

for (const c of fixtures.card_for) {
  check('card_for', c.name, cardFor(c.cards, c.parent, c.box), c.expect)
}

for (const c of fixtures.clean_message) {
  const clean = cleanMessage(c.message)
  check('clean_message', c.name, clean, c.expect)
  check('clean_message', `${c.name} (length)`, messageLength(clean), c.length)
}

console.log('\n  timing (best of 5):')
function time(label: string, run: () => unknown): void {
  let best = Infinity
  for (let i = 0; i < 5; i++) {
    const start = performance.now()
    run()
    best = Math.min(best, performance.now() - start)
  }
  console.log(`    ${label.padEnd(58)} ${best.toFixed(2).padStart(8)} ms`)
}

const up: PackingOptions = { orientation: 'upright', gap: 0.5 }
const lie: PackingOptions = { orientation: 'lying', gap: 0.5 }
const any: PackingOptions = { orientation: 'any', gap: 0.5 }
const mix6 = expand([['50g', 6], ['190g', 6]])
const store = [boxes.p11, boxes.b14, boxes.sq14]
const twelve = expand([['50g', 8], ['190g', 4]])

time('upright: fits 6 x 190g + 6 x 50g in 25 x 25 (no)', () => fits(boxes.b25, mix6, up))
time('upright: fits 6 x 190g + 6 x 50g in 30 x 30 (yes)', () => fits(boxes.b30, mix6, up))
time('upright: fits 12 x 50g in 21 x 21 (no)', () => fits(boxes.sq21, expand([['50g', 12]]), up))
time('upright: arrange 8 x 50g + 4 x 190g over 3 boxes', () => arrange(twelve, store, up))
time('upright: summary b30', () => summary(boxes.b30, [sizes['50g'], sizes['190g']], up))
time('lying: fits 6 x 190g + 6 x 50g in 30 x 30', () => fits(boxes.b30, mix6, lie))
time('lying: fits 6 x 190g + 6 x 50g in 25 x 25', () => fits(boxes.b25, mix6, lie))
time('lying, gift dims, gap 0: summary 24.44 x 8.94 x 7.94', () => summary(boxes['real-long'], [sizes['50g-gift'], sizes['190g-gift']], { orientation: 'lying', gap: 0 }))
time('any: fits 6 x 190g + 6 x 50g in 30 x 30', () => fits(boxes.b30, mix6, any))
time('any: fits 6 x 190g + 6 x 50g in 25 x 25', () => fits(boxes.b25, mix6, any))
time('any: fits 12 x 50g in 21 x 21', () => fits(boxes.sq21, expand([['50g', 12]]), any))
time('any: arrange 8 x 50g + 4 x 190g over 3 boxes', () => arrange(twelve, store, any))
time('any: summary b30', () => summary(boxes.b30, [sizes['50g'], sizes['190g']], any))

console.log(`\n  ${passed} passed, ${failed} failed`)
process.exit(failed > 0 ? 1 : 0)
