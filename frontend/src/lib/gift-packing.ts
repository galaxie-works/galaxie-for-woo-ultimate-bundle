/**
 * Which candles fit in which gift box — the TypeScript twin of
 * `src/Support/GiftPacking.php`, for the builder popup.
 *
 * Same model, same search order, same tie-breaks, so the popup and the server
 * never disagree about a box. Both are held to `tests/gift-packing/fixtures.json`
 * (`php tests/gift-packing/run.php`, `node tests/gift-packing/run.ts`): change
 * one, change the other, run both.
 *
 * - `orientation` (default 'lying'): 'upright' uses L × W of floor and H of
 *   height; 'lying' puts the jar on its side, H × max(L, W) of floor and
 *   max(L, W) of height; 'any' lets each candle take whichever lets the set fit;
 *   'faces' (a box-shaped item) lets any of its three faces go down.
 *   The gap is added to both floor sides and to the height used, which must be
 *   ≤ the box height. One layer unless `stacking`: then identical items may
 *   also stand in columns — see GiftPacking.php.
 * - Footprints turn 90° on the floor if that helps.
 * - The floor search is exact over "normal pattern" corners in bottom-left
 *   order, with remembered failures and a conservative-scale bound. Past
 *   STEP_LIMIT units of work the first pass gives up and a second runs with every
 *   footprint turned the other way round for RETRY_LIMIT more; a search that
 *   still has not finished is `null` from `fitsKnown()` — "not known", never
 *   "does not fit". See GiftPacking.php.
 * - Sizes are compared in hundredths of a cm and prices in cents.
 *
 * Plain functions over plain data: no DOM, no imports, erasable TypeScript only
 * (Node runs the test straight from source).
 */

export interface Candle {
  size: string
  length: number
  width: number
  height: number
  price?: number
}

export interface Box {
  id: string | number
  length: number
  width: number
  height: number
  /** Most candles the box takes; 0 or missing = no limit. */
  max?: number
  /** Extra height (cm, 0–2) the lid still closes over: usable height = height + overflow. */
  overflow?: number
  price: number
}

/** How a candle sits in the box. */
export type Orientation = 'upright' | 'lying' | 'any' | 'faces'

export interface PackingOptions {
  /** Paper filling around each candle, cm. Default 0 (tissue paper fills the gaps). */
  gap?: number
  /** Default 'lying' (the jar on its side). Anything else unknown counts as 'lying'. */
  orientation?: Orientation
  /** Identical items may stand one on another, in columns. Default off. */
  stacking?: boolean
}

export interface Gift<C extends Candle = Candle, B extends Box = Box> {
  box: B
  candles: C[]
}

/** Search work (states entered + corners tried) the first pass gets. */
export const STEP_LIMIT = 300000

/** Work the second pass gets, with every footprint turned the other way round. */
export const RETRY_LIMIT = 75000

/** Most ways of standing a kit's items in columns tried per question. See GiftPacking::STACK_COMBOS. */
export const STACK_COMBOS = 81

/** Failed search states remembered per `fits()` call. */
export const MEMO_LIMIT = 50000

/** Search steps after which the conservative-scale bound is tried once. */
export const BOUND_AT = 1000

/** Largest group `summary()` considers at once. */
export const MAX_ITEMS = 12

interface CandleType {
  /** Ways it can take the floor, each [short, long] in units, sorted. */
  shapes: [number, number][]
  /** Smallest footprint among the shapes. */
  area: number
  key: string
  /** Still to place. */
  count: number
  /** In the box altogether. */
  total: number
}

interface Search {
  bx: number
  by: number
  types: CandleType[]
  xs: number[]
  ys: number[]
  placed: [number, number, number, number][]
  nodes: number
  steps: number
  dead: Set<string>
  /** Past the pass's cap, or `proved`: unwind without searching further. */
  stop: boolean
  /** The conservative-scale bound showed the candles cannot fit. */
  proved: boolean
  /** Corners tried, for the clock. */
  clock: number
  /** Work this pass may do. */
  cap: number
  /** Second pass: footprints laid long side along the box's length first. */
  wide: boolean
}

/** performance.now() past which fits() gives up; null for no clock (the default). */
let deadline: number | null = null
let expiredFlag = false

