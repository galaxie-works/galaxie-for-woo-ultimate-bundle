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
 */
function debounce<T extends (...args: never[]) => void>(fn: T, ms: number): T {
  let timer = 0
  return ((...args: never[]) => {
    window.clearTimeout(timer)
    timer = window.setTimeout(() => fn(...args), ms)
  }) as T
}

async function send(config: CartConfig, key: string, quantity: number): Promise<CartResponse> {
  const body = new URLSearchParams({
    action: 'galaxie_cart_update',
    nonce: config.nonce,
    cart_item_key: key,
    quantity: String(quantity),
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

  // The mini cart in the header listens for this, and WooCommerce's own
  // fragments arrive in the same response — so the two never disagree.
  const $ = jq()
  if ($ && data.fragments) {
    $(document.body).trigger('wc_fragments_refreshed')
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

export function bootCart(config?: CartConfig): void {
  if (!config?.ajaxUrl) return

  const form = document.querySelector<HTMLFormElement>('.galaxie-cart-form[data-galaxie-auto="1"]')
  if (!form) return

  form.classList.add('is-galaxie-auto')

  const update = async (line: HTMLElement, quantity: number): Promise<void> => {
    const key = line.dataset.galaxieKey
    if (!key) return

    line.classList.add(PENDING)

    try {
      const json = await send(config, key, quantity)
      if (json.success && json.data) apply(line, json.data)
    } catch {
      // A failed request must not leave a cart showing a quantity the server
      // never accepted. Reloading shows what is actually there.
      window.location.reload()
      return
    }

    line.classList.remove(PENDING)
  }

  const onQuantity = debounce((event: Event) => {
    const input = event.target as HTMLInputElement | null
    if (!input || !input.classList.contains('qty')) return

    const line = lineOf(input)
    if (!line) return

    const quantity = Number.parseInt(input.value, 10)
    if (Number.isNaN(quantity) || quantity < 0) return

    void update(line, quantity)
  }, 400)

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
    void update(line, 0)
  })
}
