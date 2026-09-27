/**
 * Global behavior (not an island): the wishlist button and its "Salvar em"
 * popover. Storage lives on the server (the customer's lists, see PHP
 * Modules\Wishlist\Lists), so this never keeps its own copy — it renders
 * whatever the server answers, and rolls back when a request fails. Boots only
 * when the PHP side sets the `wishlist` config.
 *
 * A tap on the button opens the popover: every list, the default one first and
 * marked, each with a checkbox that saves or removes the product at once, and a
 * field to create a list with the product already on it. The button shows its
 * saved state while the product is on any list. Both of its visual states are
 * already in the DOM (the widget renders two configurable pixfort Buttons, one
 * hidden by CSS), so switching state is a class flip.
 *
 * Saving needs an account. A visitor's tap asks the server for the lists; the
 * server remembers the product and this page, answers with where to sign in,
 * and saves it to the default list and brings them back once they are in.
 *
 * The popover opens at the end of <body>, inside two wrappers that repeat the
 * widget's Elementor classes with `display: contents`. At the end of the page no
 * product card's `overflow: hidden` can clip it, and the repeated classes are
 * what Elementor's `{{WRAPPER}}` rules for the Style tab select on, so they
 * still reach it. It is placed against the button from variables those Style
 * controls set.
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

interface OpenPopover {
  box: HTMLElement
  shell: HTMLElement
  trigger: HTMLButtonElement
}

/** The old markup's second button, still on a cached page, opens the same popover. */
const TRIGGERS = '.galaxie-wishlist-btn, .galaxie-wishlist-list-btn'

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
    const trigger = target?.closest<HTMLButtonElement>(TRIGGERS)

    if (trigger && trigger.tagName === 'BUTTON' && !trigger.closest('.galaxie-wishlist-popover')) {
      event.preventDefault()
      if (!trigger.disabled) void openPopover(config, trigger)
      return
    }

    if (open && !target?.closest('.galaxie-wishlist-popover')) closePopover(false)
  })

  document.addEventListener('keydown', (event) => {
    if (!open) return

    if (event.key === 'Escape') {
      event.preventDefault()
      closePopover(true)
    } else if (event.key === 'Tab') {
      keepFocusInside(event, open.box)
    }
  })

  // Fixed to the viewport, so it follows the button through any scroll —
  // the page's or a carousel's (capture catches scrolls that do not bubble).
  const follow = () => {
    if (open) place(open.box, open.trigger)
  }
  window.addEventListener('resize', follow)
  document.addEventListener('scroll', follow, true)

  bootPreviews()
}

function applyState(button: HTMLButtonElement, saved: boolean): void {
  button.classList.toggle('is-in-wishlist', saved)

  // Only the old toggle markup carries aria-pressed; the popover button says
  // its state through its name.
  if (button.hasAttribute('aria-pressed')) button.setAttribute('aria-pressed', saved ? 'true' : 'false')

  // An icon-only button has no visible words; its name has to follow the state.
  const label = saved ? button.dataset.labelSaved : button.dataset.labelAdd
  if (label) button.setAttribute('aria-label', label)
}

/** Every button for the product on the page, not only the one tapped. */
function syncButtons(productId: string, lists: WishlistList[]): void {
  const saved = lists.some((item) => item.has)

  document.querySelectorAll<HTMLButtonElement>(`.galaxie-wishlist-btn[data-product-id="${productId}"]`).forEach((button) => {
    applyState(button, saved)
  })
}

// ----------------------------------------------------------------- popover

let open: OpenPopover | null = null
let opened = 0

function closePopover(returnFocus: boolean): void {
  if (!open) return

  const { shell, trigger } = open
  open = null

  shell.remove()
  trigger.setAttribute('aria-expanded', 'false')
  trigger.removeAttribute('aria-controls')

  if (returnFocus) trigger.focus()
}

