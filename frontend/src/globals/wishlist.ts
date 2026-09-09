/**
 * Global behavior (not an island): the wishlist toggle button. Storage lives on
 * the server (user meta / WC session), so this never keeps its own copy of the
 * list — it renders whatever state the toggle response reports, and rolls back
 * to the previous one when the request fails. Boots only when the PHP side sets
 * the `wishlist` config.
 *
 * Both visual states are already in the DOM (the widget renders two fully
 * configurable pixfort Buttons, one hidden by CSS), so switching state is just
 * a class flip — no rewriting of pixfort's own component markup from JS.
 */

interface WishlistConfig {
  ajaxUrl: string
  nonce: string
}

interface ToggleResponse {
  success: boolean
  data?: { in_wishlist?: boolean; count?: number }
}

export function bootWishlist(config: WishlistConfig): void {
  document.addEventListener('click', (event) => {
    const button = (event.target as HTMLElement | null)?.closest<HTMLButtonElement>('.galaxie-wishlist-btn')
    if (!button || button.tagName !== 'BUTTON' || button.disabled) return

    event.preventDefault()

    const productId = button.dataset.productId
    if (!productId) return

    const wasInWishlist = button.classList.contains('is-in-wishlist')
    button.disabled = true

    // Optimistic: the round-trip is short but not instant, and a heart that
    // only fills after the network feels broken. Reconciled (or rolled back)
    // against the server's own answer below either way.
    applyState(button, !wasInWishlist)

    const body = new URLSearchParams({
      action: 'galaxie_wishlist_toggle',
      nonce: config.nonce,
      product_id: productId,
    })

    fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
      .then((response) => response.json() as Promise<ToggleResponse>)
      .then((json) => {
        applyState(button, json.success ? (json.data?.in_wishlist ?? !wasInWishlist) : wasInWishlist)
        button.disabled = false
      })
      .catch(() => {
        applyState(button, wasInWishlist)
        button.disabled = false
      })
  })
}

function applyState(button: HTMLButtonElement, inWishlist: boolean): void {
  button.classList.toggle('is-in-wishlist', inWishlist)
  button.setAttribute('aria-pressed', inWishlist ? 'true' : 'false')
}
