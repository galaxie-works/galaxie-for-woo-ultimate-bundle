/**
 * The block checkout for a gift from a shared wishlist.
 *
 * The parcel goes to the list owner's saved address, which this page never
 * receives. The shipping step is replaced by a line saying who it is for and
 * where (city/state), and the shipping address the checkout holds is filled
 * with that area plus placeholders — the checkout needs something valid to
 * submit. The server writes the real address onto the order and keeps these
 * placeholders off the buyer's account (see PHP Modules\Wishlist\Gifts).
 *
 * Billing is always the buyer's own, so "use shipping as billing" is turned off.
 */

export interface GiftCheckoutConfig {
  firstName: string
  city: string
  state: string
  postcode: string
  country: string
  notice: string
  placeholder: string
}

interface WpData {
  select: (store: string) => { getCustomerData?: () => { shippingAddress?: Record<string, string> } } | undefined
  dispatch: (store: string) => Record<string, ((value: unknown) => void) | undefined> | undefined
}

function wpData(): WpData | undefined {
  return (window as unknown as { wp?: { data?: WpData } }).wp?.data
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