function now(): number {
  return typeof performance !== 'undefined' && typeof performance.now === 'function' ? performance.now() : Date.now()
}

function late(): boolean {
  if (deadline === null) return false
  if (expiredFlag || now() > deadline) {
    expiredFlag = true
    return true
  }
  return false
}

/**
 * Runs `run` with a wall-clock limit on every fits() inside it; a fits() that
 * runs out answers "does not fit". `expired` says whether any did. Twin of
 * GiftPacking::with_deadline().
 */
export function withDeadline<T>(ms: number, run: () => T): { value: T; expired: boolean } {
  const previous = deadline
  const was = expiredFlag
  const mine = now() + Math.max(0, ms)

  deadline = previous === null ? mine : Math.min(previous, mine)
  expiredFlag = false

  let inner = false
  try {
    const value = run()
    inner = expiredFlag
    return { value, expired: inner }
  } finally {
    inner = inner || expiredFlag
    deadline = previous
    expiredFlag = was || (previous !== null && inner)
  }
}

/**
 * Whether all these candles go in the box together; an unfinished search reads
 * as "no". Only for the places where "not known" and "no" may be answered
 * alike — never where a shopper is refused; those ask `fitsKnown()`.
 */
export function fits(box: Box, candles: Candle[], options: PackingOptions = {}): boolean {
  return fitsKnown(box, candles, options) === true
}

/**
 * Whether all these candles go in the box together: true, false, or null when
 * the search ran out of work or of clock before it could tell. Null is not a
 * refusal. See GiftPacking::fits_known().
 */
export function fitsKnown(box: Box, candles: Candle[], options: PackingOptions = {}): boolean | null {
  // Round against the fit: candles and gap up, the box down, so a converted
  // 5.004 cm candle never slips into a 5.00 cm box.
  const gap = up(options.gap ?? 0)
  const bx = down(box.length)
  const by = down(box.width)
  let bz = down(box.height)
  const max = Math.max(0, Math.trunc(Number(box.max ?? 0)) || 0)
  const n = candles.length

  if (n === 0) return true

  if ((max > 0 && n > max) || bx <= 0 || by <= 0 || bz <= 0) return false

  if (late()) return null

  // Candles may stand a little proud of the base when the lid still closes over
  // them: usable height = height + overflow (the gap still applies).
  bz += down(box.overflow ?? 0)

  const orientation = orientationOf(options)
  const pieces: [number, number][][] = []

  for (const candle of candles) {
    const shapes = shapesOf(candle, orientation, gap, bx, by, bz)

    if (shapes.length === 0) return false

    pieces.push(shapes)
  }

  const answer = floorFits(pieces, bx, by)

  if (answer === true || !options.stacking) return answer

  // Stacking: the columns' yes is a yes, a search that could not finish is
  // "not known", and a finished no leaves the floor's answer. See
  // GiftPacking::fits_known().
  const stacked = stacksFit(candles, orientation, gap, bx, by, bz)

  if (stacked === true) return true

  return stacked === null ? null : answer
}

/** The exact one-layer search. See GiftPacking::floor_fits(). */
function floorFits(pieces: [number, number][][], bx: number, by: number): boolean | null {
  const n = pieces.length

  // Pieces with the same possible footprints are one type with a count: the
  // search then never tries swapping two of them.
  const byKey = new Map<string, CandleType>()
  let area = 0

  for (const shapes of pieces) {
    const key = shapes.map(([w, d]) => `${w}x${d}`).join(';')
    let type = byKey.get(key)

    if (!type) {
      type = { shapes, area: Math.min(...shapes.map(([w, d]) => w * d)), key, count: 0, total: 0 }
      byKey.set(key, type)
    }

    type.count++
    type.total++
    area += type.area
  }

  if (area > bx * by) return false

  // Largest footprint first: it is the one with fewest places to go.
  const types = [...byKey.values()].sort((p, q) => cmp(q.area, p.area) || cmp(p.shapes[0][0], q.shapes[0][0]) || byString(p.key, q.key))

  // A pushed-into-the-corner layout never reaches past the largest sum of sides
  // that fits, so the box can shrink to it.
  const xs = normal(types, bx)
  const ys = normal(types, by)
  const sx = xs[xs.length - 1]
  const sy = ys[ys.length - 1]

  if (area > sx * sy) return false

  const search: Search = {
    bx: sx,
    by: sy,
    types,
    xs: corners(xs, types, sx),
    ys: corners(ys, types, sy),
    placed: [],
    nodes: 0,
    steps: 0,
    dead: new Set<string>(),
    stop: false,
    proved: false,
    clock: 0,
    cap: STEP_LIMIT,
    wide: false,
  }

  if (place(search, -1, n, area)) return true

  if (!search.stop || search.proved) return false

  // The first pass lays every footprint short side along the box's length; a
  // shelf of elongated footprints wants the long side there instead, and the
  // search walks past it for hundreds of thousands of steps. See
  // GiftPacking::fits_known() for the measurement.
  if (late()) return null

  search.placed = []
  search.dead = new Set<string>()
  search.nodes = 0
  search.steps = 0
  search.clock = 0
  search.stop = false
  search.cap = RETRY_LIMIT
  search.wide = true

  if (place(search, -1, n, area)) return true

  return search.stop && !search.proved ? null : false
}

