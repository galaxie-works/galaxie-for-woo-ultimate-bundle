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

/**
 * Writes the step's answers into WooCommerce's hidden form — the billing
 * fields and, when the form has them, the shipping ones too.
 *
 * Which of the two WooCommerce quotes carriers for is store configuration
 * ("Shipping destination") plus a checkbox we never show: with it ticked,
 * `update_order_review` sends the shipping fields, which held the account's
 * old address — or nothing at all, for a new account — whatever address the
 * shopper picked. Filling both makes the answer the same either way.
 */
export function fillNativeBilling(state: NativeBillingState): void {
  for (const type of ['billing', 'shipping'] as const) {
    setNativeField(`${type}_first_name`, state.first_name)
    setNativeField(`${type}_last_name`, state.last_name)
    setNativeField(`${type}_phone`, state.phone)
    if (state.address_1 !== undefined) {
      setNativeField(`${type}_country`, state.country || 'BR')
      setNativeField(`${type}_address_1`, state.address_1)
      setNativeField(`${type}_address_2`, state.address_2)
      setNativeField(`${type}_city`, state.city)
      setNativeField(`${type}_state`, state.state)
      setNativeField(`${type}_postcode`, state.postcode)
    }
  }
  setNativeField('billing_email', state.email)
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
 * cannot style. Left to itself, the WooCommerce Stripe plugin copies its look
 * from `#billing_first_name` and the payment box — WooCommerce's native
 * fields, which this widget keeps hidden, and a box the theme paints — so the
 * frame came out in colours from nowhere on the page; FunnelKit's plugin gives
 * it Stripe's stock theme. So one Appearance object is read off `probe` — an
 * input carrying the same `.form-control` and "Fields" classes every checkout
 * field has, and a label with the "Field labels" classes — and handed to
 * whichever plugin is on the page. Whatever the panel sets for the fields is
 * what Stripe's fields get.
 *
 * - WooCommerce Stripe takes a finished Appearance from
 *   `wc_stripe_upe_params.appearance` instead of computing one, so it is
 *   written there.
 * - FunnelKit: see applyFunnelKitAppearance().
 *
 * Must run before the plugin mounts its element (both do so after the first
 * `updated_checkout`); the island runs at DOMContentLoaded, earlier.
 */
export function applyStripeAppearance(probe: HTMLElement | null): void {
  const appearance = stripeAppearance(probe)
  if (!appearance) return

  const params = (window as unknown as { wc_stripe_upe_params?: Record<string, unknown> }).wc_stripe_upe_params
  if (params) params.appearance = appearance

  applyFunnelKitAppearance(appearance)
}

/** The Appearance object for Stripe's Payment Element, read off the probe; null without one. */
function stripeAppearance(probe: HTMLElement | null): Record<string, unknown> | null {
  const input = probe?.querySelector<HTMLElement>('input')
  const label = probe?.querySelector<HTMLElement>('.gx-co-label')
  const accent = probe?.querySelector<HTMLElement>('.gx-co-stripe-accent')
  if (!input || !label || !accent) return null

  const field = getComputedStyle(input)
  const text = getComputedStyle(label)
  const primary = getComputedStyle(accent).backgroundColor
  const background = opaque(field.backgroundColor) ? field.backgroundColor : pageBackground(probe as HTMLElement)
  const border = `${field.borderTopWidth} ${field.borderTopStyle === 'none' ? 'solid' : field.borderTopStyle} ${field.borderTopColor}`

  return {
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

/** What FunnelKit's card gateway object (stripe-elements.js `FKWCS_Stripe`) is read for. */
interface FunnelKitCardGateway {
  elements?: { update?: (options: Record<string, unknown>) => void } | null
}

interface JQueryOn {
  on: (event: string, handler: (event: unknown, ...args: unknown[]) => void) => unknown
}

let funnelKitAppearance: Record<string, unknown> | null = null
let funnelKitBound = false

/**
 * FunnelKit's card form, when the merchant chose its "Enhanced Payment
 * Element": the Appearance goes where FunnelKit looks for one, and back on
 * every time FunnelKit puts its stock theme in its place.
 *
 * FunnelKit builds the element from `fkwcs_data.fkwcs_payment_data.element_data`
 * (appearance `{ theme: 'stripe' }`) and, on each `updated_checkout`, swaps in
 * the copy its fragments carry and pushes any changed key — appearance
 * included — into the live element with `elements.update()`. The object on the
 * page is given ours (a page with no fragments, such as order-pay, mounts from
 * it); the live element is restyled through the `elements` of the gateway
 * object FunnelKit hands out with its `fkwcs_payment_element_mounted` event,
 * once mounted and again after every `updated_checkout` — on the next tick, so
 * after FunnelKit's own handler whichever was bound first. FunnelKit's copy
 * of the data is left as it is, so its "has anything changed?" comparison
 * keeps telling a real change from ours.
 *
 * FunnelKit's other card forms (inline or separate card fields) take their
 * look from `fkwcs_data.common_style` / `inline_style`, read once when its
 * script loads in the head, before this runs; they keep FunnelKit's style.
 */
function applyFunnelKitAppearance(appearance: Record<string, unknown>): void {
  type FkwcsData = { fkwcs_payment_data?: { element_data?: Record<string, unknown> } }
  const elementData = (window as unknown as { fkwcs_data?: FkwcsData }).fkwcs_data?.fkwcs_payment_data?.element_data
  if (!elementData) return

  elementData.appearance = appearance
  funnelKitAppearance = appearance

  const $ = (window as unknown as { jQuery?: (target: unknown) => JQueryOn }).jQuery
  if (!$ || funnelKitBound) return
  funnelKitBound = true

  let gateway: FunnelKitCardGateway | null = null
  const restyle = () => {
    try {
      if (funnelKitAppearance) gateway?.elements?.update?.({ appearance: funnelKitAppearance })
    } catch {
      // A look not applied is FunnelKit's stock look, never a broken form.
    }
  }

  $(document).on('fkwcs_payment_element_mounted', (_event, _element, mounted) => {
    gateway = (mounted as FunnelKitCardGateway | undefined) ?? null
    restyle()
  })
  $(document.body).on('updated_checkout', () => window.setTimeout(restyle, 0))
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
