/**
 * The checkout for a gift from a shared wishlist.
 *
 * The parcel goes to the list owner's saved address, which this page never
 * receives. The server writes the real address onto the order and keeps
 * placeholders off the buyer's account (see PHP Modules\Wishlist\Gifts).
 *
 * - The block checkout: the shipping step is replaced by a line saying who it
 *   is for and where (city/state), and the shipping address the checkout holds
 *   is filled with that area plus placeholders — the checkout needs something
 *   valid to submit. Billing is always the buyer's own, so "use shipping as
 *   billing" is turned off.
 * - The Galaxie Checkout widget (islands/checkout): its delivery step reads
 *   {@link giftCheckoutConfig} and says the same (GiftDelivery.tsx); the
 *   address it asks for is the buyer's, for billing. Nothing to fill there.
 */

export interface GiftCheckoutConfig {
  firstName: string
  city: string
  state: string
  postcode: string
  country: string
  notice: string
  placeholder: string
  /** "Este presente será enviado para <nome> (endereço protegido)." */
  delivery?: string
  /** Under the delivery notice: the form below is the buyer's own address. */
  billingHint?: string
  /** The folded delivery step's line: "Presente para <nome> — cidade/UF". */
  summary?: string
}

/** The gift the checkout is for, as the PHP boot data has it; null for an ordinary order. */
export function giftCheckoutConfig(): GiftCheckoutConfig | null {
  return (window as unknown as { __GALAXIE_WOO__?: { giftCheckout?: GiftCheckoutConfig } }).__GALAXIE_WOO__?.giftCheckout ?? null
}

interface WpData {
  select: (store: string) => { getCustomerData?: () => { shippingAddress?: Record<string, string> } } | undefined
  dispatch: (store: string) => Record<string, ((value: unknown) => void) | undefined> | undefined
}

function wpData(): WpData | undefined {
  return (window as unknown as { wp?: { data?: WpData } }).wp?.data
}

/**
 * Only on the block checkout. A mini-cart block elsewhere registers the same
 * `wc/store/cart` store, and writing placeholders into it there would push
 * them to the session for no checkout at all.
 */
function blockCheckout(): boolean {
  return null !== document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout')
}

function fill(config: GiftCheckoutConfig): void {
  const data = wpData()
  const current = data?.select('wc/store/cart')?.getCustomerData?.()?.shippingAddress ?? {}

  const wanted: Record<string, string> = {
    first_name: config.firstName,
    last_name: config.firstName,
    company: '',
    address_1: config.placeholder,
    address_2: '',
    city: config.city,
    state: config.state,
    postcode: config.postcode,
    country: config.country,
  }

  if (Object.entries(wanted).some(([key, value]) => (current[key] ?? '') !== value)) {
    data?.dispatch('wc/store/cart')?.setShippingAddress?.({ ...current, ...wanted })
  }

  data?.dispatch('wc/store/checkout')?.__internalSetUseShippingAsBilling?.(false)
}

function place(config: GiftCheckoutConfig): void {
  const fields = document.querySelector<HTMLElement>('#shipping-fields')
  if (!fields || fields.previousElementSibling?.classList.contains('galaxie-gift-notice')) return

  const notice = document.createElement('div')
  notice.className = 'galaxie-gift-notice'
  notice.textContent = config.notice
  fields.parentElement?.insertBefore(notice, fields)
}

export function bootGiftCheckout(config?: GiftCheckoutConfig): void {
  if (!config) return

  document.body.classList.add('galaxie-gift-checkout')

  let queued = false
  const run = () => {
    queued = false
    if (!blockCheckout()) return
    place(config)
    fill(config)
  }

  // The checkout is React: its steps are drawn, and redrawn, after load.
  new MutationObserver(() => {
    if (queued) return
    queued = true
    window.requestAnimationFrame(run)
  }).observe(document.body, { childList: true, subtree: true })

  run()
}