/**
 * Shares the candles out over as few boxes as possible, then the cheapest.
 * Any box can be used more than once. Empty when some candle fits in no box.
 * Ties go to the earlier box, then to a fixed split order shared with PHP;
 * candles keep their input order inside each type.
 */
export function arrange<C extends Candle, B extends Box>(candles: C[], boxes: B[], options: PackingOptions = {}): Gift<C, B>[] {
  if (candles.length === 0 || boxes.length === 0) return []

  const types: C[] = []
  const pool = new Map<string, C[]>()

  for (const candle of candles) {
    const key = candleKey(candle)
    let list = pool.get(key)

    if (!list) {
      types.push(candle)
      list = []
      pool.set(key, list)
    }

    list.push(candle)
  }

  const lists = [...pool.values()]
  const counts = lists.map((list) => list.length)
  const k = counts.length

  let total = 1
  for (const c of counts) total *= c + 1

  const decode = (index: number): number[] => {
    const v: number[] = []
    for (let i = 0; i < k; i++) {
      v.push(index % (counts[i] + 1))
      index = Math.floor(index / (counts[i] + 1))
    }
    return v
  }

  // Cheapest single box for each group (null = none holds it).
  const single: (number | null)[] = [null]
  for (let u = 1; u < total; u++) {
    const group: Candle[] = []
    decode(u).forEach((c, i) => {
      for (let j = 0; j < c; j++) group.push(types[i])
    })

    let chosen: number | null = null
    boxes.forEach((box, b) => {
      if (chosen !== null && cents(boxes[chosen].price) <= cents(box.price)) return
      if (fits(box, group, options)) chosen = b
    })
    single[u] = chosen
  }

  // best[v] = [boxes, cents, first group, box index] over all ways to split v.
  const best: ([number, number, number, number] | null)[] = [[0, 0, 0, -1]]
  for (let v = 1; v < total; v++) {
    const vec = decode(v)

    // The first type still present must be in the first group: splits that only
    // differ in group order are then tried once.
    let lead = 0
    while (vec[lead] === 0) lead++

    best[v] = null
    for (let u = v; u >= 1; u--) {
      const sub = decode(u)
      let ok = sub[lead] > 0

      for (let i = 0; ok && i < k; i++) ok = sub[i] <= vec[i]

      const box = single[u]
      const rest = best[v - u]

      if (!ok || box === null || rest === null) continue

      const count = rest[0] + 1
      const price = rest[1] + cents(boxes[box].price)
      const current = best[v]

      if (current === null || count < current[0] || (count === current[0] && price < current[1])) {
        best[v] = [count, price, u, box]
      }
    }
  }

  if (best[total - 1] === null) return []

  const gifts: Gift<C, B>[] = []
  const taken = new Array<number>(k).fill(0)
  let v = total - 1
  while (v > 0) {
    const step = best[v] as [number, number, number, number]
    const group: C[] = []

    decode(step[2]).forEach((c, i) => {
      for (let j = 0; j < c; j++) group.push(lists[i][taken[i]++])
    })

    gifts.push({ box: boxes[step[3]], candles: group })
    v -= step[2]
  }

  return gifts
}

/**
 * The sizes that still fit if one more is added: "cabe mais 1 × 50g".
 * `sizes` is one candle per size to try (default: the sizes already inside).
 */
