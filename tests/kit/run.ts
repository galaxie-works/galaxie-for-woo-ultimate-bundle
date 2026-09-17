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
