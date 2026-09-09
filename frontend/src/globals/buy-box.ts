/**
 * Behavior for the "Galaxie Buy Box" widget (Modules/VariationSwatches/Widget).
 *
 * The widget renders its OWN `form.cart.variations_form`, so unlike the
 * deprecated variation-badges widget there is no second native form to mirror
 * or hide. WooCommerce's own `wc-add-to-cart-variation.js` still runs on that
 * form — the real attribute `<select>`s are in it, just visually hidden — so
 * variation matching, option narrowing and every `found_variation` /
 * `reset_data` listener third-party plugins register keep working untouched.
 *
 * This script only does the three things WooCommerce cannot know about:
 * drive those selects from our badges, paint price and stock into our own
 * pixfort-styled blocks, and upgrade Add to Cart to AJAX.
 */

interface BuyBoxConfig {
  ajaxUrl: string
  nonce: string
}

interface AddToCartResponse {
  success: boolean
  data?: { message?: string; fragments?: Record<string, string>; cart_hash?: string }
}

interface VariationPayload {
  price_html?: string
  availability_html?: string
}

/**
 * pixfort's components wrap content in their own markup, and that markup is
 * where size/weight/colour live — so replacing a block's whole innerHTML on
 * every variation change would strip the styling the merchant configured.
 * Swap the content inside the wrapper instead.
 *
 * Both shapes have to be recognised. The sale price renders through Text or
 * through Badge depending on its display setting, and Badge's markup carries
 * no `.pix-el-text` and no paragraph — so matching only Text meant the badge
 * fell through to the block itself and got overwritten with a bare price.
 */
function contentTarget(block: HTMLElement): HTMLElement {
  return (
    block.querySelector<HTMLElement>('.pix-el-text p') ??
    block.querySelector<HTMLElement>('.pix-badge-element .badge > span') ??
    block.querySelector<HTMLElement>('.pix-el-text, p') ??
    block
  )
}

function initSwatches(form: HTMLFormElement): void {
  form.querySelectorAll<HTMLElement>('.galaxie-variation-picker[data-galaxie-attribute]').forEach((picker) => {
    const attribute = picker.dataset.galaxieAttribute
    const select = form.querySelector<HTMLSelectElement>(`.variations select[name="attribute_${attribute}"]`)
    if (!select) return

    const buttons = Array.from(picker.querySelectorAll<HTMLButtonElement>('.galaxie-swatch-option'))

    const syncFromSelect = () => {
      buttons.forEach((button) => {
        button.classList.toggle('is-selected', button.dataset.value === select.value && select.value !== '')
      })
    }

    // WooCommerce disables the <option>s that no longer lead to a purchasable
    // combination as other attributes are chosen; the badges follow that.
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
  })
}

/**
 * WooCommerce hands us the matched variation's price as ready-made markup —
 * `<del>old</del> <ins>new</ins>` when it's on sale, a bare amount otherwise.
 * Splitting that into our two slots is what lets the merchant's regular and
 * sale styling apply to a VARIABLE product: server-side there is no single
 * price to style until a variation is picked.
 */
function applyPrice(regular: HTMLElement | null, sale: HTMLElement | null, html: string): void {
  const parsed = document.createElement('div')
  parsed.innerHTML = html

  // Unwrap WooCommerce's own `.price` container in BOTH branches. It carries
  // theme font sizing, so keeping it for a plain price while dropping it for a
  // sale price (whose amounts sit inside <del>/<ins>) made the same configured
  // size render differently depending on whether the variation was on sale.
  const scope = parsed.querySelector('.price') ?? parsed
  const struck = scope.querySelector('del')
  const current = scope.querySelector('ins')

  const regularHtml = struck ? struck.innerHTML : scope.innerHTML
  const saleHtml = struck && current ? current.innerHTML : ''

  if (regular) {
    contentTarget(regular).innerHTML = regularHtml
    regular.classList.toggle('is-struck', saleHtml !== '')
  }
  if (sale) {
    contentTarget(sale).innerHTML = saleHtml
    sale.hidden = saleHtml === ''
  }
}

