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
    on: (event: string, handler: (event: unknown, ...args: unknown[]) => void) => void
    off: (event: string, handler: () => void) => void
    trigger: (event: string, args?: unknown[]) => void
  }
}

/**
 * WooCommerce renders these with a matching id and name, but themes and
 * checkout-field plugins routinely drop one or the other, so both lookups
 * have to stay.
 */
function findNativeField(name: string): HTMLInputElement | HTMLSelectElement | null {
  return (
    document.querySelector<HTMLInputElement | HTMLSelectElement>(`#${name}`) ??
    document.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`)
  )
}

/** Sets a value on a native (hidden) checkout field and fires the events WooCommerce's own scripts listen for. */
export function setNativeField(name: string, value: string | undefined): void {
  if (value === undefined) return
  const el = findNativeField(name)
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
 * Asks WooCommerce to judge the named native fields and reports back the ids
 * it rejected — empty means the step is clear to proceed.
 *
 * Going through WooCommerce rather than reimplementing its rules is the whole
 * point: which billing fields are required, and under which locale, is store
 * configuration we do not own, and its answer here is the same answer the
 * order will get at submit time. The contract is a documented one — its
 * `checkout.js` binds a delegated `validate` handler that stamps
 * `woocommerce-invalid` / `woocommerce-validated` onto each field's wrapping
 * `p.form-row` — so we fire the event and read the class back off the row.
 *
 * The usual idiom scopes this to `.input-text:visible, select:visible,
 * input:checkbox:visible` inside a container. We cannot: the fields the
 * shopper sees are our React inputs, and WooCommerce's own are the
 * deliberately hidden ones we mirror into (see `fillNativeBilling`), which no
 * `:visible` selector would ever reach. Hence naming the ids per step.
 *
 * Fails open. Without jQuery there is no `validate` handler to answer us, and
 * an unanswerable question is no reason to strand a paying customer — let
 * WooCommerce reject the order instead.
 */
export function validateNativeFields(names: string[]): string[] {
  const $ = window.jQuery
  if (!$) return []

  const failed: string[] = []
  for (const name of names) {
    const el = findNativeField(name)
    if (!el) continue
    $(el).trigger('validate')
    if (el.closest('.form-row')?.classList.contains('woocommerce-invalid')) {
      failed.push(name)
    }
  }
  return failed
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

/** The panel classes the decorators below put on WooCommerce's markup. */
export interface NativeDecor {
  rate: string
  rateName: string
  method: string
  methodName: string
  methodBox: string
  /** Saved-card rows and their parts (see PHP Checkout\PaymentMarkup). */
  token: string
  tokenNumber: string
  tokenExpiry: string
  tokenBadge: string
  /** Small print: gateway descriptions, the save-card checkbox. */
  small: string
  /** Field labels: the editor sample's stand-ins for Stripe's field labels. */
  label: string
  /** pixfort's place-order button markup, `marker` standing for the label. */
  placeOrder: { html: string; full: boolean }
  marker: string
}

function addClasses(el: Element | null, classes: string): void {
  if (!el || !classes) return
  el.classList.add(...classes.split(/\s+/).filter(Boolean))
}

/**
 * Puts the widget's "Delivery: shipping options" classes on WooCommerce's
 * own rate list. That list is WooCommerce's markup, re-printed on every
 * recalculation, so it cannot carry our classes by itself — the same
 * position the account widgets are in with WooCommerce's address fields,
 * where the classes are injected rather than the markup rewritten.
 */
export function decorateShipping(mount: HTMLElement | null, decor: NativeDecor): void {
  mount?.querySelectorAll('#shipping_method > li').forEach((li) => {
    addClasses(li, decor.rate)
    addClasses(li.querySelector('label'), decor.rateName)
  })
}

/**
 * The same for the payment block, plus the place-order button: WooCommerce's
 * `<button id="place_order">` stays the element WooCommerce and the gateways
 * drive (its id, name, value and type are untouched), and pixfort's button
 * is drawn inside it, as every other button of the plugin is. Its label is
 * WooCommerce's, read back from the button, because gateways rename it.
 *
 * Idempotent, and meant to be called again whenever WooCommerce redraws: it
 * replaces the whole `#payment` fragment on every `updated_checkout`, and
 * swaps the button's text on its own when the shopper changes gateway.
 */
export function decoratePayment(mount: HTMLElement | null, decor: NativeDecor): void {
  if (!mount) return

  mount.querySelectorAll('li.wc_payment_method').forEach((li) => {
    addClasses(li, decor.method)
    addClasses(li.querySelector(':scope > label'), decor.methodName)
    addClasses(li.querySelector('.payment_box'), `${decor.methodBox} ${decor.small}`)
  })
  mount.querySelectorAll('.wc-saved-payment-methods > li').forEach((li) => addClasses(li, decor.token))
  mount.querySelectorAll('.gx-co-card-number').forEach((el) => addClasses(el, decor.tokenNumber))
  mount.querySelectorAll('.gx-co-card-expiry').forEach((el) => addClasses(el, decor.tokenExpiry))
  mount.querySelectorAll('.gx-co-card-badge').forEach((el) => addClasses(el, decor.tokenBadge))
  mount.querySelectorAll('.gx-co-sample-label').forEach((el) => addClasses(el, decor.label))

  const button = mount.querySelector<HTMLButtonElement>('#place_order')
  if (!button || button.querySelector('.btn')) return

  const label = (button.textContent ?? '').trim() || button.dataset.value || button.value
  const safe = label.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c)
  button.classList.remove('button', 'alt')
  button.classList.add('galaxie-account-submit', 'gx-co-btn')
  button.classList.toggle('is-full', decor.placeOrder.full)
  button.innerHTML = decor.placeOrder.html.split(decor.marker).join(safe)
}

/**
 * Re-runs `decoratePayment` whenever WooCommerce touches the block: a
 * `MutationObserver` rather than WooCommerce's events, because the gateway
 * switch rewrites the button with `.text()` and announces nothing.
 */
export function watchPayment(mount: HTMLElement | null, decor: NativeDecor): () => void {
  if (!mount) return () => {}
  decoratePayment(mount, decor)
  const observer = new MutationObserver(() => decoratePayment(mount, decor))
  observer.observe(mount, { childList: true, subtree: true })
  return () => observer.disconnect()
}

/**
 * The look Stripe's card form takes, from the checkout's own fields.
 *
 * The new card is typed into Stripe's Payment Element, an iframe this page
 * cannot style. Left to itself, the Stripe plugin copies its look from
 * `#billing_first_name` and the payment box — WooCommerce's native fields,
 * which this widget keeps hidden, and a box the theme paints — so the frame
 * came out in colours from nowhere on the page. The plugin takes a finished
 * Appearance object from `wc_stripe_upe_params.appearance` instead of
 * computing one, so this writes one there, read off `probe`: an input
 * carrying the same `.form-control` and "Fields" classes every checkout field
 * has, and a label with the "Field labels" classes. Whatever the panel sets
 * for the fields is what Stripe's fields get.
 *
 * Must run before the plugin mounts its element (it does so after the first
 * `updated_checkout`); the island runs at DOMContentLoaded, earlier.
 */
export function applyStripeAppearance(probe: HTMLElement | null): void {
  const params = (window as unknown as { wc_stripe_upe_params?: Record<string, unknown> }).wc_stripe_upe_params
  const input = probe?.querySelector<HTMLElement>('input')
  const label = probe?.querySelector<HTMLElement>('.gx-co-label')
  const accent = probe?.querySelector<HTMLElement>('.gx-co-stripe-accent')
  if (!params || !input || !label || !accent) return

  const field = getComputedStyle(input)
  const text = getComputedStyle(label)
  const primary = getComputedStyle(accent).backgroundColor
  const background = opaque(field.backgroundColor) ? field.backgroundColor : pageBackground(probe as HTMLElement)
  const border = `${field.borderTopWidth} ${field.borderTopStyle === 'none' ? 'solid' : field.borderTopStyle} ${field.borderTopColor}`

  params.appearance = {
    theme: isDark(background) ? 'night' : 'stripe',
    variables: {
      colorPrimary: primary,
      colorBackground: background,
      colorText: field.color,
      colorDanger: '#df1b41',
      fontFamily: field.fontFamily,
      fontSizeBase: field.fontSize,
      borderRadius: field.borderTopLeftRadius,
    },
    rules: {
      '.Input': { border, boxShadow: 'none', padding: `${field.paddingTop} ${field.paddingLeft}` },
      '.Input:focus': { borderColor: primary, boxShadow: 'none' },
      '.Label': { color: text.color, fontWeight: text.fontWeight, fontSize: text.fontSize },
      '.Tab': { border, boxShadow: 'none', backgroundColor: background },
      '.Tab--selected': { borderColor: primary, boxShadow: 'none' },
    },
  }
}

function channels(color: string): number[] {
  return (color.match(/[\d.]+/g) ?? []).map(Number)
}

function opaque(color: string): boolean {
  const [, , , alpha = 1] = channels(color)
  return channels(color).length >= 3 && alpha > 0.9
}

/** The first painted background up the tree: what a transparent field sits on. */
function pageBackground(from: HTMLElement): string {
  for (let el: HTMLElement | null = from; el; el = el.parentElement) {
    const bg = getComputedStyle(el).backgroundColor
    if (opaque(bg)) return bg
  }
  return 'rgb(255, 255, 255)'
}

function isDark(color: string): boolean {
  const [r = 255, g = 255, b = 255] = channels(color)
  return 0.2126 * r + 0.7152 * g + 0.0722 * b < 128
}
