/**
 * Behaviour for the "Galaxie Cart Coupon" widget.
 *
 * Applying and removing go to WooCommerce's own `apply_coupon` and
 * `remove_coupon` endpoints with WooCommerce's own nonces, the same requests its
 * cart.js makes. Two things are added: the widget's ids, so the server can use
 * the messages the merchant wrote, and a refresh of the cart afterwards,
 * because a coupon changes the totals, the free shipping bar and sometimes the
 * shipping options too.
 *
 * Without WooCommerce's cart parameters on the page, nothing binds and the form
 * posts to the cart handler as a normal form.
 */

import { jq, refreshFragments, swapFragments, widgetOrigin } from '@/globals/cart-fragments'

interface CouponParams {
  wc_ajax_url: string
  apply_coupon_nonce: string
  remove_coupon_nonce: string
}

const ERROR = /woocommerce-error|is-error/

function params(): CouponParams | null {
  const w = window as unknown as { wc_cart_params?: CouponParams; wc_checkout_params?: CouponParams }
  const p = w.wc_cart_params ?? w.wc_checkout_params

  return p?.wc_ajax_url && p.apply_coupon_nonce ? p : null
}

/** Set while this file triggers WooCommerce's coupon events, so it does not react to itself. */
let ours = false

async function post(p: CouponParams, endpoint: string, data: Record<string, string>): Promise<string> {
  const response = await fetch(p.wc_ajax_url.replace('%%endpoint%%', endpoint), {
    method: 'POST',
    body: new URLSearchParams(data),
    credentials: 'same-origin',
  })

  return response.text()
}

function show(widget: HTMLElement, html: string): void {
  const holder = widget.querySelector<HTMLElement>('.galaxie-coupon-notice')
  if (!holder) return

  if (widget.dataset.messages === 'notice') {
    // WooCommerce's own notice markup. The Toast Notices module turns it into
    // a toast wherever it lands; without that module it shows here.
    holder.className = 'galaxie-coupon-notice is-store-notice'
    holder.innerHTML = html
    holder.hidden = html.trim() === ''
    return
  }

  const isError = ERROR.test(html)
  const text = (new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '').replace(/\s+/g, ' ').trim()
  const classes = (isError ? widget.dataset.errorClass : widget.dataset.okClass) ?? ''

  holder.className = `galaxie-coupon-notice ${isError ? 'is-error' : 'is-success'} ${classes}`.trim()
  holder.textContent = text
  holder.hidden = text === ''
}

function trigger(event: string, args: unknown[]): void {
  ours = true
  jq()?.(document.body).trigger(event, args)
  ours = false
}

async function refresh(): Promise<void> {
  const $ = jq()

  // cart.js re-fetches the cart page and redraws the table and totals, and the
  // fragment listener refreshes this widget from that same response.
  if ($ && document.querySelector('.woocommerce-cart-form')) {
    $(document.body).trigger('wc_update_cart')
    return
  }

  // No cart table on the page, so cart.js has nothing to redraw. Do it here.
  const html = await (await fetch(window.location.href, { credentials: 'same-origin' })).text()
  swapFragments(html)

  const next = new DOMParser().parseFromString(html, 'text/html').querySelector('.cart_totals')
  const current = document.querySelector('.cart_totals')

  if (next && current) {
    current.replaceWith(document.importNode(next, true))
    jq()?.(document.body).trigger('updated_cart_totals')
  }
}

function busy(widget: HTMLElement, on: boolean): void {
  widget.classList.toggle('is-busy', on)
  widget.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
    button.disabled = on
  })
}

export function bootCoupon(): void {
  document.addEventListener('submit', (event) => {
    const form = (event.target as Element | null)?.closest?.<HTMLFormElement>('.galaxie-coupon-form')
    const widget = form?.closest<HTMLElement>('.galaxie-coupon')
    const p = params()

    if (!form || !widget || !p) return

    event.preventDefault()

    const input = form.querySelector<HTMLInputElement>('input[name="coupon_code"]')
    const code = input?.value.trim() ?? ''

    busy(widget, true)

    void post(p, 'apply_coupon', { security: p.apply_coupon_nonce, coupon_code: code, ...widgetOrigin(widget, 'coupon') })
      .then(async (html) => {
        show(widget, html)
        if (!ERROR.test(html) && input) input.value = ''
        trigger('applied_coupon', [code])
        await refresh()
      })
      .finally(() => busy(widget, false))
  })

  document.addEventListener('click', (event) => {
    const link = (event.target as Element | null)?.closest?.<HTMLAnchorElement>('.galaxie-coupon-remove')
    const widget = link?.closest<HTMLElement>('.galaxie-coupon')
    const p = params()

    if (!link || !widget || !p) return

    event.preventDefault()
    busy(widget, true)

    const coupon = link.dataset.coupon ?? ''

    void post(p, 'remove_coupon', { security: p.remove_coupon_nonce, coupon, ...widgetOrigin(widget, 'coupon') })
      .then(async (html) => {
        show(widget, html)
        trigger('removed_coupon', [coupon])
        await refresh()
      })
      .finally(() => busy(widget, false))
  })

  // A coupon removed somewhere else, such as the [Remove] link WooCommerce
  // prints in the totals box, leaves this widget's list stale.
  jq()?.(document.body).on('applied_coupon removed_coupon', () => {
    if (!ours) void refreshFragments()
  })
}
