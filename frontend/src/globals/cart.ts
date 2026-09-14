/**
 * Behaviour for the "Galaxie Cart" widget.
 *
 * WooCommerce's own cart asks the shopper to type a quantity, then find the
 * "Update cart" button, then wait for a page reload. That is the same
 * complaint that got add-to-cart rebuilt, so quantity changes and removals go
 * through one small AJAX endpoint instead.
 *
 * The endpoint calls `WC_Cart::set_quantity()` — the very call WooCommerce's
 * own form handler makes — so stock limits, `sold_individually` and every
 * cart hook behave identically. This is a different door into the same room,
 * not a second set of rules. Nothing here computes a total: the server sends
 * back the totals block it rendered, and this only swaps it in.
 *
 * With the switch off, none of this binds and the native form posts as usual.
 */

import { refreshFragments } from '@/globals/cart-fragments'

interface CartConfig {
  ajaxUrl: string
  nonce: string
}

interface CartResponse {
  success: boolean
  data?: {
    removed?: boolean
    subtotal?: string
    count?: number
    empty?: boolean
    totals?: string
    freeShipping?: { percent: number; achieved: boolean; remaining: string } | null
    fragments?: Record<string, string>
    cart_hash?: string
    // Only on failure: why, and what the cart still holds.
    message?: string
    quantity?: number
  }
}

const PENDING = 'is-galaxie-updating'

interface JQueryLike {
  trigger: (e: string, args?: unknown[]) => void
  on: (events: string, selector: string, handler: (event: { target: unknown }) => void) => void
}

function jq(): ((el: unknown) => JQueryLike) | undefined {
  return (window as unknown as { jQuery?: (el: unknown) => JQueryLike }).jQuery
}

/**
 * A quantity typed digit by digit would otherwise fire a request per keystroke
 * — "12" asking for 1, then 12. The pause is long enough to finish typing and
 * short enough that nobody reaches for a button that is not there.
 *
 * One timer per cart line, not one for the form: with a single timer, changing
 * product A and then product B inside the pause cancelled A's update.
 */
const DEBOUNCE_MS = 400
const timers = new Map<string, number>()

function debounceByKey(key: string, fn: () => void): void {
  window.clearTimeout(timers.get(key))
  timers.set(
    key,
    window.setTimeout(() => {
      timers.delete(key)
      fn()
    }, DEBOUNCE_MS)
  )
}

/**
 * Put WooCommerce's fragments into the page the way its own add-to-cart.js and
 * cart-fragments.js do: each key is a selector, each value replaces what it
 * matches. Then keep cart-fragments.js's session cache in step, so the next
 * page load does not paint the old mini cart from storage, and announce it.
 */
function applyWooFragments(fragments: Record<string, string>, cartHash?: string): void {
  for (const [selector, html] of Object.entries(fragments)) {
    let targets: NodeListOf<Element>
    try {
      targets = document.querySelectorAll(selector)
    } catch {
      continue // A plugin's selector the DOM API will not parse.
    }
    targets.forEach((el) => {
      el.outerHTML = html
    })
  }

  const params = (window as unknown as { wc_cart_fragments_params?: { fragment_name?: string; cart_hash_key?: string } })
    .wc_cart_fragments_params

  if (params?.fragment_name) {
    try {
      sessionStorage.setItem(params.fragment_name, JSON.stringify(fragments))
      if (params.cart_hash_key && cartHash !== undefined) {
        sessionStorage.setItem(params.cart_hash_key, cartHash)
        localStorage.setItem(params.cart_hash_key, cartHash)
      }
    } catch {
      // Storage blocked: cart-fragments.js falls back to asking the server.
    }
  }

  const $ = jq()
  if ($) {
    $(document.body).trigger('wc_fragments_loaded')
    $(document.body).trigger('wc_fragments_refreshed')
  }
}

/** WooCommerce's error notice, shown above the cart form. */
function showError(form: HTMLElement, message: string): void {
  const wrapper = document.querySelector('.woocommerce-notices-wrapper')
  const notice = document.createElement('ul')
  notice.className = 'woocommerce-error'
  notice.setAttribute('role', 'alert')
  const li = document.createElement('li')
  li.textContent = message
  notice.appendChild(li)

  if (wrapper) {
    wrapper.replaceChildren(notice)
  } else {
    form.querySelector(':scope > .woocommerce-error')?.remove()
    form.prepend(notice)
  }

  notice.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
}

