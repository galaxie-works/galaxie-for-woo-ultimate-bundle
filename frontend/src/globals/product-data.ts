/**
 * Makes the Galaxie product dynamic tags follow the chosen variation.
 *
 * A dynamic tag renders on the server, once, before anyone has chosen
 * anything. On a variable product that is the whole problem: weight and
 * dimensions are stored on the VARIATION and the parent has none, so the tag
 * renders empty; and an attribute used for variations renders every value at
 * once ("190g, 50g") rather than the one that was picked.
 *
 * So each tag prints a `<span class="galaxie-dyn" data-galaxie-dyn="...">` and
 * this rewrites it. The values come from WooCommerce's own variation payload,
 * which already carries `weight_html`, `dimensions_html`, `dimensions` and
 * `attributes` — no second source of truth, and no request.
 */

interface VariationPayload {
  sku?: string
  weight?: string
  weight_html?: string
  dimensions_html?: string
  dimensions?: { length?: string; width?: string; height?: string }
  attributes?: Record<string, string>
}

type Jq = ((el: unknown) => { on: (events: string, handler: (...args: unknown[]) => void) => void }) | undefined

function jquery(): Jq {
  return (window as unknown as { jQuery?: Jq }).jQuery
}

/**
 * The server-rendered text, kept so `reset_data` can put it back.
 *
 * Read lazily on first change rather than at boot: Elementor and the theme
 * both move nodes around after load, and a value captured too early can belong
 * to a span that is no longer the one on screen.
 */
const ORIGINAL = new WeakMap<HTMLElement, string>()

function original(el: HTMLElement): string {
  const seen = ORIGINAL.get(el)
  if (seen !== undefined) return seen

  const text = el.textContent ?? ''
  ORIGINAL.set(el, text)
  return text
}

function withUnit(value: string, unit: string): string {
  if (!value) return ''
  return unit ? `${value} ${unit}` : value
}

function valueFor(el: HTMLElement, variation: VariationPayload): string | null {
  const kind = el.dataset.galaxieDyn
  const unit = el.dataset.galaxieUnit ?? ''

  if (kind === 'weight') {
    // `weight_html` already carries the unit and WooCommerce's own formatting,
    // so it is preferred whenever the merchant asked for a unit at all.
    if (unit && variation.weight_html) return variation.weight_html
    return variation.weight ? withUnit(variation.weight, unit) : ''
  }

  if (kind === 'dimension') {
    const which = el.dataset.galaxieDimension ?? 'formatted'

    if (which === 'formatted') {
      // Built from the raw sides rather than from `dimensions_html`, which
      // joins with the HTML entity `&times;` — and this is written through
      // textContent, so an entity would appear on screen as "5 &times; 5".
      const d = variation.dimensions
      if (!d) return ''

      const sides = [d.length, d.width, d.height].filter((side): side is string => !!side)
      return sides.length ? withUnit(sides.join(' × '), unit) : ''
    }

    const side = variation.dimensions?.[which as 'length' | 'width' | 'height']
    return side ? withUnit(side, unit) : ''
  }

  if (kind === 'sku') {
    return variation.sku ?? ''
  }

  if (kind === 'attribute') {
    const taxonomy = el.dataset.galaxieAttribute
    if (!taxonomy) return null

    // The payload keys attributes by the form field name, not the taxonomy.
    const picked = variation.attributes?.[`attribute_${taxonomy}`]

    // An empty string here means "any value" — this variation does not pin the
    // attribute down, so the parent's full list is still the honest answer.
    if (!picked) return null

    return (el.dataset.galaxiePrefix ?? '') + picked
  }

  return null
}

function scopeOf(el: HTMLElement): HTMLElement {
  return (el.closest('form.cart') as HTMLElement | null) ?? document.body
}

export function bootProductData(): void {
  const spans = Array.from(document.querySelectorAll<HTMLElement>('.galaxie-dyn[data-galaxie-dyn]'))
  if (!spans.length) return

  const jq = jquery()
  if (!jq) return

  // One binding per form, however many spans read from it. The spans are often
  // nowhere near the form — a spec table further down the page — so the form is
  // found once and everything on the page listens to it.
  const forms = new Set<HTMLElement>()
  spans.forEach((el) => forms.add(scopeOf(el)))

  const anyForm = document.querySelector<HTMLElement>('form.cart.variations_form')
  const target = forms.size === 1 ? Array.from(forms)[0] : (anyForm ?? document.body)

  // The event object comes FIRST and has to be named, or `args[0]` is the
  // Event and every field read off it is undefined. That failure is quiet and
  // asymmetric, which is what made it readable from the page: weight,
  // dimensions and SKU went blank (an Event has no `weight`), while the
  // attribute row kept its old text (no `attributes` either, so the code
  // correctly decided it had nothing to say).
  jq(target).on('found_variation', (_event: unknown, ...args: unknown[]) => {
    const variation = args[0] as VariationPayload | undefined
    if (!variation) return

    spans.forEach((el) => {
      original(el)
      const next = valueFor(el, variation)
      if (next !== null) el.textContent = next
    })
  })

  jq(target).on('reset_data', () => {
    spans.forEach((el) => {
      const text = ORIGINAL.get(el)
      if (text !== undefined) el.textContent = text
    })
  })
}
