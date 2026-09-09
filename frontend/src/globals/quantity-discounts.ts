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

import { findAlert } from '@/globals/buy-box-alert'

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

/**
 * Point the shopper at the choice the button is waiting on.
 *
 * The Buy Box already renders a configured Alert for exactly this case, so it
 * is reused when the page has one rather than inventing a second voice for the
 * same sentence. With no alert on the page, bringing the picker into view and
 * focusing it is the honest minimum — it says where to look without claiming
 * anything the merchant did not write.
 */
function askForVariation(form: HTMLFormElement): void {
  findAlert()?.show('select')

  const picker = form.querySelector<HTMLElement>('.galaxie-variation-picker, .variations')
  picker?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  picker?.querySelector<HTMLElement>('button, select')?.focus({ preventScroll: true })
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
      } else {
        // Cleared, not left stale: WooCommerce reads this attribute straight
        // off the button, and a leftover id from a previous selection would
        // add the wrong variation.
        button.removeAttribute('data-product_id')
      }

      // Only a real refusal is dressed as one. "You have not chosen a size
      // yet" is not a refusal — the button stays live and answers on click.
      // A tier above the chosen variation's stock genuinely cannot be bought,
      // and that one is disabled and says so through its own state.
      const overStock = variationId > 0 && !affordable
      button.classList.toggle('is-over-stock', overStock)
      button.toggleAttribute('disabled', overStock)
    })
  }

  // Runs on the button in the capture phase, so it lands before WooCommerce's
  // own delegated handler on document.body and can stop the request outright.
  buttons.forEach((button) => {
    button.addEventListener(
      'click',
      (event) => {
        if (button.dataset.product_id) return

        event.preventDefault()
        event.stopImmediatePropagation()
        askForVariation(form)
      },
      true,
    )
  })

  const jq = window.jQuery

  if (jq) {
    jq(form).on('found_variation', (_event: unknown, ...args: unknown[]) => {
      ceiling = ceilingFor(args[0] as FoundVariation | undefined)
      apply()
    })

    jq(form).on('reset_data', () => {
      ceiling = null
      applyLater()
    })
  }

  /*
   * Deferred on purpose.
   *
   * `variation_id` is written by WooCommerce while it handles the very change
   * event this listens to, so reading it from a capture-phase listener reads
   * the value from before the selection — measured on the page as
   * `change(capture)` at 0ms with the field still "0", and WooCommerce writing
   * 991438 two milliseconds later. Applying at that moment cleared the button
   * and nothing put it back.
   *
   * Waiting a turn makes the field the single source of truth and drops the
   * dependency on which script ran first, which is the part that was fragile:
   * the buttons follow the state, not the narrative that produced it.
   */
  const applyLater = () => window.setTimeout(apply, 0)

  form.addEventListener('change', applyLater, true)

  apply()
}

/**
 * A tier row that succeeded says so through the same Alert the Buy Box uses.
 *
 * WooCommerce hands the button it just handled as the third argument of its own
 * `added_to_cart`, which is the only way to tell one of our rows apart from any
 * other add-to-cart on the page — the event itself is global and fires for
 * every one of them.
 */
function announceAdds(): void {
  const jq = window.jQuery
  if (!jq) return

  jq(document.body).on('added_to_cart', (_event: unknown, ...args: unknown[]) => {
    const button = args[2] as { get?: (index: number) => HTMLElement | undefined } | undefined
    const node = button?.get?.(0)

    if (!node?.classList?.contains('galaxie-qd-add')) return

    const alert = findAlert()
    if (alert?.has('added')) alert.show('added')
  })
}

export function bootQuantityDiscounts(): void {
  const run = () => {
    document.querySelectorAll<HTMLElement>('.galaxie-qd').forEach(initBlock)
    announceAdds()
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }
}