export function room(box: Box, candles: Candle[], sizes: Candle[] = [], options: PackingOptions = {}): string[] {
  const fit: string[] = []

  for (const size of distinct(sizes.length ? sizes : candles)) {
    if (fits(box, [...candles, size], options) && !fit.includes(String(size.size))) {
      fit.push(String(size.size))
    }
  }

  return fit
}

export interface SummaryRow {
  /** Size key → count. */
  counts: Record<string, number>
  /** Reached MAX_ITEMS: the box may hold more than this. */
  capped: boolean
}

/**
 * Representative fits for a box: the most of each size alone, then every mix
 * that cannot take one more of anything. For 14 × 11 × 9.5 and the Eir sizes the
 * counts are `{ '50g': 4 }`, `{ '190g': 1 }`, `{ '50g': 2, '190g': 1 }`.
 * A row that reaches MAX_ITEMS is `capped` (unless the box `max` is a real limit
 * at or below it) — the same rule as GiftPacking::summary().
 */
export function summary(box: Box, sizes: Candle[], options: PackingOptions = {}): SummaryRow[] {
  const list = distinct(sizes)
  const k = list.length
  const memo = new Map<string, boolean>()

  const check = (v: number[]): boolean => {
    const key = v.join(',')
    let result = memo.get(key)

    if (result === undefined) {
      const group: Candle[] = []
      v.forEach((c, i) => {
        for (let j = 0; j < c; j++) group.push(list[i])
      })
      result = sum(v) <= MAX_ITEMS && fits(box, group, options)
      memo.set(key, result)
    }

    return result
  }

  const pure: number[][] = []
  const mixes: number[][] = []

  // Every vector that fits, smallest first; fitting is monotone, so each is
  // grown from one that did.
  const queue: number[][] = [new Array<number>(k).fill(0)]
  const seen = new Set<string>([queue[0].join(',')])
  for (let q = 0; q < queue.length; q++) {
    const v = queue[q]
    let maximal = sum(v) > 0

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

      if (!check(next)) pure[i] = v
    } else if (used > 1 && maximal) {
      mixes.push(v)
    }
  }

  const max = Math.max(0, Math.trunc(Number(box.max ?? 0)) || 0)

  return [...pure.filter(Boolean), ...mixes].map((v) => {
    const counts: Record<string, number> = {}
    v.forEach((c, i) => {
      if (c > 0) counts[String(list[i].size)] = c
    })
    return { counts, capped: sum(v) >= MAX_ITEMS && (max === 0 || max > MAX_ITEMS) }
  })
}

/**
 * One candle per size, the smallest of it the store actually sells: least
 * volume, the first seen on a tie. See GiftPacking::offered() for why a shape
 * covering every variation was the wrong jar to describe a size by.
 */
export function offered<C extends Candle>(candles: C[]): C[] {
  const out = new Map<string, C>()

  for (const candle of candles) {
    const key = String(candle.size ?? '')
    const seen = out.get(key)

    if (!seen || bulk(candle) < bulk(seen)) out.set(key, candle)
  }

  return [...out.values()]
}

/** A candle's volume in hundredths of a cm cubed; the rounding gift-groups.ts uses. */
function bulk(candle: Candle): number {
  const units = (cm: number): number => (Number(cm) > 0 ? Math.floor(Number(cm) * 100 + 0.5) : 0)

  return units(candle.length) * units(candle.width) * units(candle.height)
}