function initPriceAndStock(form: HTMLFormElement): void {
  const regularSlot = form.querySelector<HTMLElement>('.galaxie-buybox-price-regular')
  const saleSlot = form.querySelector<HTMLElement>('.galaxie-buybox-price-sale')
  const stockBlock = form.querySelector<HTMLElement>('.galaxie-buybox-stock')
  const jq = window.jQuery
  if (!jq || (!regularSlot && !stockBlock)) return

  const stockTarget = stockBlock ? contentTarget(stockBlock) : null
  const initialRegular = regularSlot ? contentTarget(regularSlot).innerHTML : ''
  const initialStruck = !!regularSlot?.classList.contains('is-struck')
  const initialSale = saleSlot ? contentTarget(saleSlot).innerHTML : ''
  const initialSaleHidden = !!saleSlot?.hidden
  const initialStock = stockTarget?.innerHTML ?? ''

  // Read off the event payload rather than WooCommerce's own price markup:
  // that markup is exactly what this widget stopped rendering.
  jq(form).on('found_variation', (_event: unknown, ...args: unknown[]) => {
    const variation = args[0] as VariationPayload | undefined
    if (variation?.price_html) applyPrice(regularSlot, saleSlot, variation.price_html)
    if (stockTarget) stockTarget.innerHTML = variation?.availability_html || initialStock
  })

  jq(form).on('reset_data', () => {
    if (regularSlot) {
      contentTarget(regularSlot).innerHTML = initialRegular
      regularSlot.classList.toggle('is-struck', initialStruck)
    }
    if (saleSlot) {
      contentTarget(saleSlot).innerHTML = initialSale
      saleSlot.hidden = initialSaleHidden
    }
    if (stockTarget) stockTarget.innerHTML = initialStock
  })
}

function currentQuantity(form: HTMLFormElement): number {
  const field = form.querySelector<HTMLInputElement | HTMLSelectElement>('.galaxie-buybox-quantity .qty')
  return field ? Number(field.value) || 1 : 1
}

function initButtons(form: HTMLFormElement, config?: BuyBoxConfig): void {
  const addCart = form.querySelector<HTMLButtonElement>('.galaxie-buybox-addcart')
  const buyNow = form.querySelector<HTMLButtonElement>('.galaxie-buybox-buynow')
  const variationField = form.querySelector<HTMLInputElement>('input[name="variation_id"]')

  // Buy Now navigates away regardless, so it stays a plain native submit —
  // every WooCommerce validation, stock check and third-party add-to-cart hook
  // still runs, and the server-side redirect filter reads this flag. The field
  // is cleared afterwards so a failed submit can't leave it set and send a
  // later ordinary add-to-cart straight to checkout.
  buyNow?.addEventListener('click', () => {
    const flag = form.querySelector<HTMLInputElement>('input[name="galaxie_buy_now"]')
    if (flag) {
      flag.value = '1'
      window.setTimeout(() => {
        flag.value = ''
      }, 0)
    }
  })

  addCart?.addEventListener('click', (event) => {
    // No config, or a product with no variation to identify: fall through to
    // the native submit, which is a complete working path on its own.
    if (!config || !variationField) return

    const variationId = Number(variationField.value) || 0
    if (!variationId) return

    event.preventDefault()
    addCart.disabled = true

    const body = new URLSearchParams({
      action: 'galaxie_variation_add_to_cart',
      nonce: config.nonce,
      variation_id: String(variationId),
      quantity: String(currentQuantity(form)),
    })

    fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
      .then((response) => response.json() as Promise<AddToCartResponse>)
      .then((json) => {
        addCart.disabled = false
        if (!json.success) {
          window.alert(json.data?.message ?? 'Não foi possível adicionar ao carrinho.')
          return
        }
        const jq = window.jQuery
        if (jq && json.data) {
          jq(document.body).trigger('added_to_cart', [json.data.fragments, json.data.cart_hash, jq(addCart)])
        }
      })
      .catch(() => {
        addCart.disabled = false
      })
  })
}

export function bootBuyBox(config?: BuyBoxConfig): void {
  const run = () => {
    document.querySelectorAll<HTMLFormElement>('form.galaxie-buybox').forEach((form) => {
      initSwatches(form)
      initPriceAndStock(form)
      initButtons(form, config)
    })
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }
}
