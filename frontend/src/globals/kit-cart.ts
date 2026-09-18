/**
 * Kits in the cart: "Editar kit" links, and keeping cart widgets current after
 * a kit goes in or comes out.
 *
 * The link is `<a href="#galaxie-kit-edit-{group}">` (PHP Groups::edit_link()):
 * a plain anchor survives the block cart's HTML sanitiser (it keeps `a`, `href`
 * and `class`), so one delegated handler serves the classic cart and checkout,
 * the block cart and checkout, and the Galaxie Cart widget.
 *
 * With another kit open, the server asks first ("Adicionar o kit atual ao
 * carrinho e editar este?"); a yes repeats the request with `confirm=1`.
 */

import { ask, tell } from '@/lib/dialog'
import { refreshFragments, jq } from '@/globals/cart-fragments'
import { kitCall, kitConfig, kitText } from '@/globals/kit-store'
import type { KitAnswer } from '@/globals/kit-store'
import { canOpenKit, openKit } from '@/globals/kit-open'

const HASH = '#galaxie-kit-edit-'

interface WpData {
  dispatch: (store: string) => Record<string, ((...args: unknown[]) => unknown) | undefined> | undefined
}

/**
 * Every cart view on the page, redrawn: WooCommerce's mini cart fragments, the
 * classic cart form, the block cart and checkout (their data store), and the
 * Galaxie widgets that carry `data-galaxie-fragment`.
 */
export function refreshCart(answer?: KitAnswer | null): void {
  const $ = jq()

  if ($) {
    if (answer?.fragments) {
      $(document.body).trigger('added_to_cart', [answer.fragments, answer.cart_hash])
    } else {
      $(document.body).trigger('wc_fragment_refresh')
    }

    if (document.querySelector('form.woocommerce-cart-form') && !document.querySelector('.galaxie-cart-form')) {
      $(document.body).trigger('wc_update_cart')
    }

    if (document.querySelector('form.checkout')) {
      $(document.body).trigger('update_checkout')
    }
  }

  const data = (window as unknown as { wp?: { data?: WpData } }).wp?.data
  const cart = data?.dispatch('wc/store/cart')
  if (cart?.invalidateResolutionForStore) cart.invalidateResolutionForStore()

  void refreshFragments()
}

async function edit(group: string, from: Element): Promise<void> {
  // Taking the kit out of the cart only makes sense if its popup can open.
  if (!canOpenKit()) {
    await tell(from, 'kit_edit', { text: kitText('edit_closed'), fallback: kitText('edit_closed') })
    return
  }

  let result = await kitCall('edit_from_cart', { group })

  if (!result.ok && result.data?.reason === 'needs_confirm') {
    const yes = await ask(from, 'kit_edit', { text: result.data.message ?? '', fallback: result.data.message ?? '' })
    if (!yes) return

    result = await kitCall('edit_from_cart', { group, confirm: 1 })
  }

  if (!result.ok) {
    await tell(from, 'kit_edit', { text: result.data?.message || kitText('edit_failed'), fallback: kitText('edit_failed') })
    return
  }

  refreshCart(result.data)
  if (!openKit({ screen: 'summary' })) {
    await tell(from, 'kit_edit', { text: kitText('edit_done'), fallback: kitText('edit_done') })
  }
}

export function bootKitCart(): void {
  if (!kitConfig()) return

  document.addEventListener('click', (event) => {
    const link = (event.target as Element | null)?.closest<HTMLAnchorElement>(`a[href^="${HASH}"]`)
    if (!link) return

    event.preventDefault()
    event.stopPropagation()

    const group = (link.getAttribute('href') ?? '').slice(HASH.length)
    if (!/^[a-z0-9]{1,32}$/.test(group) || link.dataset.busy) return

    link.dataset.busy = '1'
    void edit(group, link).finally(() => {
      delete link.dataset.busy
    })
  })

  // A kit that went in (or out) from the popup, the Buy Box or the progress widget.
  document.addEventListener('galaxie:kit-cart', (event) => {
    refreshCart((event as CustomEvent<KitAnswer>).detail)
  })
}
