/**
 * Bridge between the PHP side and the React islands.
 *
 * Islands are mounted onto `<div data-galaxie-island="name" data-galaxie-props='{...}'>`
 * nodes rendered by the Elementor widgets. Per-request config (ajax url, nonce)
 * is passed the same way, in the props JSON — no global `wp_localize_script`
 * object to collide with.
 */

export interface AjaxResult<T = unknown> {
  success: boolean
  data?: T & { message?: string }
}

interface AjaxEndpoint {
  ajaxUrl: string
  nonce: string
}

/** PasswordlessAuth's boot data. */
export interface AuthConfig extends AjaxEndpoint {
  /** 'password' when sign-in by code is switched off (wp-admin → Galaxie → Login). */
  mode?: 'otp' | 'password'
  lostPasswordUrl?: string
}

/** LegalPages' boot data: the pages set on wp-admin → Galaxie → Páginas legais. */
export interface LegalPage {
  url: string
  title: string
}

/** AgeGate's boot data (wp-admin → Galaxie → Idade mínima); minAge 0 when the module is off. */
export interface AgeGateConfig {
  minAge: number
  maxDate: string
}

/** OrderCancellation's boot data: the reasons set on wp-admin → Galaxie → Cancelamento. */
export interface OrderCancellationConfig {
  ajaxUrl: string
  /** The in-transit notice's text for a screen whose widget has no dialog of its own. */
  posted: string
  reasons: string[]
  question: string
  choose: string
  comment: string
  required: string
}

export interface GalaxieWooConfig {
  auth?: AuthConfig
  orderCancellation?: OrderCancellationConfig
  ageGate?: AgeGateConfig
  legal?: Partial<Record<'terms' | 'privacy' | 'returns', LegalPage>>
  checkout?: AjaxEndpoint
  myAccount?: AjaxEndpoint
  accountDeletion?: AjaxEndpoint
  toastNotices?: boolean
}

/** Reads the boot config every enabled module contributes (see PHP Core\Plugin::print_boot_data). */
export function getGalaxieConfig(): GalaxieWooConfig {
  return (window as unknown as { __GALAXIE_WOO__?: GalaxieWooConfig }).__GALAXIE_WOO__ ?? {}
}

/** Parse the JSON props a mount node carries. Never throws. */
export function readProps<T = Record<string, unknown>>(el: Element): T {
  const raw = el.getAttribute('data-galaxie-props')
  if (!raw) {
    return {} as T
  }
  try {
    return JSON.parse(raw) as T
  } catch {
    return {} as T
  }
}

/* ------------------------------------------------------------------ nonces
 *
 * Product and home pages are served by LiteSpeed and the CDN for days, and
 * window.__GALAXIE_WOO__ is printed into them, nonces included. A WordPress
 * nonce is good for 12 to 24 hours, so on a page cached longer than that every
 * request it makes is refused: `check_ajax_referer()` answers a bare `-1`
 * (HTTP 403), our modules' own checks a JSON error with 403.
 *
 * The store hands out fresh ones from an endpoint no cache keeps
 * (PHP Core\FreshNonces, `galaxie_fresh_nonces`: same admin-ajax, same
 * cookies as the requests that verify them). They are written INTO the config
 * objects already on the page, in place, so whoever holds one of those objects
 * and reads `config.nonce` at send time gets the new value without being told.
 *
 * When: once, as soon as the page runs, if its boot data is older than
 * STALE_AFTER_S (the page came out of a cache); otherwise only when a request
 * is refused, and then that request is sent once more with the fresh nonce.
 */

/** Older than this, a cached page's nonces may have lapsed (they last 12 to 24 h). */
const STALE_AFTER_S = 10 * 3600

const REFRESH_ACTION = 'galaxie_fresh_nonces'

const OFFLINE = 'Não foi possível falar com a loja. Verifique sua conexão e tente de novo.'

type Tree = Record<string, unknown>

interface RefreshState {
  /** The refresh in flight, shared by every bundle on the page (main and kit). */
  pending?: Promise<boolean>
}

function bootRoot(): Tree {
  const w = window as unknown as { __GALAXIE_WOO__?: Tree }
  w.__GALAXIE_WOO__ ??= {}
  return w.__GALAXIE_WOO__
}

function refreshState(): RefreshState {
  const w = window as unknown as { __GALAXIE_NONCES__?: RefreshState }
  w.__GALAXIE_NONCES__ ??= {}
  return w.__GALAXIE_NONCES__
}

