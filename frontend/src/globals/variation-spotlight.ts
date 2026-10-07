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

import { tell } from '@/lib/dialog'
import { parseReply, postWithNonce } from '@/lib/wp'

interface VariationSpotlightConfig {
  ajaxUrl: string
  nonce: string
}

interface AddToCartData {
  fragments?: Record<string, string>
  cart_hash?: string
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

    // Nonce read at send time and renewed once if a cached page outlived it.
    postWithNonce(config.ajaxUrl, config, {
      action: 'galaxie_spotlight_add_to_cart',
      variation_id: variationId,
      quantity: '1',
    })
      .then((reply) => {
        const json = parseReply<AddToCartData>(reply)
        if (json?.success) {
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
          void tell(button, 'spotlight_dialog', { text: json?.data?.message, fallback: 'Não foi possível adicionar ao carrinho.' })
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
