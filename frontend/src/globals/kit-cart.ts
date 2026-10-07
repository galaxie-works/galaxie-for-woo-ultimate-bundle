/**
 * Kits in the cart: "Editar kit" links, the line that says a kit is out for
 * editing, and keeping cart widgets current after a kit goes in or comes out.
 *
 * The link is `<a href="#galaxie-kit-edit-{group}">` (PHP Groups::edit_link()):
 * a plain anchor, so one delegated handler serves the classic cart and
 * checkout and the Galaxie Cart widget (whose kit header row carries it).
 *
 * "Editar kit" takes the kit out of the cart, so it is always asked first —
 * the cart widget's own dialog ("Este kit volta para o montador e sai do
 * carrinho. Tudo bem?") or the built-in one. With another kit open, the server
 * asks too ("Adicionar o kit atual ao carrinho e editar este?"); a yes repeats
 * the request with `confirm=1`.
 *
 * While the draft is a kit taken out of the cart, every cart on the page says
 * so above its lines — "Kit 1 em edição · Voltar ao kit" — so closing the
 * popup does not make the kit look gone.
 */

import { ask, tell } from '@/lib/dialog'
import { refreshFragments, jq } from '@/globals/cart-fragments'
import { currentKit, kitCall, kitConfig, kitEditing, kitFill, kitText, onKit } from '@/globals/kit-store'
import type { KitAnswer } from '@/globals/kit-store'
import { canOpenKit, openKit } from '@/globals/kit-open'

const HASH = '#galaxie-kit-edit-'

/** Where a cart prints its lines: the bar goes above them. */
const CARTS = '.galaxie-cart-form, form.woocommerce-cart-form, .widget_shopping_cart_content'

interface WpData {
  dispatch: (store: string) => Record<string, ((...args: unknown[]) => unknown) | undefined> | undefined
}

/**
 * WooCommerce's fragments, put in place the way its own scripts do — each key
 * a selector, each value what replaces it — and its session cache kept in
 * step. Done here rather than by firing `added_to_cart`: that event is also
 * what themes and plugins listen to for "a product was added from this page",
 * and some answer it by opening drawers or reloading.
 */
function applyFragments(fragments: Record<string, string>, cartHash?: string): void {
  const $ = jq()

  for (const [selector, html] of Object.entries(fragments)) {
    // jQuery's replaceWith() runs the scripts a fragment may carry; without it, the DOM.
    const targets = $ ? ($(selector) as unknown as { replaceWith?: (markup: string) => unknown }) : null

    if (targets?.replaceWith) {
      targets.replaceWith(html)
    } else {
      document.querySelectorAll(selector).forEach((el) => {
        el.outerHTML = html
      })
    }
  }

  const params = (window as unknown as { wc_cart_fragments_params?: { fragment_name?: string; cart_hash_key?: string } }).wc_cart_fragments_params

  if (params?.fragment_name) {
    try {
      sessionStorage.setItem(params.fragment_name, JSON.stringify(fragments))
      if (params.cart_hash_key && cartHash) {
        localStorage.setItem(params.cart_hash_key, cartHash)
        sessionStorage.setItem(params.cart_hash_key, cartHash)
      }
    } catch {
      // Storage blocked: cart-fragments.js asks the server instead.
    }
  }

  if ($) {
    $(document.body).trigger('wc_fragments_loaded')
    $(document.body).trigger('wc_fragments_refreshed')
  }
}

/**
 * Every cart view on the page, redrawn: WooCommerce's mini cart fragments, the
 * classic cart form, the block cart and checkout (their data store), and the
 * Galaxie widgets that carry `data-galaxie-fragment`.
 */
export function refreshCart(answer?: KitAnswer | null): void {
  const $ = jq()

  if (answer?.fragments) {
    applyFragments(answer.fragments, answer.cart_hash)
  } else if ($) {
    $(document.body).trigger('wc_fragment_refresh')
  }

  if ($) {
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
  paintEditing()
}

async function edit(group: string, from: Element): Promise<void> {
  // Taking the kit out of the cart only makes sense if its popup can open.
  if (!canOpenKit()) {
    await tell(from, 'kit_edit', { text: kitText('edit_closed'), fallback: kitText('edit_closed') })
    return
  }

  // The kit leaves the cart: asked before anything moves.
  const name = from.closest('[data-galaxie-kit]')?.querySelector('.galaxie-cart-kit-title')?.textContent?.trim() ?? ''
  const sure = await ask(from, 'kit_edit', {
    fallback: 'Este kit volta para o montador e sai do carrinho até você adicioná-lo de novo. Continuar?',
    fill: { kit: name },
  })
  if (!sure) return

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

/**
 * "Kit 1 em edição · Voltar ao kit" above every cart on the page while the
 * draft is a kit taken out of the cart; gone once it is back or discarded.
 */
function paintEditing(): void {
  document.querySelectorAll('.galaxie-kit-editing').forEach((bar) => bar.remove())

  const kit = currentKit()
  if (!kitEditing() || !kit) return

  document.querySelectorAll<HTMLElement>(CARTS).forEach((cart) => {
    const bar = document.createElement('div')
    bar.className = 'galaxie-kit-editing'
    bar.setAttribute('role', 'status')

    const text = document.createElement('span')
    text.textContent = kitFill(kitText('editing', '{kit} em edição'), { kit: kit.name })

    const back = document.createElement('a')
    back.href = '#'
    back.className = 'galaxie-kit-editing-back'
    back.dataset.galaxieKitResume = ''
    back.textContent = kitText('editing_back', 'Voltar ao kit')

    bar.append(text, document.createTextNode(' · '), back)

    // Inside the mini cart's own box (its fragment replaces what is in it);
    // above a cart form, outside it, so the form's own layout is untouched.
    if (cart.matches('.widget_shopping_cart_content')) cart.prepend(bar)
    else cart.before(bar)
  })
}

/**
 * The Galaxie Cart widget drops lines without reloading (cart.ts): a kit's
 * header row left with no kit line under it goes too.
 */
function tidyKitHeads(): void {
  document.querySelectorAll<HTMLElement>('.galaxie-cart-kit-head').forEach((head) => {
    if (!head.nextElementSibling?.classList.contains('galaxie-kit-line')) head.remove()
  })
}

export function bootKitCart(): void {
  if (!kitConfig()) return

  document.addEventListener('click', (event) => {
    const target = event.target as Element | null

    const resume = target?.closest<HTMLElement>('[data-galaxie-kit-resume]')
    if (resume) {
      event.preventDefault()
      openKit({ screen: 'summary' })
      return
    }

    const link = target?.closest<HTMLAnchorElement>(`a[href^="${HASH}"]`)
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

  onKit(() => paintEditing())

  // The mini cart is redrawn from fragments: the bar goes back in after each.
  jq()?.(document.body).on('wc_fragments_refreshed wc_fragments_loaded updated_wc_div removed_from_cart', () => {
    window.setTimeout(paintEditing, 0)
  })

  const watch = (): void => {
    paintEditing()

    document.querySelectorAll('.galaxie-cart-form').forEach((form) => {
      new MutationObserver(tidyKitHeads).observe(form, { childList: true })
    })
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch)
  else watch()
}
