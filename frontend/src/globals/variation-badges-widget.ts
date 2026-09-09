/**
 * Global behavior for the "Galaxie Variation Badges" Elementor widget
 * (Modules/VariationSwatches/Widget). That widget server-renders a styled
 * label + one badge (pixfort's own Text/Badge components) per option for a
 * chosen attribute, then renders the REAL WooCommerce variation form right
 * after it — WooCommerce's own `wc-add-to-cart-variation.js` only recognizes
 * a `<select>` inside `.variations`, so it can't live anywhere else.
 *
 * This script: hides that attribute's own row in the native table (avoiding
 * a duplicate "Peso" label + ugly dropdown), and wires our pre-rendered
 * badges to the real (still fully functional) `<select>` — same
 * set-value-and-dispatch-change trick as the plain swatches behavior, plus
 * keeping the `.is-selected` badge variant in sync with the select's value.
 */

function wireAttribute(picker: HTMLElement): void {
  const attribute = picker.dataset.galaxieAttribute
  if (!attribute) return

  // The widget always renders the native form as picker's next sibling.
  const nativeForm = picker.parentElement?.querySelector<HTMLFormElement>('form.variations_form')
  const select = nativeForm?.querySelector<HTMLSelectElement>(`.variations select[name="attribute_${attribute}"]`)
  if (!select) return

  const row = select.closest('tr')
  if (row) row.style.display = 'none'

  const buttons = Array.from(picker.querySelectorAll<HTMLButtonElement>('.galaxie-swatch-option'))

  const syncFromSelect = () => {
    buttons.forEach((button) => {
      const isSelected = button.dataset.value === select.value && select.value !== ''
      button.classList.toggle('is-selected', isSelected)
    })
  }

  const syncDisabled = () => {
    buttons.forEach((button) => {
      const option = Array.from(select.options).find((o) => o.value === button.dataset.value)
      button.disabled = !!option?.disabled
    })
  }

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      if (button.disabled || select.value === button.dataset.value) return
      select.value = button.dataset.value ?? ''
      select.dispatchEvent(new Event('change', { bubbles: true }))
      syncFromSelect()
    })
  })

  select.addEventListener('change', syncFromSelect)

  const observer = new MutationObserver(() => {
    syncDisabled()
  })
  observer.observe(select, { attributes: true, attributeFilter: ['disabled'], subtree: true })

  syncFromSelect()
  syncDisabled()
}

export function bootVariationBadgesWidget(): void {
  const run = () => {
    document.querySelectorAll<HTMLElement>('.galaxie-variation-picker[data-galaxie-attribute]').forEach(wireAttribute)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }
}