async function openPopover(config: WishlistConfig, trigger: HTMLButtonElement): Promise<void> {
  const productId = trigger.dataset.productId ?? ''
  if (!productId) return

  // A second tap on the same button closes it.
  if (open?.trigger === trigger) {
    closePopover(true)
    return
  }

  closePopover(false)
  trigger.disabled = true
  trigger.setAttribute('aria-busy', 'true')

  try {
    const json = await call(config, 'lists', { product_id: productId })
    if (!json.success || !json.data?.lists) return

    const anchor = trigger.closest<HTMLElement>('.galaxie-wishlist-anchor')
    const template = anchor?.querySelector<HTMLTemplateElement>('template.galaxie-wishlist-popover-template')

    // The editor's always-open preview makes way for the real one.
    anchor?.querySelectorAll('.galaxie-wishlist-popover.is-preview').forEach((preview) => preview.remove())

    const box = buildPopover(config, trigger, productId, json.data.lists, template)
    const { shell, slot } = portal(trigger)

    box.id = `galaxie-wishlist-popover-${++opened}`
    slot.appendChild(box)
    document.body.appendChild(shell)

    open = { box, shell, trigger }
    trigger.setAttribute('aria-expanded', 'true')
    trigger.setAttribute('aria-controls', box.id)

    place(box, trigger)
    box.querySelector<HTMLInputElement>('input[type="checkbox"]')?.focus()
  } finally {
    trigger.disabled = false
    trigger.removeAttribute('aria-busy')
  }
}

/**
 * A home for the popover at the end of <body> that Elementor's selectors still
 * match: `.elementor-{document} .elementor-element.elementor-element-{widget}`
 * is what `{{WRAPPER}}` becomes. Both wrappers are `display: contents`, so the
 * widget's own padding or background never paints them. Outside Elementor the
 * shell is empty and the popover keeps the stylesheet's defaults.
 */
function portal(trigger: HTMLElement): { shell: HTMLElement; slot: HTMLElement } {
  const shell = document.createElement('div')
  shell.className = 'galaxie-wishlist-portal'

  const widget = trigger.closest<HTMLElement>('.elementor-element[data-id]')
  const documentRoot = widget?.closest<HTMLElement>('[data-elementor-id]')

  if (!widget?.dataset.id || !documentRoot) return { shell, slot: shell }

  const outer = document.createElement('div')
  outer.className = ['elementor', ...Array.from(documentRoot.classList).filter((name) => /^elementor-\d+$/.test(name))].join(' ')

  const inner = document.createElement('div')
  inner.className = `elementor-element elementor-element-${widget.dataset.id}`

  outer.appendChild(inner)
  shell.appendChild(outer)

  return { shell, slot: inner }
}

/** The widget's own skeleton, or the one this script built before it had one. */
function skeleton(trigger: HTMLButtonElement, template?: HTMLTemplateElement | null): HTMLElement {
  const cloned = template?.content.firstElementChild?.cloneNode(true)
  if (cloned instanceof HTMLElement) return cloned

  const box = document.createElement('div')
  box.className = 'galaxie-wishlist-popover is-fallback'
  box.setAttribute('role', 'dialog')

  const title = document.createElement('div')
  title.className = 'galaxie-wishlist-popover-title font-weight-bold'
  title.textContent = trigger.dataset.title || 'Salvar em'
  box.setAttribute('aria-label', title.textContent)

  const list = document.createElement('ul')
  list.className = 'galaxie-wishlist-popover-lists'
  list.dataset.badgeText = 'Padrão'

  const form = document.createElement('form')
  form.className = 'galaxie-wishlist-popover-new'
  const field = document.createElement('input')
  field.type = 'text'
  field.maxLength = 60
  field.placeholder = trigger.dataset.placeholder || 'Nome da nova lista'
  field.setAttribute('aria-label', field.placeholder)
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
  const badgeClass = list.dataset.badgeClass ?? ''
  const badgeText = list.dataset.badgeText ?? ''

  // Same markup as the widget's editor preview, so the Style tab styles both.
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
        name.className = 'galaxie-wishlist-popover-name'
        name.textContent = item.name
        label.append(input, name)

        if (item.default && badgeText) {
          const badge = document.createElement('span')
          badge.className = `galaxie-wishlist-popover-badge ${badgeClass}`.trim()
          badge.textContent = badgeText
          label.appendChild(badge)
        }

        row.appendChild(label)

        input.addEventListener('change', () => {
          input.disabled = true
          call(config, 'set', { list_id: item.id, product_id: productId, on: input.checked ? '1' : '' })
            .then((json) => {
              if (!json.success) {
                input.checked = !input.checked
                return
              }
              syncButtons(productId, json.data?.lists ?? [])
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

  // The server sends the default list first, and creates it for an account
  // that has none — so a first save is one tick.
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
        syncButtons(productId, json.data.lists)
        if (open) place(open.box, open.trigger)
      })
      .finally(() => {
        create.disabled = false
      })
  })

  return box
}