function place(s: Search, last: number, left: number, area: number): boolean {
  if (left === 0) return true

  if (s.stop) return false

  s.nodes++

  if (++s.steps > s.cap) {
    s.stop = true
    return false
  }

  // Long search: check once whether the candles can fit at all.
  if (s.nodes === BOUND_AT && bound(s)) {
    s.stop = true
    s.proved = true
    return false
  }

  const cols = s.xs.length
  const cells = cols * s.ys.length

  // Corners inside a placed candle can take nothing: start at the first free one.
  let first = last + 1
  while (first < cells) {
    s.steps++
    if (!covered(s.placed, s.xs[first % cols], s.ys[Math.floor(first / cols)])) break
    first++
  }

  if (first >= cells) return false

  // What is left to decide depends only on that corner, the candles still to
  // place and the tops of the ones that reach its row — not on how the rows below
  // were filled. A state that failed once fails again.
  const floor = s.ys[Math.floor(first / cols)]
  const tops: string[] = []
  for (const r of s.placed) {
    if (r[1] + r[3] > floor) tops.push(`${r[0]}:${r[2]}:${r[1] + r[3]}`)
  }
  tops.sort(byString)

  const key = `${first}|${s.types.map((type) => type.count).join(',')}|${tops.join(',')}`

  if (s.dead.has(key)) return false

  // The state's own copies: the corner loop reads them thousands of times.
  const placed = s.placed
  const xs = s.xs
  const ys = s.ys
  const bx = s.bx
  const by = s.by
  const cap = s.cap

  // Set once per row of corners (see the free-floor bound below).
  let at = -1
  let y = 0
  let top = 0
  let deep = 0
  let above = 0
  let band: [number, number, number][] = []

  for (let idx = first; idx < cells; idx++) {
    // Work, not just states, is what the limit counts: a fine grid of corners
    // makes each state expensive.
    if (++s.steps > cap) {
      s.stop = true
      return false
    }

    // The clock, every 1024 corners, when withDeadline() set one.
    if (deadline !== null && (++s.clock & 1023) === 0 && late()) {
      s.stop = true
      return false
    }

    const row = Math.floor(idx / cols)

    // Everything left sits at or above this row, and not left of this corner
    // within it. If that much floor, less what is taken, is smaller than the
    // candles left, no later corner helps either. Only the part left of the
    // corner moves along a row, so the rest is worked out once when the row
    // changes.
    if (row !== at) {
      at = row
      y = ys[row]
      top = row + 1 < ys.length ? ys[row + 1] : by
      deep = top - y
      above = bx * (by - y)
      band = []

      for (const r of placed) {
        const base = r[1] > y ? r[1] : y
        const rise = r[1] + r[3]

        if (rise > base) above -= r[2] * (rise - base)

        const cut = rise < top ? rise : top

        if (cut > base) band.push([r[0], r[0] + r[2], cut - base])
      }
    }

    const x = xs[idx % cols]
    let free = above - x * deep

    for (const r of band) {
      if (r[0] < x) free += ((r[1] < x ? r[1] : x) - r[0]) * r[2]
    }

    if (area > free) break

    for (const type of s.types) {
      if (type.count === 0) continue

      for (const shape of type.shapes) {
        const turns: [number, number][] =
          shape[0] === shape[1] ? [[shape[0], shape[1]]] : s.wide ? [[shape[1], shape[0]], [shape[0], shape[1]]] : [[shape[0], shape[1]], [shape[1], shape[0]]]

        for (const [w, d] of turns) {
          if (x + w > s.bx || y + d > s.by) continue

          let clear = true
          for (const r of placed) {
            if (x < r[0] + r[2] && r[0] < x + w && y < r[1] + r[3] && r[1] < y + d) {
              clear = false
              break
            }
          }

          if (!clear) continue

          s.placed.push([x, y, w, d])
          type.count--

          // `area` counts each candle left at its smallest shape: a bound, not a sum of placements.
          const done = place(s, idx, left - 1, area - type.area)

          type.count++
          s.placed.pop()

          if (done) return true

          if (s.stop) return false
        }
      }
    }
  }

  if (!s.stop && s.dead.size < MEMO_LIMIT) s.dead.add(key)

  return false
}

/** Whether a corner lies inside a placed candle. */
function covered(placed: Search['placed'], x: number, y: number): boolean {
  for (const r of placed) {
    if (r[0] <= x && x < r[0] + r[2] && r[1] <= y && y < r[1] + r[3]) return true
  }
  return false
}

/**
 * Proves "does not fit" for layouts the area alone lets through, with
 * conservative scales (Fekete & Schepers): small integer weights per side
 * length, divided by the most weight any line of candles across the box can
 * carry, tried for every combination when there are at most three distinct
 * side lengths. See GiftPacking::bound() for the worked example.
 */
