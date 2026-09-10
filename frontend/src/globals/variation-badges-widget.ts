/**
 * Global behavior for the "Galaxie Variation Badges" widget
 * (Modules/VariationSwatches/Widget) — a full pixfort-styled buy box: styled
 * label + badges per option, a quantity input, and Add to Cart / Buy Now
 * buttons (each rendered through pixfort's own Button component).
 *
 * The widget also renders the REAL WooCommerce variation form (hidden via
 * CSS) alongside its own UI — WooCommerce's own `wc-add-to-cart-variation.js`
 * only recognizes a `<select>` inside `.variations`, so that can't be moved
 * out; it stays as the source of truth for price/stock. This script:
 *  - hides the attribute's own row in that native table (avoiding a
 *    duplicate label+dropdown) and drives its still-functional `<select>`
 *    from our badges, keeping `.is-selected` in sync (same trick as the
 *    plain automatic swatches behavior);
 *  - reads the resulting `variation_id` (a hidden input WooCommerce's own
 *    JS keeps updated inside the same form) and the visible quantity input,
 *    and posts them to our own AJAX endpoint when Add to Cart / Buy Now is
 *    clicked — Buy Now redirects to checkout on success.
 */

interface BuyBoxConfig {
  ajaxUrl: string
  nonce: string
}

interface AddToCartResponse {
  success: boolean
  data?: { message?: string; fragments?: Record<string, string>; cart_hash?: string; checkout_url?: string }
}

function postAddToCart(config: BuyBoxConfig, variationId: number, quantity: number): Promise<AddToCartResponse> {
  const body = new URLSearchParams({
    action: 'galaxie_variation_add_to_cart',
    nonce: config.nonce,
    variation_id: String(variationId),
    quantity: String(quantity),
  })
  return fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body }).then(
    (response) => response.json() as Promise<AddToCartResponse>
  )
}