/** A dialog: Tab and Shift+Tab go round its own fields and buttons. */
function keepFocusInside(event: KeyboardEvent, box: HTMLElement): void {
  const items = Array.from(box.querySelectorAll<HTMLInputElement | HTMLButtonElement>('input, button')).filter((item) => !item.disabled)
  if (!items.length) return

  const first = items[0]
  const last = items[items.length - 1]
  const active = document.activeElement

  if (!box.contains(active)) {
    event.preventDefault()
    ;(event.shiftKey ? last : first).focus()
  } else if (event.shiftKey && active === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && active === last) {
    event.preventDefault()
    first.focus()
  }
}

/**
 * Against the button, from the variables the widget's Position controls set
 * on the popover: `--galaxie-wl-pop-place` (below, above), `--galaxie-wl-pop-align`
 * (left, center, right) and the two offsets in px. The real popover is fixed
 * to the viewport and kept on screen; the editor preview is placed inside its
 * anchor instead.
 */
function place(box: HTMLElement, trigger: HTMLElement, within?: HTMLElement): void {
  const style = getComputedStyle(box)
  const read = (name: string) => style.getPropertyValue(name).trim()
  const number = (name: string, fallback: number) => {
    const value = Number.parseFloat(read(name))
    return Number.isFinite(value) ? value : fallback
  }

  const align = read('--galaxie-wl-pop-align')
  const offsetX = number('--galaxie-wl-pop-x', 0)
  const offsetY = number('--galaxie-wl-pop-y', 8)
  const rect = trigger.getBoundingClientRect()
  const width = box.offsetWidth
  const height = box.offsetHeight
  const edge = 8

  let above = read('--galaxie-wl-pop-place') === 'above'
  let left = (align === 'center' ? rect.left + rect.width / 2 - width / 2 : align === 'right' ? rect.right - width : rect.left) + offsetX

  if (within) {
    const origin = within.getBoundingClientRect()
    box.style.left = `${left - origin.left}px`
    box.style.top = `${(above ? rect.top - height - offsetY : rect.bottom + offsetY) - origin.top}px`
    return
  }

  // No room on the chosen side, and room on the other: open there instead.
  if (above && rect.top - height - offsetY < edge && rect.bottom + offsetY + height <= window.innerHeight - edge) above = false
  else if (!above && rect.bottom + offsetY + height > window.innerHeight - edge && rect.top - height - offsetY >= edge) above = true

  left = Math.min(Math.max(edge, left), window.innerWidth - width - edge)

  box.style.left = `${Math.max(edge, left)}px`
  box.style.top = `${above ? rect.top - height - offsetY : rect.bottom + offsetY}px`
}

/**
 * The editor keeps a popover open for styling. Elementor renders the widget
 * again for every change of text or position, so each new render is placed
 * as it arrives, as well as whatever is on the page when this boots.
 */
function bootPreviews(): void {
  const placeAll = (root: ParentNode) => {
    root.querySelectorAll<HTMLElement>('.galaxie-wishlist-popover.is-preview').forEach((box) => {
      const anchor = box.closest<HTMLElement>('.galaxie-wishlist-anchor')
      const trigger = anchor?.querySelector<HTMLElement>('.galaxie-wishlist-btn')
      if (anchor && trigger) place(box, trigger, anchor)
    })
  }

  placeAll(document)

  type Hooks = { addAction: (name: string, callback: (scope: ArrayLike<HTMLElement>) => void) => void }
  const frontend = () => (window as unknown as { elementorFrontend?: { hooks?: Hooks } }).elementorFrontend
  const hook = () => frontend()?.hooks?.addAction('frontend/element_ready/galaxie-wishlist-button.default', (scope) => {
    if (scope[0]) placeAll(scope[0])
  })

  if (frontend()?.hooks) hook()
  else window.addEventListener('elementor/frontend/init', hook, { once: true })
}
