/**
 * Interop with WooCommerce's own (hidden) checkout form rendered by
 * `[woocommerce_checkout]` inside the widget. These are plain DOM functions,
 * not React — the moved nodes carry WooCommerce's own live jQuery event
 * bindings, which only survive if we relocate the actual elements rather than
 * re-rendering equivalent markup ourselves.
 */

declare global {
  interface Window {
    jQuery?: JQueryStatic
  }
}

interface JQueryStatic {
  (selector: Document | Element | string): {
    on: (event: string, handler: () => void) => void
    off: (event: string, handler: () => void) => void
    trigger: (event: string) => void
  }
}

/** Sets a value on a native (hidden) checkout field and fires the events WooCommerce's own scripts listen for. */
export function setNativeField(name: string, value: string | undefined): void {
  if (value === undefined) return
  const el =
    document.querySelector<HTMLInputElement | HTMLSelectElement>(`#${name}`) ??
    document.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`)
  if (!el) return
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
  el.dispatchEvent(new Event('change', { bubbles: true }))
}

export interface NativeBillingState {
  first_name?: string
  last_name?: string
  phone?: string
  email?: string
  address_1?: string
  address_2?: string
  city?: string
  state?: string
  postcode?: string
  country?: string
}

export function fillNativeBilling(state: NativeBillingState): void {
  setNativeField('billing_first_name', state.first_name)
  setNativeField('billing_last_name', state.last_name)
  setNativeField('billing_phone', state.phone)
  setNativeField('billing_email', state.email)
  if (state.address_1 !== undefined) {
    setNativeField('billing_country', state.country || 'BR')
    setNativeField('billing_address_1', state.address_1)
    setNativeField('billing_address_2', state.address_2)
    setNativeField('billing_city', state.city)
    setNativeField('billing_state', state.state)
    setNativeField('billing_postcode', state.postcode)
  }
}

/**
 * Moves the native shipping-method list into `mount`. Scoped to
 * `#order_review` — WooCommerce regenerates the WHOLE
 * `.woocommerce-checkout-review-order-table` fragment (including a fresh
 * `#shipping_method` back in its original spot) on every `updated_checkout`,
 * so this must be called again after every one of those (an unscoped lookup
 * would instead find our own already-relocated copy and do nothing). Returns
 * whether a real rate list was found this run — false means either no
 * address yet, or the cart doesn't need shipping.
 */
export function relocateShippingMethod(mount: HTMLElement | null): boolean {
  if (!mount) return false

  const list = document.querySelector('#order_review #shipping_method');
  const row = document.querySelector<HTMLElement>('#order_review tr.woocommerce-shipping-totals');

  if (!list) {
    if (row) row.style.display = 'none'
    return false
  }

  if (row) row.style.display = ''

  mount.innerHTML = ''
  const td = list.parentElement
  if (td) {
    while (td.firstChild) {
      mount.appendChild(td.firstChild)
    }
  } else {
    mount.appendChild(list)
  }
  return true
}

/** True once a shipping method is actually selected (or the cart never needed one). */
export function hasChosenShippingMethod(mount: HTMLElement | null, everSeenRates: boolean): boolean {
  if (!everSeenRates) return true
  if (!mount) return false
  if (mount.querySelector<HTMLInputElement>('input.shipping_method[type="hidden"]')) return true
  return !!mount.querySelector<HTMLInputElement>('input.shipping_method:checked')
}

/**
 * Moves the native `#payment` block (methods + place-order button) into
 * `mount`. Unlike shipping, this only needs to run once: `#payment` IS the
 * whole fragment WooCommerce's own JS replaces by selector on every
 * `updated_checkout`, so once it's inside `mount` it keeps getting correctly
 * replaced there (id-based selection doesn't care about its parent).
 */
export function relocatePayment(mount: HTMLElement | null): void {
  if (!mount) return
  const payment = document.querySelector('#payment')
  if (payment && !mount.contains(payment)) {
    mount.appendChild(payment)
  }
}

/** Triggers WooCommerce's own totals recalculation and resolves once it (or an 8s safety timeout) completes. */
export function waitForCheckoutUpdate(): Promise<void> {
  return new Promise((resolve) => {
    const $ = window.jQuery
    if (!$) {
      resolve()
      return
    }
    let done = false
    const finish = () => {
      if (done) return
      done = true
      $(document.body).off('updated_checkout.galaxie', finish)
      resolve()
    }
    $(document.body).on('updated_checkout.galaxie', finish)
    $(document.body).trigger('update_checkout')
    window.setTimeout(finish, 8000)
  })
}

/** Subscribes `handler` to WooCommerce's `updated_checkout` for the component's lifetime. No-op cleanup if jQuery is absent. */
export function onCheckoutUpdated(handler: () => void): () => void {
  const $ = window.jQuery
  if (!$) return () => {}
  $(document.body).on('updated_checkout.galaxie', handler)
  return () => $(document.body).off('updated_checkout.galaxie', handler)
}