function bound(s: Search): boolean {
  // How many candles could show each side length along a line.
  const bySide = new Map<number, number>()
  for (const type of s.types) {
    for (const side of sidesOf(type)) bySide.set(side, (bySide.get(side) ?? 0) + type.total)
  }

  const lengths = [...bySide.keys()].sort((a, b) => a - b)
  const m = lengths.length

  if (m > 3) return false

  const avail = lengths.map((length) => bySide.get(length) as number)
  const top = m === 1 ? 1 : m === 2 ? 6 : 3
  const index = new Map(lengths.map((length, j) => [length, j]))

  const scales = (cap: number): [number[], number][] => {
    const out: [number[], number][] = []
    for (let code = 1; code < (top + 1) ** m; code++) {
      const v: number[] = []
      for (let j = 0, c = code; j < m; j++, c = Math.floor(c / (top + 1))) v.push(c % (top + 1))

      // Heaviest line: every count of the first sides, the last one filled up.
      let most = 0
      const walk = (j: number, room: number, weight: number): void => {
        if (j === m - 1) {
          most = Math.max(most, weight + v[j] * Math.min(avail[j], Math.floor(room / lengths[j])))
          return
        }
        for (let n = 0; n <= avail[j] && n * lengths[j] <= room; n++) walk(j + 1, room - n * lengths[j], weight + n * v[j])
      }
      walk(0, cap, 0)

      if (most > 0) out.push([v, most])
    }
    return out
  }

  const along = scales(s.bx)
  const across = scales(s.by)

  for (const fx of along) {
    for (const fy of across) {
      let total = 0
      for (const type of s.types) {
        // Each candle takes the cheapest of its shapes and turns.
        let least = Number.MAX_SAFE_INTEGER
        for (const [a, b] of type.shapes) {
          const w = index.get(a) as number
          const d = index.get(b) as number
          least = Math.min(least, fx[0][w] * fy[0][d], fx[0][d] * fy[0][w])
        }
        total += type.total * least
      }

      if (total > fx[1] * fy[1]) return true
    }
  }

  return false
}

/** Every sum of footprint sides up to the box side: the only coordinates and right edges a pushed-into-the-corner layout can have. */
function normal(types: CandleType[], limit: number): number[] {
  let sums = new Set<number>([0])

  // Each candle adds any side of any of its shapes, or nothing.
  for (const type of types) {
    const steps = sidesOf(type)
    for (let c = 0; c < type.count; c++) {
      const next = new Set(sums)
      for (const total of sums) {
        for (const step of steps) {
          if (total + step <= limit) next.add(total + step)
        }
      }
      sums = next
    }
  }

  return [...sums].sort((a, b) => a - b)
}

/** The sums a candle's corner can sit on: room left for the narrowest side. */
function corners(sums: number[], types: CandleType[], side: number): number[] {
  let narrow = Number.MAX_SAFE_INTEGER
  for (const type of types) {
    for (const shape of type.shapes) narrow = Math.min(narrow, shape[0])
  }

  return sums.filter((total) => total + narrow <= side)
}

/** Distinct side lengths across a type's shapes, ascending. */
function sidesOf(type: CandleType): number[] {
  const sides: number[] = []
  for (const shape of type.shapes) {
    for (const side of shape) {
      if (!sides.includes(side)) sides.push(side)
    }
  }
  return sides.sort((a, b) => a - b)
}

function orientationOf(options: PackingOptions): Orientation {
  const o = options.orientation
  return o === 'upright' || o === 'any' || o === 'faces' ? o : 'lying'
}

/**
 * The ways an item can go into this box, gap included: [short, long, height
 * used], those too tall or too big for the floor dropped, sorted.
 */
function waysOf(candle: Candle, orientation: Orientation, gap: number, bx: number, by: number, bz: number): [number, number, number][] {
  const l = up(candle.length)
  const w = up(candle.width)
  const h = up(candle.height)

  if (l <= 0 || w <= 0 || h <= 0) return []

  const across = Math.max(l, w)
  const ways: [number, number, number][] = []

  // 'faces': a box-shaped item, any of its three faces down (the PHP twin says why).
  if (orientation === 'faces') {
    ways.push([l + gap, w + gap, h + gap], [l + gap, h + gap, w + gap], [w + gap, h + gap, l + gap])
  } else {
    if (orientation !== 'lying') ways.push([l + gap, w + gap, h + gap])
    if (orientation !== 'upright') ways.push([h + gap, across + gap, across + gap])
  }

  const out: [number, number, number][] = []
  for (const [a, b, z] of ways) {
    const short = Math.min(a, b)
    const long = Math.max(a, b)

    if (z > bz || !((short <= bx && long <= by) || (long <= bx && short <= by))) continue
    if (!out.some(([p, q, r]) => p === short && q === long && r === z)) out.push([short, long, z])
  }

  return out.sort((p, q) => cmp(p[0], q[0]) || cmp(p[1], q[1]) || cmp(p[2], q[2]))
}

