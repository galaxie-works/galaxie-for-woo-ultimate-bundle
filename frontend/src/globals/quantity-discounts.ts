/**
 * Behaviour for the "Galaxie Quantity Discounts" widget
 * (Modules/QuantityDiscounts/Widget).
 *
 * The table itself is server-rendered and needs no JS. This exists for one
 * thing: on a variable product there is nothing to add to the cart until the
 * shopper picks a variation, so each row's Add button ships disabled and
 * without a product id, and this fills both in from whatever they select.
 *
 * The click itself is WooCommerce's. The buttons carry `.add_to_cart_button`
 * and `.ajax_add_to_cart`, so its own `wc-add-to-cart` script posts them,
 * refreshes the cart fragments and fires `added_to_cart` — which is also what
 * makes the theme's mini-cart update without us knowing anything about it.
 */

interface FoundVariation {
  variation_id?: number
  max_qty?: number | string
  is_in_stock?: boolean
}

/**
 * The form holding the attribute selects. Our own Buy Box is preferred over the
 * native one because a page carrying both has the native form hidden, and the
 * hidden one is not the one the shopper is choosing from.
 */
function variationForm(): HTMLFormElement | null {
  return (
    document.querySelector<HTMLFormElement>('form.galaxie-buybox') ??
    document.querySelector<HTMLFormElement>('form.variations_form') ??
    document.querySelector<HTMLFormElement>('form.cart')
  )
}

/**
 * How many units of this variation may be bought, or null for "no ceiling".
 *
 * WooCommerce sends `max_qty` as '' when no ceiling exists, which is what it
 * reports whenever stock is not being counted — Manage stock off, or backorders
 * allowed. Its own `get_max_purchase_quantity()` produces -1 in those cases
 * before that translation, and the filter behind it (`woocommerce_quantity_
 * input_max`) is public, so a third party can put any number there. Anything at
 * or below zero is therefore read as "no ceiling" rather than trusted as a real
 * limit — trusting a -1 would disable every row on a product with unlimited
 * stock, which is the exact opposite of what it means.
 */
function ceilingFor(variation: FoundVariation | undefined): number | null {
  if (variation?.is_in_stock === false) return 0

  const max = Number(variation?.max_qty)

  return Number.isFinite(max) && max > 0 ? max : null
}

function initBlock(block: HTMLElement): void {
  const buttons = Array.from(
    block.querySelectorAll<HTMLButtonElement>('.galaxie-qd-add[data-galaxie-qd-needs-variation]'),
  )
  if (!buttons.length) return

  const form = variationForm()
  if (!form) return

  const field = form.querySelector<HTMLInputElement>('input[name="variation_id"]')

  // null means no ceiling; 0 means nothing can be bought at all.
  let ceiling: number | null = null

  const apply = () => {
    const variationId = Number(field?.value) || 0

    buttons.forEach((button) => {
      const quantity = Number(button.dataset.galaxieQdQty) || 0
      const affordable = ceiling === null || quantity <= ceiling
      const usable = variationId > 0 && affordable

      if (usable) {
        button.dataset.product_id = String(variationId)
        button.removeAttribute('disabled')
      } else {
        // Cleared, not left stale: WooCommerce reads this attribute straight
        // off the button, and a leftover id from a previous selection would
        // add the wrong variation.
        button.removeAttribute('data-product_id')
        button.setAttribute('disabled', 'disabled')
      }

      button.classList.toggle('is-over-stock', variationId > 0 && !affordable)
    })
  }

  const jq = window.jQuery

  if (jq) {
    jq(form).on('found_variation', (_event: unknown, ...args: unknown[]) => {
      ceiling = ceilingFor(args[0] as FoundVariation | undefined)
      apply()
    })

    jq(form).on('reset_data', () => {
      ceiling = null
      apply()
    })
  }

  // Also on plain change, so the buttons still track the selection when
  // WooCommerce's variation script is absent — the value of the hidden field is
  // the only thing this really depends on.
  form.addEventListener('change', apply, true)

  apply()
}

export function bootQuantityDiscounts(): void {
  const run = () => document.querySelectorAll<HTMLElement>('.galaxie-qd').forEach(initBlock)

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }
}
