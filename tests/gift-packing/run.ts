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

interface Fixtures {
  sizes: Record<string, Candle>
  boxes: Record<string, Box>
  fits: { name: string; box: string; candles: [string, number][]; options?: PackingOptions; expect: boolean }[]
  arrange: { name: string; candles: [string, number][]; boxes: string[]; expect: { box: string; candles: string[] }[] }[]
  room: { name: string; box: string; candles: [string, number][]; sizes: string[]; expect: string[] }[]
  summary: { name: string; box: string; sizes: string[]; expect: SummaryRow[] }[]
  cover: { name: string; candles: string[]; expect: Candle[]; box?: string; fits?: boolean }[]
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
  const first = shape(arrange(expand(c.candles), list))
  check('arrange', c.name, first, c.expect)
  check('arrange', `${c.name} (same again)`, shape(arrange(expand(c.candles), list)), first)
}

for (const c of fixtures.room) {
  check('room', c.name, room(boxes[c.box], expand(c.candles), c.sizes.map((s) => sizes[s])), c.expect)
}

for (const c of fixtures.summary) {
  check('summary', c.name, summary(boxes[c.box], c.sizes.map((s) => sizes[s])), c.expect)
}

for (const c of fixtures.cover) {
  const covered = cover(c.candles.map((s) => sizes[s]))
  check('cover', c.name, covered, c.expect)

  if (c.box !== undefined) check('cover', `${c.name} (fits ${c.box})`, fits(boxes[c.box], covered), c.fits)
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

time('fits 6 x 190g + 6 x 50g in 25 x 25 (no)', () => fits(boxes.b25, expand([['50g', 6], ['190g', 6]])))
time('fits 6 x 190g + 6 x 50g in 30 x 30 (yes)', () => fits(boxes.b30, expand([['190g', 6], ['50g', 6]])))
time('fits 12 x 50g in 21 x 21 (no)', () => fits(boxes.sq21, expand([['50g', 12]])))
time('arrange 8 x 50g + 4 x 190g over 3 boxes', () => arrange(expand([['50g', 8], ['190g', 4]]), [boxes.p11, boxes.b14, boxes.sq14]))
time('summary b30', () => summary(boxes.b30, [sizes['50g'], sizes['190g']]))

console.log(`\n  ${passed} passed, ${failed} failed`)
process.exit(failed > 0 ? 1 : 0)
