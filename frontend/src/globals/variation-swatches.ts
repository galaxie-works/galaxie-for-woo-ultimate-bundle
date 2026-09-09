/**
 * Global behavior (not an island): turns WooCommerce's native variation
 * `<select>` dropdowns into clickable badges, for the attribute slugs listed
 * in module settings (default `pa_peso`). Boots only when the PHP side sets
 * the `variationSwatches` flag.
 *
 * The native `<select>` stays in the DOM as the source of truth — we only
 * hide it visually. WooCommerce's own `wc-add-to-cart-variation.js` reads and
 * writes that select directly (value, `disabled` on `<option>`s as valid
 * combinations narrow) and recalculates price/stock/image from it. Badges
 * only ever set `select.value` and dispatch a bubbling `change` event, so
 * every bit of native and theme variation logic keeps working untouched.
 */

const WRAPPER_CLASS = 'galaxie-swatch-wrapper'
const BADGE_CLASS = 'galaxie-swatch-badge'

interface VariationSwatchesConfig {
  attributes?: string[]
}

function buildBadges(select: HTMLSelectElement): HTMLDivElement {
  const wrapper = document.createElement('div')
  wrapper.className = `galaxie-ui ${WRAPPER_CLASS}`
  wrapper.setAttribute('role', 'group')

  const render = () => {
    wrapper.innerHTML = ''
    Array.from(select.options).forEach((option) => {
      if (!option.value) return // skip the placeholder ("Choose an option")

      const badge = document.createElement('button')
      badge.type = 'button'
      badge.className = BADGE_CLASS
      badge.textContent = option.text
      badge.disabled = option.disabled
      badge.setAttribute('aria-pressed', String(option.selected))
      if (option.selected) badge.classList.add('is-selected')

      badge.addEventListener('click', () => {
        if (select.value === option.value) return
        select.value = option.value
        select.dispatchEvent(new Event('change', { bubbles: true }))
      })

      wrapper.appendChild(badge)
    })
  }

  render()

  // WooCommerce toggles `disabled` on <option>s (and can reset `.value`) as
  // the customer picks other attributes, narrowing valid combinations — an
  // attribute-level MutationObserver keeps the badges in sync with that.
  const observer = new MutationObserver(render)
  observer.observe(select, { attributes: true, attributeFilter: ['disabled'], subtree: true })
  select.addEventListener('change', render)

  return wrapper
}

function enhance(select: HTMLSelectElement): void {
  if (select.dataset.galaxieSwatchesDone) return
  select.dataset.galaxieSwatchesDone = '1'

  const wrapper = buildBadges(select)
  select.insertAdjacentElement('afterend', wrapper)
  select.style.display = 'none'
}

function scan(root: ParentNode, attributes: string[]): void {
  attributes.forEach((slug) => {
    root
      .querySelectorAll<HTMLSelectElement>(`.variations select[name="attribute_${slug}"]`)
      .forEach(enhance)
  })
}

export function bootVariationSwatches(config: VariationSwatchesConfig): void {
  const attributes = config.attributes ?? []
  if (attributes.length === 0) return

  const run = () => scan(document, attributes)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }

  // Variable-product forms can be swapped in via AJAX (quick view, related
  // products) after the initial load.
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      m.addedNodes.forEach((node) => {
        if (node instanceof HTMLElement) scan(node, attributes)
      })
    })
  })
  observer.observe(document.body, { childList: true, subtree: true })
}
