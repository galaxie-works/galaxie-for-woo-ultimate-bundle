/**
 * Keeping the cart's separate widgets in step with each other.
 *
 * The coupon field, the shipping options and the totals box are separate
 * Elementor widgets, so each can sit where the merchant wants it. WooCommerce's
 * cart.js knows about none of them. After a CEP, a coupon or a quantity change
 * it redraws `.woocommerce-cart-form` and `.cart_totals` and nothing else, so
 * the other widgets went stale beside a correct total.
 *
 * Each of those widgets marks its root with `data-galaxie-fragment`. Whenever
 * a whole cart page comes back from the server, whether cart.js fetched it or
 * we did, the fresh copies are swapped in by key. No second endpoint, no
 * second rendering path: it is the same PHP that drew the page.
 */

interface AjaxOptions {
  url?: string
  type?: string
  data?: unknown
}

interface JQueryStaticLike {
  (el: unknown): {
    trigger: (event: string, args?: unknown[]) => void
    on: (events: string, handler: (...args: unknown[]) => void) => void
    ajaxComplete: (handler: (event: unknown, xhr: { responseText?: unknown }) => void) => void
  }
  ajaxPrefilter: (handler: (options: AjaxOptions) => void) => void
}

export function jq(): JQueryStaticLike | undefined {
  return (window as unknown as { jQuery?: JQueryStaticLike }).jQuery
}

/**
 * The Elementor ids of the widget an element belongs to, as request fields.
 *
 * The server needs them to render with that widget's saved settings, and
 * Elementor already writes both into the page.
 */
export function widgetOrigin(el: Element | null, kind: string): Record<string, string> {
  const element = el?.closest<HTMLElement>('.elementor-element[data-id]')
  const document_ = el?.closest<HTMLElement>('[data-elementor-id]')
  const elementId = element?.dataset.id
  const postId = document_?.dataset.elementorId

  return elementId && postId ? { [`galaxie_${kind}_post`]: postId, [`galaxie_${kind}_element`]: elementId } : {}
}

export function swapFragments(html: string): void {
  if (!html.includes('data-galaxie-fragment')) return

  const fresh = new DOMParser().parseFromString(html, 'text/html')
  const seen = new Set<string>()

  fresh.querySelectorAll<HTMLElement>('[data-galaxie-fragment]').forEach((node) => {
    const key = node.dataset.galaxieFragment ?? ''
    seen.add(key)

    document.querySelectorAll<HTMLElement>('[data-galaxie-fragment]').forEach((old) => {
      if (old.dataset.galaxieFragment !== key) return

      const next = document.importNode(node, true)

      // What the shopper was just told stays on screen through the swap.
      old.querySelectorAll<HTMLElement>('[data-galaxie-preserve]').forEach((keep) => {
        next.querySelector(`[data-galaxie-preserve="${keep.dataset.galaxiePreserve}"]`)?.replaceWith(keep)
      })

      old.replaceWith(next)
    })
  })

  // A widget that rendered nothing this time, because the cart emptied, leaves
  // nothing behind. Only a whole cart page can say so.
  if (fresh.body?.classList.contains('woocommerce-cart')) {
    document.querySelectorAll<HTMLElement>('[data-galaxie-fragment]').forEach((old) => {
      if (!seen.has(old.dataset.galaxieFragment ?? '')) old.remove()
    })
  }

  jq()?.(document.body).trigger('galaxie_fragments_updated')
}

export async function refreshFragments(): Promise<void> {
  if (!document.querySelector('[data-galaxie-fragment]')) return

  const response = await fetch(window.location.href, { credentials: 'same-origin' })
  swapFragments(await response.text())
}

export function bootCartFragments(): void {
  const $ = jq()
  if (!$) return

  // Every jQuery request that brings back a cart page: cart.js's own
  // `update_cart`, and the shipping calculator's post.
  $(document).ajaxComplete((_event, xhr) => {
    if (typeof xhr?.responseText === 'string') swapFragments(xhr.responseText)
  })

  // WooCommerce answers these two with its default totals template. Sending
  // the totals widget's ids along lets the server answer with ours instead.
  $.ajaxPrefilter((options) => {
    if (!options.url || !/wc-ajax=(update_shipping_method|get_cart_totals)\b/.test(options.url)) return

    const extra = new URLSearchParams(widgetOrigin(document.querySelector('.galaxie-cart-totals'), 'totals')).toString()
    if (!extra) return

    if ((options.type ?? 'GET').toUpperCase() === 'POST') {
      options.data = typeof options.data === 'string' && options.data !== '' ? `${options.data}&${extra}` : extra
    } else {
      options.url += (options.url.includes('?') ? '&' : '?') + extra
    }
  })
}
