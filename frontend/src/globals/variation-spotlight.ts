/**
 * Global behavior (not an island): powers the "Add straight to cart" button
 * mode of the Variation Spotlight widget (the "Go to product page" mode needs
 * no JS at all — it's a plain link). Boots only when the PHP side sets the
 * `variationSpotlight` flag.
 *
 * On success, mirrors WooCommerce's own AJAX add-to-cart JS: triggers the
 * native `added_to_cart` jQuery event with the same `fragments`/`cart_hash`
 * payload the server returns, so WooCommerce's own cart-fragments script
 * refreshes the mini-cart — no reload, no reimplementing that logic.
 */

interface VariationSpotlightConfig {
  ajaxUrl: string
  nonce: string
}

interface AddToCartResponse {
  success: boolean
  data?: { message?: string; fragments?: Record<string, string>; cart_hash?: string }
}

export function bootVariationSpotlight(config: VariationSpotlightConfig): void {
  document.addEventListener('click', (event) => {
    const button = (event.target as HTMLElement | null)?.closest<HTMLButtonElement>('.galaxie-spotlight-add')
    if (!button || button.tagName !== 'BUTTON' || button.disabled) return

    event.preventDefault()

    const variationId = button.dataset.variationId
    if (!variationId) return

    const originalText = button.textContent
    button.disabled = true
    button.textContent = '…'

    const body = new URLSearchParams({
      action: 'galaxie_spotlight_add_to_cart',
      nonce: config.nonce,
      variation_id: variationId,
      quantity: '1',
    })

    fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
      .then((response) => response.json() as Promise<AddToCartResponse>)
      .then((json) => {
        if (json.success) {
          button.textContent = 'Adicionado!'
          const jq = window.jQuery
          if (jq && json.data) {
            jq(document.body).trigger('added_to_cart', [json.data.fragments, json.data.cart_hash, jq(button)])
          }
          window.setTimeout(() => {
            button.textContent = originalText
            button.disabled = false
          }, 2000)
        } else {
          window.alert(json.data?.message ?? 'Não foi possível adicionar ao carrinho.')
          button.textContent = originalText
          button.disabled = false
        }
      })
      .catch(() => {
        button.textContent = originalText
        button.disabled = false
      })
  })
}