/**
 * Which Elementor widget drew the totals, read off the page.
 *
 * The endpoint re-renders the totals rows and has no idea which widget's
 * settings to render them with. These two ids are already in the DOM — Elementor
 * puts them there — so sending them costs nothing and means the rows come back
 * looking like the ones they replace instead of unstyled.
 */
function totalsOrigin(): Record<string, string> {
  const totals = document.querySelector<HTMLElement>('.galaxie-cart-totals')
  const element = totals?.closest<HTMLElement>('.elementor-element[data-id]')
  const document_ = totals?.closest<HTMLElement>('[data-elementor-id]')

  const elementId = element?.dataset.id
  const postId = document_?.dataset.elementorId

  return elementId && postId ? { element_id: elementId, elementor_post: postId } : {}
}

async function send(config: CartConfig, key: string, quantity: number, remove = false): Promise<CartResponse> {
  const body = new URLSearchParams({
    action: 'galaxie_cart_update',
    nonce: config.nonce,
    cart_item_key: key,
    quantity: String(quantity),
    // The remove link skips cart validation, as WooCommerce's own does.
    ...(remove ? { remove: '1' } : {}),
    ...totalsOrigin(),
  })

  const response = await fetch(config.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  })

  return (await response.json()) as CartResponse
}

function lineOf(el: Element): HTMLElement | null {
  return el.closest<HTMLElement>('.galaxie-cart-line[data-galaxie-key]')
}

function apply(line: HTMLElement, data: NonNullable<CartResponse['data']>): void {
  // An empty cart is a different page — a saved template, possibly — and this
  // script has no business rebuilding it. The server already knows.
  if (data.empty) {
    window.location.reload()
    return
  }

  if (data.removed) {
    line.remove()
  } else if (data.subtotal !== undefined) {
    const cell = line.querySelector('.galaxie-cart-subtotal')
    if (cell) cell.innerHTML = data.subtotal
  }

  if (data.totals) {
    // Searched on the DOCUMENT, not inside the form's own block. With the
    // split widgets the totals live in a container of their own — often a
    // sticky column on the other side of the page — and scoping the lookup to
    // the table's root would silently stop updating them there.
    //
    // Only the ROWS are replaced. The server has no widget settings when it
    // answers this request, so what it can render is the amounts and nothing
    // else; swapping the whole box would trade the merchant's background,
    // radius and checkout button for a bare one on the first quantity change.
    const rows = document.querySelector('.galaxie-cart-totals-rows')
    if (rows) rows.outerHTML = data.totals
  }

  applyFreeShipping(data.freeShipping)

  // A new quantity changes what the carriers quote and what a coupon takes
  // off, and those live in widgets this response does not carry.
  void refreshFragments()

  // WooCommerce's own fragments (the header mini cart among them) arrive in
  // the same response, so they are put in the page here rather than merely
  // announced — triggering `wc_fragments_refreshed` alone changed nothing.
  if (data.fragments) {
    applyWooFragments(data.fragments, data.cart_hash)
  } else {
    jq()?.(document.body).trigger('wc_fragment_refresh')
  }
}

/**
 * Refill the free shipping line from the numbers the server sent.
 *
 * The line itself, its styling and both sentences were rendered by the widget
 * and stay on the page — the endpoint has no widget settings, so it sends what
 * only it can know (how much is missing) and the sentence the merchant wrote is
 * the one that gets refilled.
 */
function applyFreeShipping(state: CartResponse['data'] extends undefined ? never : NonNullable<CartResponse['data']>['freeShipping']): void {
  if (!state) return

  const el = document.querySelector<HTMLElement>('[data-galaxie-free-shipping]')
  if (!el) return

  const text = el.querySelector<HTMLElement>('.galaxie-free-shipping-text')
  const fill = el.querySelector<HTMLElement>('.galaxie-free-shipping-fill')

  el.classList.toggle('is-achieved', state.achieved)
  el.classList.toggle('is-pending', !state.achieved)

  if (fill) fill.style.width = `${state.percent}%`

  if (!text) return

  if (state.achieved) {
    text.textContent = el.dataset.done ?? ''
    return
  }

  const template = el.dataset.template ?? ''
  const amount = document.createElement('span')
  amount.className = 'galaxie-free-shipping-amount'
  amount.textContent = state.remaining

  // Rebuilt from a text node and one element rather than by writing a string
  // into innerHTML: the template is merchant text and the amount is money, and
  // neither has any business being parsed as markup.
  text.textContent = ''
  const [before, after] = template.split('{amount}')
  text.appendChild(document.createTextNode(before ?? ''))
  if (template.includes('{amount}')) text.appendChild(amount)
  text.appendChild(document.createTextNode(after ?? ''))
}

