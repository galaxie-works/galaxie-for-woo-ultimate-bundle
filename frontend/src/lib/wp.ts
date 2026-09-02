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

export interface GalaxieWooConfig {
  auth?: AjaxEndpoint
  checkout?: AjaxEndpoint
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

/**
 * POST to admin-ajax, mirroring the proven v1 helper: FormData with `action`
 * + `nonce`, same-origin credentials, JSON back, and a synthetic failure shape
 * instead of a thrown error so callers can always read `.success`.
 */
export async function post<T = unknown, D extends object = Record<string, unknown>>(
  ajaxUrl: string,
  action: string,
  nonce: string,
  data: D = {} as D
): Promise<AjaxResult<T>> {
  const body = new FormData()
  body.append('action', action)
  body.append('nonce', nonce)
  for (const [key, value] of Object.entries(data)) {
    if (value !== undefined && value !== null) {
      body.append(key, String(value))
    }
  }
  try {
    const res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
    return (await res.json()) as AjaxResult<T>
  } catch {
    return { success: false, data: { message: 'Network error' } as T & { message?: string } }
  }
}