function initWidget(picker: HTMLElement, config?: BuyBoxConfig): void {
  // A picker inside a Galaxie Buy Box belongs to that widget, which owns its
  // own form and wires its own handlers (globals/buy-box.ts). Enhancing it here
  // too would attach a second, conflicting set to the same <select>.
  if (picker.closest('.galaxie-buybox')) return

  // Elementor always wraps a single widget's entire render() output in its
  // own dedicated `.elementor-widget-container` — scope to that explicitly
  // (rather than assuming `picker.parentElement` is it) so this never
  // accidentally reaches into a sibling widget's own variation form when
  // more than one sits in the same section/column.
  const container = picker.closest<HTMLElement>('.elementor-widget-container') ?? picker.parentElement
  if (!container) return

  const attribute = picker.dataset.galaxieAttribute
  const nativeForm = container.querySelector<HTMLFormElement>('form.variations_form')
  const select = attribute
    ? nativeForm?.querySelector<HTMLSelectElement>(`.variations select[name="attribute_${attribute}"]`)
    : null

  if (select) {
    const row = select.closest('tr')
    if (row) row.style.display = 'none'

    const buttons = Array.from(picker.querySelectorAll<HTMLButtonElement>('.galaxie-swatch-option'))

    const syncFromSelect = () => {
      buttons.forEach((button) => {
        button.classList.toggle('is-selected', button.dataset.value === select.value && select.value !== '')
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
    new MutationObserver(syncDisabled).observe(select, { attributes: true, attributeFilter: ['disabled'], subtree: true })

    syncFromSelect()
    syncDisabled()
  }

  if (nativeForm) {
    const priceEl = container.querySelector<HTMLElement>('.galaxie-buybox-price')
    const stockEl = container.querySelector<HTMLElement>('.galaxie-buybox-stock')
    const jq = window.jQuery

    if (jq && (priceEl || stockEl)) {
      const initialPriceHtml = priceEl?.innerHTML ?? ''
      const initialStockHtml = stockEl?.innerHTML ?? ''

      // Mirrored straight from the DOM WooCommerce itself renders/updates
      // inside `.single_variation_wrap` (standard classes from
      // single-variation.php), rather than trusting the exact shape of the
      // `found_variation` event payload — more robust across WC versions.
      const syncFromNativeBlock = () => {
        const nativePrice = nativeForm.querySelector<HTMLElement>('.woocommerce-variation-price')
        const nativeStock = nativeForm.querySelector<HTMLElement>('.woocommerce-variation-availability')
        if (priceEl) priceEl.innerHTML = nativePrice ? nativePrice.innerHTML : initialPriceHtml
        if (stockEl) stockEl.innerHTML = nativeStock ? nativeStock.innerHTML : initialStockHtml
      }

      jq(nativeForm).on('show_variation found_variation', syncFromNativeBlock)
      jq(nativeForm).on('hide_variation reset_data', () => {
        if (priceEl) priceEl.innerHTML = initialPriceHtml
        if (stockEl) stockEl.innerHTML = initialStockHtml
      })
    }
  }

  if (!config || !nativeForm) return

  const quantityInput = container.querySelector<HTMLInputElement | HTMLSelectElement>('.galaxie-buybox-quantity .qty')
  const addCartButton = container.querySelector<HTMLButtonElement>('.galaxie-buybox-addcart')
  const buyNowButton = container.querySelector<HTMLButtonElement>('.galaxie-buybox-buynow')

  const currentVariationId = (): number => {
    const hidden = nativeForm.querySelector<HTMLInputElement>('input[name="variation_id"]')
    return hidden ? Number(hidden.value) || 0 : 0
  }
  const currentQuantity = (): number => (quantityInput ? Number(quantityInput.value) || 1 : 1)

  const runAddToCart = (button: HTMLButtonElement, onSuccess: (json: AddToCartResponse) => void) => {
    const variationId = currentVariationId()
    if (!variationId) {
      window.alert('Selecione uma variação antes de continuar.')
      return
    }
    button.disabled = true
    postAddToCart(config, variationId, currentQuantity())
      .then((json) => {
        button.disabled = false
        if (json.success) {
          onSuccess(json)
        } else {
          window.alert(json.data?.message ?? 'Não foi possível adicionar ao carrinho.')
        }
      })
      .catch(() => {
        button.disabled = false
      })
  }

  addCartButton?.addEventListener('click', () => {
    runAddToCart(addCartButton, (json) => {
      const jq = window.jQuery
      if (jq && json.data) {
        jq(document.body).trigger('added_to_cart', [json.data.fragments, json.data.cart_hash, jq(addCartButton)])
      }
    })
  })

  // Buy Now goes through WooCommerce's OWN form submit rather than our AJAX
  // endpoint: every native validation, stock check and third-party add-to-cart
  // hook still runs, and the server-side `woocommerce_add_to_cart_redirect`
  // filter sends the shopper to checkout. It navigates away regardless, so
  // there's nothing AJAX would buy us here — only compatibility to lose.
  buyNowButton?.addEventListener('click', () => {
    const nativeSubmit = nativeForm.querySelector<HTMLButtonElement>('.single_add_to_cart_button')
    const buyNowField = nativeForm.querySelector<HTMLInputElement>('input[name="galaxie_buy_now"]')
    if (!nativeSubmit || nativeSubmit.classList.contains('disabled') || nativeSubmit.disabled) {
      window.alert('Selecione uma variação antes de continuar.')
      return
    }

    // Our visible quantity field lives outside the form, so copy it in first.
    const nativeQty = nativeForm.querySelector<HTMLInputElement>('input.qty, select.qty')
    if (nativeQty) nativeQty.value = String(currentQuantity())

    if (buyNowField) buyNowField.value = '1'
    nativeSubmit.click()
    // Clear it again so a failed submit can't leave a stale flag behind that
    // would later redirect an ordinary add-to-cart straight to checkout.
    window.setTimeout(() => {
      if (buyNowField) buyNowField.value = ''
    }, 0)
  })
}

export function bootVariationBadgesWidget(config?: BuyBoxConfig): void {
  const run = () => {
    document
      .querySelectorAll<HTMLElement>('.galaxie-variation-picker[data-galaxie-attribute]')
      .forEach((picker) => initWidget(picker, config))
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }
}