let rebindRegistered = false

export function bootCart(config?: CartConfig): void {
  if (!config?.ajaxUrl) return

  // WooCommerce's cart.js replaces the whole `.woocommerce-cart-form` after
  // the shipping calculator or a shipping method change — a fresh element with
  // none of the listeners below on it. So the boot runs again on its
  // `updated_wc_div`, and each form node is bound exactly once.
  if (!rebindRegistered) {
    const jQuery = jq()
    if (jQuery) {
      rebindRegistered = true
      ;(jQuery(document.body) as unknown as { on: (e: string, h: () => void) => void }).on(
        'updated_wc_div updated_cart_totals',
        () => bootCart(config)
      )
    }
  }

  const form = document.querySelector<HTMLFormElement>('.galaxie-cart-form[data-galaxie-auto="1"]')
  if (!form || form.dataset.galaxieBound === '1') return

  form.dataset.galaxieBound = '1'
  form.classList.add('is-galaxie-auto')

  const update = async (line: HTMLElement, quantity: number, remove = false): Promise<void> => {
    const key = line.dataset.galaxieKey
    if (!key) return

    line.classList.add(PENDING)

    try {
      const json = await send(config, key, quantity, remove)
      if (json.success && json.data) {
        apply(line, json.data)
      } else if (json.data) {
        // Refused by WooCommerce's cart validation (a pack size, a limit):
        // nothing changed on the server, so the stepper goes back to what the
        // cart holds and the reason is shown the way WooCommerce shows it.
        const input = line.querySelector<HTMLInputElement>('input.qty')
        if (input && typeof json.data.quantity === 'number') input.value = String(json.data.quantity)
        if (json.data.message) showError(form, json.data.message)
      }
    } catch {
      // A failed request must not leave a cart showing a quantity the server
      // never accepted. Reloading shows what is actually there.
      window.location.reload()
      return
    }

    line.classList.remove(PENDING)
  }

  const onQuantity = (event: Event): void => {
    const input = event.target as HTMLInputElement | null
    if (!input || !input.classList.contains('qty')) return

    const line = lineOf(input)
    const key = line?.dataset.galaxieKey
    if (!line || !key) return

    // The value is read when the pause ends, so the last digit typed wins.
    debounceByKey(key, () => {
      const quantity = Number.parseInt(input.value, 10)
      if (Number.isNaN(quantity) || quantity < 0) return

      void update(line, quantity)
    })
  }

  // Through jQuery when it is there, and this is the whole reason the stepper
  // appeared to do nothing: the theme's − and + are anchors that write
  // `input.value` and then `$input.trigger('change')`. jQuery's trigger runs
  // jQuery's handlers, not listeners registered with `addEventListener`, so a
  // native listener hears absolutely nothing — no `input`, no `change`. Typing
  // a number worked, which is why this survived a check that typed one.
  const $ = jq()

  if ($) {
    $(form).on('change input', '.qty', (event) => onQuantity(event as unknown as Event))
  } else {
    form.addEventListener('input', onQuantity)
    form.addEventListener('change', onQuantity)
  }

  // Removal is a quantity of zero, which is WooCommerce's own convention — so
  // the link and the stepper reach the cart by the same path, and there is one
  // place where a removal can go wrong instead of two.
  form.addEventListener('click', (event) => {
    const link = (event.target as Element | null)?.closest<HTMLAnchorElement>('.galaxie-cart-remove')
    if (!link) return

    const line = lineOf(link)
    if (!line) return

    event.preventDefault()

    // A pending stepper change on this line is moot once it is removed.
    const key = line.dataset.galaxieKey
    if (key) {
      window.clearTimeout(timers.get(key))
      timers.delete(key)
    }

    void update(line, 0, true)
  })
}
