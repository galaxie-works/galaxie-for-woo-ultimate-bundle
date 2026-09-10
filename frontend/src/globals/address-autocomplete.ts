/**
 * Google Places address search, for the cart's shipping calculator and for
 * checkout.
 *
 * Ported from eir-my-account-ux (`checkout-auth.js` initPlacesAutocomplete and
 * `cart-shipping.js`), which is the third place this pattern was about to be
 * written and the reason it now lives once.
 *
 * Nothing here validates an address. Places fills the fields; WooCommerce still
 * decides what the shipping costs, and a shopper can always type over it.
 */

interface PlacesConfig {
  country: string
  placeholder: string
}

interface AddressComponent {
  types: string[]
  short_name?: string
  long_name?: string
}

interface Place {
  address_components?: AddressComponent[]
}

interface PlacesAutocomplete {
  addListener: (event: string, handler: () => void) => void
  getPlace: () => Place
}

interface MapsGlobal {
  maps?: {
    places?: {
      Autocomplete?: new (input: HTMLInputElement, options: unknown) => PlacesAutocomplete
    }
  }
}

const MOUNTED = 'data-galaxie-places'

function pick(place: Place, type: string): string {
  const found = (place.address_components ?? []).find((c) => c.types.includes(type))
  return found ? (found.short_name ?? found.long_name ?? '') : ''
}

function set(field: HTMLInputElement | HTMLSelectElement | null, value: string): void {
  if (!field || value === '') return

  field.value = value

  // WooCommerce listens on jQuery for these, and its own country/state selects
  // are select2 widgets that only redraw when told this way. A native event
  // would set the value and leave the visible control showing the old one.
  const $ = (window as unknown as { jQuery?: (el: unknown) => { trigger: (e: string) => void } }).jQuery
  if ($) $(field).trigger('change')
}

function attach(input: HTMLInputElement, scope: ParentNode, config: PlacesConfig): void {
  const google = (window as unknown as { google?: MapsGlobal }).google
  const Autocomplete = google?.maps?.places?.Autocomplete

  if (!Autocomplete) return

  const autocomplete = new Autocomplete(input, {
    fields: ['address_components'],
    componentRestrictions: { country: config.country.toLowerCase() },
    types: ['address'],
  })

  autocomplete.addListener('place_changed', () => {
    const place = autocomplete.getPlace()
    if (!place.address_components) return

    const number = pick(place, 'street_number')
    const route = pick(place, 'route')
    const neighbourhood =
      pick(place, 'sublocality_level_1') || pick(place, 'sublocality') || pick(place, 'neighborhood')
    const city = pick(place, 'administrative_area_level_2') || pick(place, 'locality')
    const state = pick(place, 'administrative_area_level_1')
    const postcode = pick(place, 'postal_code')

    const q = <T extends HTMLElement>(selector: string): T | null => scope.querySelector<T>(selector)

    // The calculator's fields and checkout's are different ids for the same
    // five answers, so both are filled and whichever exists wins.
    set(q<HTMLInputElement>('#calc_shipping_postcode'), postcode)
    set(q<HTMLInputElement>('#calc_shipping_city'), city)
    set(q<HTMLSelectElement>('#calc_shipping_state'), state.toUpperCase().slice(0, 2))

    set(q<HTMLInputElement>('#billing_postcode, #shipping_postcode'), postcode)
    set(q<HTMLInputElement>('#billing_city, #shipping_city'), city)
    set(q<HTMLSelectElement>('#billing_state, #shipping_state'), state.toUpperCase().slice(0, 2))
    set(
      q<HTMLInputElement>('#billing_address_1, #shipping_address_1'),
      [route, number].filter(Boolean).join(', ')
    )
    set(q<HTMLInputElement>('#billing_address_2, #shipping_address_2'), neighbourhood)
  })
}

/**
 * The search box, above whichever address field is on this page.
 *
 * Injected rather than reusing an existing input: Places rewrites what a field
 * shows as the shopper types, and doing that to the postcode field means a
 * half-typed street name sitting in a box labelled CEP.
 */
function mount(anchor: HTMLElement, scope: ParentNode, config: PlacesConfig): void {
  if (anchor.hasAttribute(MOUNTED)) return
  anchor.setAttribute(MOUNTED, '1')

  const wrap = document.createElement('div')
  wrap.className = 'form-row form-row-wide galaxie-places-row'

  const input = document.createElement('input')
  input.type = 'text'
  input.className = 'input-text galaxie-places-input'
  input.autocomplete = 'off'
  input.placeholder = config.placeholder

  wrap.appendChild(input)
  anchor.parentNode?.insertBefore(wrap, anchor)

  attach(input, scope, config)
}

function mountAll(config: PlacesConfig): void {
  const calculator = document.querySelector<HTMLElement>('.shipping-calculator-form')
  const calcAnchor = calculator?.querySelector<HTMLElement>('#calc_shipping_postcode_field, .form-row')

  if (calculator && calcAnchor) {
    mount(calcAnchor, calculator, config)
  }

  const checkout = document.querySelector<HTMLElement>('.woocommerce-billing-fields__field-wrapper')
  const checkoutAnchor = checkout?.querySelector<HTMLElement>('#billing_address_1_field, .form-row')

  if (checkout && checkoutAnchor) {
    mount(checkoutAnchor, document, config)
  }
}

export function bootAddressAutocomplete(config?: PlacesConfig): void {
  if (!config) return

  const ready = (): boolean =>
    Boolean((window as unknown as { google?: MapsGlobal }).google?.maps?.places?.Autocomplete)

  const run = (): void => mountAll(config)

  // The Maps script loads with `loading=async`, so `google.maps` may not exist
  // the instant this runs even though it is declared as a dependency. Polling
  // briefly is what the original did, and assuming otherwise is what broke it.
  if (ready()) {
    run()
  } else {
    const wait = window.setInterval(() => {
      if (!ready()) return
      window.clearInterval(wait)
      run()
    }, 200)

    window.setTimeout(() => window.clearInterval(wait), 10000)
  }

  // WooCommerce redraws the calculator and the checkout fields on every update,
  // taking the search box with them. Re-mounting is idempotent — the anchor
  // carries a flag — so this can simply run again.
  const $ = (window as unknown as { jQuery?: (el: unknown) => { on: (e: string, h: () => void) => void } })
    .jQuery

  if ($) {
    $(document.body).on('updated_wc_div updated_cart_totals updated_checkout', () => {
      if (ready()) run()
    })
  }
}
