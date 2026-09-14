/**
 * "Save this list" on a shared wishlist: copies it to the visitor's own lists.
 * A visitor who is not signed in is sent to sign in; the server remembers the
 * list and saves it once they are in (see PHP Modules\Wishlist\Module).
 */

interface WishlistConfig {
  ajaxUrl: string
  nonce: string
}

export function bootSharedWishlist(config?: WishlistConfig): void {
  if (!config) return

  document.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest?.<HTMLButtonElement>('.galaxie-shared-save')
    const root = button?.closest<HTMLElement>('.galaxie-shared-wishlist')
    if (!button || !root || button.disabled) return

    event.preventDefault()
    button.disabled = true

    const body = new URLSearchParams({
      action: 'galaxie_wishlist_save_shared',
      nonce: config.nonce,
      token: root.dataset.token ?? '',
      return: window.location.href,
    })

    fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
      .then((response) => response.json() as Promise<{ success: boolean; data?: { login?: string; message?: string } }>)
      .then((json) => {
        if (!json.success && json.data?.login) {
          window.location.assign(json.data.login)
          return
        }

        const line = root.querySelector<HTMLElement>(':scope > .galaxie-account-message')
        if (line) {
          line.className = `galaxie-account-message ${json.success ? 'is-success' : 'is-error'} ${root.dataset.msgClass ?? ''}`.trim()
          line.textContent = json.success ? (root.dataset.saved ?? '') : (json.data?.message ?? 'Algo deu errado. Tente de novo.')
          line.hidden = false
        }

        button.disabled = json.success
      })
      .catch(() => {
        button.disabled = false
      })
  })
}