function isTree(value: unknown): value is Tree {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** Writes `source`'s leaves into `target`, keeping `target`'s objects (and every reference to them). */
function mergeInPlace(target: Tree, source: Tree): void {
  for (const [key, value] of Object.entries(source)) {
    const current = target[key]
    if (isTree(value)) {
      if (isTree(current)) {
        mergeInPlace(current, value)
      } else {
        target[key] = value
      }
    } else if (typeof value === 'string') {
      target[key] = value
    }
  }
}

function refreshUrl(): string {
  const url = bootRoot().ajaxUrl
  return typeof url === 'string' && url !== '' ? url : '/wp-admin/admin-ajax.php'
}

/**
 * Asks the store for this visitor's current nonces and writes them into
 * window.__GALAXIE_WOO__. Concurrent callers share one request. Resolves
 * false when the store could not be reached; callers then keep what they had.
 */
export function refreshNonces(): Promise<boolean> {
  const state = refreshState()
  if (state.pending) return state.pending

  const body = new URLSearchParams({ action: REFRESH_ACTION })

  state.pending = fetch(refreshUrl(), { method: 'POST', credentials: 'same-origin', cache: 'no-store', body })
    .then((res) => res.json() as Promise<AjaxResult<{ nonces?: Tree; generatedAt?: number }>>)
    .then((json) => {
      if (!json?.success || !json.data || !isTree(json.data.nonces)) return false
      const root = bootRoot()
      mergeInPlace(root, json.data.nonces)
      if (typeof json.data.generatedAt === 'number') root.generatedAt = json.data.generatedAt
      return true
    })
    .catch(() => false)
    .finally(() => {
      state.pending = undefined
    })

  return state.pending
}

/** Refreshes first when the page's boot data is old enough for its nonces to have lapsed. */
export async function refreshIfStale(): Promise<void> {
  const generatedAt = Number(bootRoot().generatedAt) || 0
  if (!generatedAt || Date.now() / 1000 - generatedAt < STALE_AFTER_S) return
  await refreshNonces()
}

/** WordPress refusing the request's nonce: `check_ajax_referer()`'s bare -1, or a 403. */
export function nonceRefused(status: number, text: string): boolean {
  return status === 403 || text.trim() === '-1'
}

export interface AjaxReply {
  status: number
  text: string
}

/** The reply's JSON, or null when it is not JSON (a bare -1, an HTML error page). */
export function parseReply<T = unknown>(reply: AjaxReply): AjaxResult<T> | null {
  try {
    return JSON.parse(reply.text) as AjaxResult<T>
  } catch {
    return null
  }
}

/**
 * Sends the request `send` builds (it must read its nonce at call time), and
 * when the store refuses the nonce, refreshes the page's nonces once and sends
 * it once more. A network failure rejects, as `fetch` does.
 */
export async function withFreshNonce(send: () => Promise<Response>): Promise<AjaxReply> {
  await refreshIfStale()

  let res = await send()
  let text = await res.text()

  if (nonceRefused(res.status, text) && (await refreshNonces())) {
    res = await send()
    text = await res.text()
  }

  return { status: res.status, text }
}

/**
 * POST `fields` to admin-ajax with `holder.nonce` as `nonce`, read at send
 * time. `holder` is the config object from window.__GALAXIE_WOO__ the caller
 * was booted with, which a refresh updates in place.
 */
export function postWithNonce(ajaxUrl: string, holder: { nonce: string }, fields: Record<string, string>): Promise<AjaxReply> {
  return withFreshNonce(() =>
    fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: new URLSearchParams({ ...fields, nonce: holder.nonce }),
    })
  )
}

/** Where in window.__GALAXIE_WOO__ a nonce value sits, so its fresh value can be read back after a refresh. */
function nonceKeyPath(value: string, tree: Tree = bootRoot(), path: string[] = []): string[] | null {
  for (const [key, child] of Object.entries(tree)) {
    if (child === value && /nonce$/i.test(key)) return [...path, key]
    if (isTree(child)) {
      const found = nonceKeyPath(value, child, [...path, key])
      if (found) return found
    }
  }
  return null
}

function valueAt(path: string[] | null): string | null {
  if (!path) return null
  let node: unknown = bootRoot()
  for (const key of path) {
    if (!isTree(node)) return null
    node = node[key]
  }
  return typeof node === 'string' ? node : null
}

/**
 * POST to admin-ajax, mirroring the proven v1 helper: FormData with `action`
 * + `nonce`, same-origin credentials, JSON back, and a synthetic failure shape
 * instead of a thrown error so callers can always read `.success`.
 *
 * A refused nonce is refreshed and the request sent once more (see
 * withFreshNonce). The fresh value is read from wherever the one passed in sits
 * in window.__GALAXIE_WOO__, so callers keep passing `cfg.x.nonce`.
 */
export async function post<T = unknown, D extends object = Record<string, unknown>>(
  ajaxUrl: string,
  action: string,
  nonce: string,
  data: D = {} as D
): Promise<AjaxResult<T>> {
  const path = nonce ? nonceKeyPath(nonce) : null

  const send = (): Promise<Response> => {
    const body = new FormData()
    body.append('action', action)
    body.append('nonce', valueAt(path) ?? nonce)
    for (const [key, value] of Object.entries(data)) {
      if (value !== undefined && value !== null) {
        body.append(key, String(value))
      }
    }
    return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
  }

  try {
    const reply = await withFreshNonce(send)
    return parseReply<T>(reply) ?? { success: false, data: { message: OFFLINE } as T & { message?: string } }
  } catch {
    return { success: false, data: { message: OFFLINE } as T & { message?: string } }
  }
}

// A page served from a cache long enough for its nonces to lapse gets fresh
// ones as soon as it runs, so code that reads `cfg.x.nonce` without going
// through the helpers above is covered too. An uncached page is never that old.
if (typeof window !== 'undefined') {
  window.setTimeout(() => void refreshIfStale(), 0)
}
