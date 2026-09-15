/**
 * Global behavior (not an island): the wishlist heart and the "add to a list"
 * popover. Storage lives on the server (the customer's lists, see PHP
 * Modules\Wishlist\Lists), so this never keeps its own copy — it renders
 * whatever the server answers, and rolls back when a request fails. Boots only
 * when the PHP side sets the `wishlist` config.
 *
 * The heart saves to the default list. Both of its visual states are already
 * in the DOM (the widget renders two configurable pixfort Buttons, one hidden
 * by CSS), so switching state is a class flip.
 *
 * Saving needs an account. A visitor's tap is answered with where to sign in;
 * the server has already remembered the product and this page, and saves it
 * and brings them back once they are in.
 */

interface WishlistConfig {
  ajaxUrl: string
  nonce: string
  loggedIn?: boolean
}

export interface WishlistList {
  id: string
  name: string
  default: boolean
  gifts: boolean
  shared: boolean
  shareUrl: string
  count: number
  has: boolean
}

interface WishlistResponse {
  success: boolean
  data?: {
    in_wishlist?: boolean
    lists?: WishlistList[]
    list?: WishlistList
    login?: string
    message?: string
  }
}

async function call(config: WishlistConfig, action: string, fields: Record<string, string>): Promise<WishlistResponse> {
  const body = new URLSearchParams({ action: `galaxie_wishlist_${action}`, nonce: config.nonce, return: window.location.href, ...fields })
  const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
  const json = (await response.json()) as WishlistResponse

  // A visitor: the server remembered what they tapped; sign in and come back.
  if (!json.success && json.data?.login) window.location.assign(json.data.login)

  return json
}

export function bootWishlist(config: WishlistConfig): void {
  document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement | null

    const listButton = target?.closest<HTMLButtonElement>('.galaxie-wishlist-list-btn')
    if (listButton) {
      event.preventDefault()
      void openPopover(config, listButton)
      return
    }

    if (popover && !target?.closest('.galaxie-wishlist-popover')) closePopover()

    const button = target?.closest<HTMLButtonElement>('.galaxie-wishlist-btn')
    if (!button || button.tagName !== 'BUTTON' || button.disabled) return

    event.preventDefault()

    const productId = button.dataset.productId
    if (!productId) return

    const wasInWishlist = button.classList.contains('is-in-wishlist')
    button.disabled = true

    // Optimistic for a signed-in customer: a heart that only fills after the
    // network feels broken. A visitor is about to be sent to sign in instead.
    if (config.loggedIn) applyState(button, !wasInWishlist)

    call(config, 'toggle', { product_id: productId })
      .then((json) => {
        if (json.data?.login) return
        syncHearts(productId, json.success ? (json.data?.in_wishlist ?? !wasInWishlist) : wasInWishlist)
        button.disabled = false
      })
      .catch(() => {
        applyState(button, wasInWishlist)
        button.disabled = false
      })
  })

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && popover) closePopover()
  })
}

function applyState(button: HTMLButtonElement, inWishlist: boolean): void {
  button.classList.toggle('is-in-wishlist', inWishlist)
  button.setAttribute('aria-pressed', inWishlist ? 'true' : 'false')

  // An icon-only button has no visible words; its name has to follow the state.
  const label = inWishlist ? button.dataset.labelSaved : button.dataset.labelAdd
  if (label) button.setAttribute('aria-label', label)
}

/** Every heart for the product on the page, not only the one tapped. */
function syncHearts(productId: string, inDefault: boolean): void {
  document.querySelectorAll<HTMLButtonElement>(`.galaxie-wishlist-btn[data-product-id="${productId}"]`).forEach((button) => {
    applyState(button, inDefault)
  })
}

// ----------------------------------------------------------------- popover

let popover: HTMLElement | null = null

function closePopover(): void {
  popover?.remove()
  popover = null
}

async function openPopover(config: WishlistConfig, trigger: HTMLButtonElement): Promise<void> {
  const productId = trigger.dataset.productId ?? ''
  if (!productId) return

  if (popover?.dataset.productId === productId) {
    closePopover()
    return
  }

  closePopover()
  trigger.disabled = true

  // The widget's anchor: its template carries the classes the Style tab chose,
  // and opening inside it is what lets those styles reach the popover.
  const anchor = trigger.closest<HTMLElement>('.galaxie-wishlist-list-anchor')
  const template = anchor?.querySelector<HTMLTemplateElement>('template.galaxie-wishlist-popover-template')

  try {
    const json = await call(config, 'lists', { product_id: productId })
    if (!json.success || !json.data?.lists) return

    // The editor's always-open preview makes way for the real one.
    anchor?.querySelector('.galaxie-wishlist-popover.is-preview')?.remove()

    popover = buildPopover(config, trigger, productId, json.data.lists, template)

    if (anchor && template) {
      anchor.appendChild(popover)
      keepInView(popover)
    } else {
      document.body.appendChild(popover)
      place(popover, trigger)
    }

    popover.querySelector<HTMLInputElement>('input[type="checkbox"]')?.focus()
  } finally {
    trigger.disabled = false
  }
}