/** The floor footprints an item may take, [short, long], sorted: its ways without the height. */
function shapesOf(candle: Candle, orientation: Orientation, gap: number, bx: number, by: number, bz: number): [number, number][] {
  const shapes: [number, number][] = []
  for (const [a, b] of waysOf(candle, orientation, gap, bx, by, bz)) {
    if (!shapes.some(([p, q]) => p === a && q === b)) shapes.push([a, b])
  }
  return shapes
}

/**
 * Whether the items fit when identical ones may stand in columns; null when
 * the search ran out of ways, work or time. Only identical items stack: it can
 * miss a fit, never invent one. See GiftPacking::stacks_fit().
 */
function stacksFit(candles: Candle[], orientation: Orientation, gap: number, bx: number, by: number, bz: number): boolean | null {
  const groups = new Map<string, { candle: Candle; n: number }>()

  for (const candle of candles) {
    const key = `${up(candle.length)}x${up(candle.width)}x${up(candle.height)}`
    const group = groups.get(key)
    if (group) group.n++
    else groups.set(key, { candle, n: 1 })
  }

  const keys = [...groups.keys()].sort(byString)

  // Each option: [footprint, columns needed, whether it stacks at all].
  let combos: [[number, number], number, boolean][][] = [[]]
  for (const key of keys) {
    const group = groups.get(key)!
    const options: [[number, number], number, boolean][] = []
    for (const [a, b, z] of waysOf(group.candle, orientation, gap, bx, by, bz)) {
      const high = Math.max(1, Math.floor(bz / z))
      options.push([[a, b], Math.ceil(group.n / high), high > 1])
    }

    const next: [[number, number], number, boolean][][] = []
    for (const combo of combos) {
      for (const option of options) next.push([...combo, option])
    }

    combos = next

    // Too many ways to try: nothing was ruled out, so nothing is known.
    if (combos.length > STACK_COMBOS) return null
  }

  let unknown = false

  for (const combo of combos) {
    if (!combo.some((option) => option[2])) continue

    if (late()) return null

    const pieces: [number, number][][] = []
    for (const [shape, count] of combo) {
      for (let i = 0; i < count; i++) pieces.push([shape])
    }

    const answer = floorFits(pieces, bx, by)

    if (answer === true) return true

    unknown = unknown || answer === null
  }

  return unknown ? null : false
}

/** Byte order, like PHP's SORT_STRING (keys are ASCII). */
function byString(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0
}

function distinct<C extends Candle>(candles: C[]): C[] {
  const out = new Map<string, C>()
  for (const candle of candles) {
    const key = candleKey(candle)
    if (!out.has(key)) out.set(key, candle)
  }
  return [...out.values()]
}

/** Rounded up like fits() rounds candles, so 5.000 and 5.004 cm stay apart (arrange() tests a group with its first candle). */
function candleKey(candle: Candle): string {
  return `${candle.size ?? ''}|${up(candle.length)}|${up(candle.width)}|${up(candle.height)}`
}

/** Hundredths of a cm, rounded up (candles, gap); 1e-6 absorbs float noise. Same as PHP up(). */
function up(cm: unknown): number {
  const value = Number(cm)
  return Number.isFinite(value) && value > 0 ? Math.ceil(value * 100 - 1e-6) : 0
}

/** Hundredths of a cm, rounded down (box sides). Same as PHP down(). */
function down(cm: unknown): number {
  const value = Number(cm)
  return Number.isFinite(value) && value > 0 ? Math.floor(value * 100 + 1e-6) : 0
}

function cents(price: unknown): number {
  const value = Number(price)
  return Number.isFinite(value) && value > 0 ? Math.floor(value * 100 + 0.5) : 0
}

function cmp(a: number, b: number): number {
  return a < b ? -1 : a > b ? 1 : 0
}

function sum(v: number[]): number {
  return v.reduce((a, b) => a + b, 0)
}
