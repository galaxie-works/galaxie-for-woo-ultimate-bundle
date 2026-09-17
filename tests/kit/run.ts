/**
 * Gift kit wording tests for `frontend/src/lib/gift-kit.ts`:
 * `node tests/kit/run.ts` (Node ≥ 22.18 runs TypeScript directly; also
 * `pnpm run test:kit` from frontend/). Same fixtures as run.php, no test
 * framework, exits 1 on any failure.
 */

import { readFileSync } from 'node:fs'
import { isDeepStrictEqual } from 'node:util'

import { cleanName, combos, defaultName, fillText, wording } from '../../frontend/src/lib/gift-kit.ts'
import type { Combos, Wording } from '../../frontend/src/lib/gift-kit.ts'
import { fits } from '../../frontend/src/lib/gift-packing.ts'
import type { Box, Candle } from '../../frontend/src/lib/gift-packing.ts'

interface Fixtures {
  sizes: Record<string, Candle>
  boxes: Record<string, Box>
  labels: Record<string, string>
  combos: { name: string; box: string; candles: [string, number][]; sizes: string[]; expect: Combos; wording: Wording }[]
  wording: { name: string; combos: Combos; labels: Record<string, string>; expect: Wording }[]
  fill: { name: string; text: string; values: Record<string, string>; expect: string }[]
  clean_name: { name: string; name_in: string; expect: string }[]
  default_name: { name: string; used: string[]; expect: string }[]
  timing: { name: string; box: string; candles: [string, number][]; sizes: string[]; max_ms: number }[]
}

const fixtures = JSON.parse(readFileSync(new URL('./fixtures.json', import.meta.url), 'utf8')) as Fixtures
const { sizes, boxes } = fixtures
let passed = 0
let failed = 0

const expand = (pairs: [string, number][]): Candle[] => pairs.flatMap(([size, count]) => Array.from({ length: count }, () => sizes[size]))

function check(group: string, name: string, actual: unknown, expect: unknown): void {
  if (isDeepStrictEqual(actual, expect) && JSON.stringify(actual) === JSON.stringify(expect)) {
    passed++
    console.log(`  ok    ${group}: ${name}`)
    return
  }

  failed++
  console.log(`  FAIL  ${group}: ${name}\n        expected ${JSON.stringify(expect)}\n        got      ${JSON.stringify(actual)}`)
}

for (const c of fixtures.combos) {
  const found = combos(boxes[c.box], expand(c.candles), c.sizes.map((s) => sizes[s]))
  check('combos', c.name, found, c.expect)
  check('combos wording', c.name, wording(found, fixtures.labels), c.wording)
}

// Timing: answered within the bound, and what is listed is true.
for (const c of fixtures.timing) {
  const box = boxes[c.box]
  const inside = expand(c.candles)
  const started = performance.now()
  const found = combos(box, inside, c.sizes.map((s) => sizes[s]))
  const ms = performance.now() - started

  check('timing', `${c.name}: within ${c.max_ms} ms`, ms <= c.max_ms, true)
  console.log(`        ${Math.round(ms)} ms, ${found.singles.length} single(s), ${found.mixes.length} mix(es), ${found.complete ? 'complete' : 'partial'}`)

  const room = 12 - inside.length
  let truth = true

  for (const row of found.singles) {
    const size = sizes[row[0].size]
    const withN = (n: number): Candle[] => [...inside, ...Array.from({ length: n }, () => size)]
    truth = truth && row.length === 1 && fits(box, withN(row[0].count)) && (row[0].count + 1 > room || !fits(box, withN(row[0].count + 1)))
  }

  for (const row of found.mixes) {
    truth = truth && fits(box, [...inside, ...row.flatMap((entry) => Array.from({ length: entry.count }, () => sizes[entry.size]))])
  }

  check('timing', `${c.name}: every size listed is its exact most, every mix fits`, truth, true)
}

for (const c of fixtures.wording) {
  check('wording', c.name, wording(c.combos, c.labels), c.expect)
}

for (const c of fixtures.fill) {
  check('fill', c.name, fillText(c.text, c.values), c.expect)
}

for (const c of fixtures.clean_name) {
  check('clean_name', c.name, cleanName(c.name_in), c.expect)
}

for (const c of fixtures.default_name) {
  check('default_name', c.name, defaultName(c.used), c.expect)
}

console.log(`\n  ${passed} passed, ${failed} failed`)
process.exit(failed > 0 ? 1 : 0)