/** The widget's own skeleton, or the one this script built before it had one. */
function skeleton(trigger: HTMLButtonElement, template?: HTMLTemplateElement | null): HTMLElement {
  const cloned = template?.content.firstElementChild?.cloneNode(true)
  if (cloned instanceof HTMLElement) return cloned

  const box = document.createElement('div')
  box.className = 'galaxie-wishlist-popover'
  box.setAttribute('role', 'dialog')

  const title = document.createElement('div')
  title.className = 'galaxie-wishlist-popover-title font-weight-bold'
  title.textContent = trigger.dataset.title || 'Salvar em'

  const list = document.createElement('ul')
  list.className = 'galaxie-wishlist-popover-lists'

  const form = document.createElement('form')
  form.className = 'galaxie-wishlist-popover-new'
  const field = document.createElement('input')
  field.type = 'text'
  field.maxLength = 60
  field.placeholder = trigger.dataset.placeholder || 'Nome da nova lista'
  field.className = 'form-control'
  const create = document.createElement('button')
  create.type = 'submit'
  create.className = 'btn btn-sm btn-primary'
  create.textContent = trigger.dataset.create || 'Criar lista'
  form.append(field, create)

  box.append(title, list, form)
  return box
}

function buildPopover(config: WishlistConfig, trigger: HTMLButtonElement, productId: string, lists: WishlistList[], template?: HTMLTemplateElement | null): HTMLElement {
  const box = skeleton(trigger, template)
  box.dataset.productId = productId

  const list = box.querySelector<HTMLUListElement>('.galaxie-wishlist-popover-lists') ?? box.appendChild(document.createElement('ul'))
  const rowClass = list.dataset.rowClass ?? ''

  const render = (items: WishlistList[]) => {
    list.replaceChildren(
      ...items.map((item) => {
        const row = document.createElement('li')
        const label = document.createElement('label')
        const input = document.createElement('input')
        const name = document.createElement('span')

        if (rowClass) label.className = rowClass
        input.type = 'checkbox'
        input.checked = item.has
        name.textContent = item.name
        label.append(input, name)
        row.appendChild(label)

        input.addEventListener('change', () => {
          input.disabled = true
          call(config, 'set', { list_id: item.id, product_id: productId, on: input.checked ? '1' : '' })
            .then((json) => {
              if (!json.success) {
                input.checked = !input.checked
                return
              }
              syncFromLists(productId, json.data?.lists ?? [])
            })
            .catch(() => {
              input.checked = !input.checked
            })
            .finally(() => {
              input.disabled = false
            })
        })

        return row
      })
    )
  }

  render(lists)

  const form = box.querySelector<HTMLFormElement>('.galaxie-wishlist-popover-new')
  const field = form?.querySelector<HTMLInputElement>('input[type="text"]')
  const create = form?.querySelector<HTMLButtonElement>('button[type="submit"]')
  if (!form || !field || !create) return box

  form.addEventListener('submit', (event) => {
    event.preventDefault()
    const name = field.value.trim()
    if (!name) {
      field.focus()
      return
    }

    create.disabled = true
    call(config, 'create', { name, product_id: productId })
      .then((json) => {
        if (!json.success || !json.data?.lists) return
        field.value = ''
        render(json.data.lists)
        syncFromLists(productId, json.data.lists)
      })
      .finally(() => {
        create.disabled = false
      })
  })

  return box
}

function syncFromLists(productId: string, lists: WishlistList[]): void {
  const main = lists.find((item) => item.default)
  if (main) syncHearts(productId, main.has)
}

/**
 * Inside the widget the stylesheet places the popover; this only slides it
 * back when that would leave the viewport, through the variable the
 * stylesheet's transform already adds.
 */
function keepInView(box: HTMLElement): void {
  box.style.removeProperty('--galaxie-wl-nudge')

  const rect = box.getBoundingClientRect()
  const edge = 8
  let nudge = 0

  if (rect.left < edge) nudge = edge - rect.left
  else if (rect.right > window.innerWidth - edge) nudge = window.innerWidth - edge - rect.right

  if (nudge) box.style.setProperty('--galaxie-wl-nudge', `${nudge}px`)
}

/** Fallback for markup with no anchor: under the button, kept inside the viewport. */
function place(box: HTMLElement, trigger: HTMLElement): void {
  const rect = trigger.getBoundingClientRect()
  const width = box.offsetWidth
  const left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8)

  box.style.top = `${rect.bottom + window.scrollY + 8}px`
  box.style.left = `${left + window.scrollX}px`
}
