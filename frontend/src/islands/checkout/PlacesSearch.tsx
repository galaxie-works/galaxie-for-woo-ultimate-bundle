import * as React from 'react'

import { attachPlaces, whenPlacesReady, type PlacedAddress, type PlacesConfig } from '@/globals/address-autocomplete'
import { CoField, useFieldClass } from '@/lib/pix'

/** The Address Autocomplete module's boot data; absent when the module is off or has no key. */
function placesConfig(): PlacesConfig | null {
  return (window as unknown as { __GALAXIE_WOO__?: { addressAutocomplete?: PlacesConfig } }).__GALAXIE_WOO__?.addressAutocomplete ?? null
}

interface PlacesSearchProps {
  label: string
  /** A picked address, read into address fields. */
  onPlace: (address: PlacedAddress) => void
}

/**
 * The Google Places search the cart's shipping calculator has, above the
 * checkout's address form: the same module, the same key, the same reading
 * of the answer (address-autocomplete.ts). It only fills the form below —
 * the shopper still sees and can correct every field, and WooCommerce still
 * prices the shipping.
 *
 * A search box of its own, not Places on the street field: Places rewrites
 * what its field shows as the shopper types, and the street field would end
 * up holding a half-typed search. Nothing is drawn when the module is off.
 */
function PlacesSearch({ label, onPlace }: PlacesSearchProps) {
  const field = useFieldClass()
  const ref = React.useRef<HTMLInputElement>(null)
  const id = React.useId()
  const config = placesConfig()

  // The latest handler, so the listener attached once keeps writing into the
  // form's current state.
  const handler = React.useRef(onPlace)
  handler.current = onPlace

  React.useEffect(() => {
    const input = ref.current
    if (!config || !input) return
    return whenPlacesReady(() => {
      attachPlaces(input, config, (address) => handler.current(address))
    })
    // `config` is page data, fixed for the page's life.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (!config) return null

  return (
    <CoField label={label} htmlFor={id}>
      <input
        ref={ref}
        id={id}
        type="text"
        autoComplete="off"
        placeholder={config.placeholder}
        className={`${field} galaxie-places-input`}
        // Enter picks a suggestion in Places; it must not submit the form.
        onKeyDown={(e) => {
          if ('Enter' === e.key) e.preventDefault()
        }}
      />
    </CoField>
  )
}

export { PlacesSearch }
