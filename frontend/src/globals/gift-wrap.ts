/**
 * "Presente" on the Galaxie Buy Box, and the Galaxie Gift Builder answering it
 * from inside a pixfort popup (Modules/GiftWrap).
 *
 * The checkbox is a real field of the Buy Box form (`galaxie_gift_wrap`), so a
 * native submit carries it with no help and the AJAX add only copies it — see
 * buy-box.ts. What lives here is the popup in between.
 *
 * OPENING. pixfort exposes one global for it, `window.loadPopup({ id })`, the
 * same call its own `.pix-popup-link` click handler makes (dist/front/common.js).
 * It lazy-loads pixfort's dialog code, then shows the `dialog#pix_popup_{id}`
 * already printed in the footer — a popup whose display conditions match this
 * page — or fetches the popup over AJAX and inserts it. So the dialog, and the
 * builder inside it, may not exist yet at the moment we ask.
 *
 * CONTINUING. The click that opened the popup is held as a callback. Confirm or
 * "Seguir sem incrementar o presente" settles the gift, closes the popup and
 * runs it. The merchant's popup has pixfort's close button, click-outside and
 * Esc turned off, so those two buttons are the only ways out and there is no
 * "closed without choosing" to handle.
 *
 * CLOSING. pixfort exports no close function to the page: the popup manager
 * (`M` in dist/front/dialog.*.js, webpack module 1895) stays inside its chunk.
 * What it does offer is one delegated body handler, bound when a popup opens:
 *
 *     $("body").on("click", "dialog .pix-popup-close,
 *       dialog .pix-dialog-backdrop:not(.is-disabled), .pix-popup .pix-close-popup",
 *       … i[0].close(), l.getPopup(t).closePopup() )
 *
 * The three "disable" options only reach the first two selectors — the close
 * button is hidden (`popup-close-none`), the backdrop gets `is-disabled`, Esc is
 * skipped for `pix-disable-esc` — and never `.pix-close-popup`. Clicking an
 * element with that class inside the dialog is therefore pixfort's own close,
 * with all three off. The close button is a fallback behind it, and the native
 * `dialog.close()` behind that.
 */

type Resolution = 'confirm' | 'bypass'

interface GiftItem {
  name: string
  meta: string
  image: string
}

interface Pending {
  form: HTMLFormElement
  block: HTMLElement
  item: GiftItem
  popupId: string
  proceed: (() => void) | null
  done: boolean
}

interface VariationImagePayload {
  image?: { gallery_thumbnail_src?: string; thumb_src?: string }
}

type LoadPopup = (options: { id: string }) => unknown

/**
 * How long a popup gets to appear before the click it held goes ahead without
 * it. A popup that never loads (deleted, unpublished, a failed request) would
 * otherwise leave the buttons doing nothing at all; the item is still flagged
 * as a gift, it just skips the builder.
 */
const OPEN_TIMEOUT = 10000

let pending: Pending | null = null
let stopWatching: (() => void) | null = null

/** How each form's gift was last settled, until the choice or the cart changes. */
const resolutions = new WeakMap<HTMLFormElement, Resolution>()
const variationImages = new WeakMap<HTMLFormElement, string>()

function loadPopup(): LoadPopup | null {
  const fn = (window as unknown as { loadPopup?: LoadPopup }).loadPopup
  return typeof fn === 'function' ? fn : null
}

function giftBlock(form: HTMLFormElement): HTMLElement | null {
  return form.querySelector<HTMLElement>('[data-galaxie-giftwrap]')
}

function checkbox(block: HTMLElement | null): HTMLInputElement | null {
  return block?.querySelector<HTMLInputElement>('input[type="checkbox"]') ?? null
}

/** Whether the shopper ticked "Estou comprando um presente" on this form. */
export function giftWrapChecked(form: HTMLFormElement): boolean {
  return !!checkbox(giftBlock(form))?.checked
}

/**
 * Called by Add to Cart and Buy Now before they act. True when the gift popup
 * took the click over — the caller stops, and `proceed` runs once the shopper
 * confirms or continues without extras.
 *
 * Not taken over: the box unticked, a gift already settled for this choice
 * (through "Configurar presente", say), no popup chosen, or no pixfort.
 */
export function interceptForGift(form: HTMLFormElement, proceed: () => void): boolean {
  const block = giftBlock(form)

  if (!block || !checkbox(block)?.checked) return false
  if (resolutions.has(form)) return false
  if (!block.dataset.popup || !loadPopup()) return false

  open(form, block, proceed)
  return true
}

/** After an add succeeded: the next one is a new gift, and asks again. */
export function giftWrapAdded(form: HTMLFormElement): void {
  if (!resolutions.delete(form)) return

  const block = giftBlock(form)
  if (block) paintBlock(form, block)
}

function describe(form: HTMLFormElement, block: HTMLElement): GiftItem {
  const choices = Array.from(form.querySelectorAll<HTMLSelectElement>('.variations select'))
    .filter((select) => select.value !== '')
    .map((select) => select.selectedOptions[0]?.textContent?.trim() ?? '')
    .filter((label) => label !== '')

  const field = form.querySelector<HTMLInputElement | HTMLSelectElement>('.galaxie-buybox-quantity .qty')
  const quantity = field ? Number(field.value) || 1 : 1
  const unit = (quantity === 1 ? block.dataset.unitOne : block.dataset.unitMany) || '%d'

  return {
    name: block.dataset.productName ?? '',
    meta: [...choices, unit.replace('%d', String(quantity))].join(' · '),
    image: variationImages.get(form) || block.dataset.productImage || '',
  }
}

function open(form: HTMLFormElement, block: HTMLElement, proceed: (() => void) | null): void {
  const popupId = block.dataset.popup ?? ''
  const load = loadPopup()

  if (!popupId || !load) return

  stopWatching?.()

  const current: Pending = { form, block, item: describe(form, block), popupId, proceed, done: false }
  pending = current
  stopWatching = watch(current)

  // pixfort's loader is async; a rejection is left to the timeout in watch().
  Promise.resolve(load({ id: popupId })).catch(() => undefined)
}

/**
 * Paints the builder as soon as the popup's markup exists, until the popup is
 * on screen — or, if it never gets there, lets the held click go ahead. Returns
 * the function that stops watching.
 */
function watch(current: Pending): () => void {
  const observer = new MutationObserver(() => check())

  const stop = (): void => {
    observer.disconnect()
    window.clearTimeout(timer)
  }

  const check = (): void => {
    const dialog = document.getElementById(`pix_popup_${current.popupId}`)
    if (!dialog) return

    paintBuilders(dialog)

    // pixfort adds `displayed` once the popup is shown; its content is in place by then.
    if (dialog.classList.contains('displayed')) stop()
  }

  const timer = window.setTimeout(() => {
    if (current.done) return

    stop()
    current.done = true
    if (pending === current) pending = null
    current.proceed?.()
  }, OPEN_TIMEOUT)

  observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] })
  check()

  return stop
}

function resolve(kind: Resolution, trigger: HTMLElement): void {
  const current = pending && !pending.done ? pending : null

  if (current) {
    current.done = true
    pending = null
    stopWatching?.()
    stopWatching = null

    resolutions.set(current.form, kind)
    paintBlock(current.form, current.block)
  }

  closePopup(trigger.closest<HTMLElement>('dialog, .pix-popup'))
  current?.proceed?.()
}

function isOpen(dialog: HTMLElement): boolean {
  return dialog.classList.contains('displayed') || (dialog instanceof HTMLDialogElement && dialog.open)
}

/** pixfort's own close, whatever the popup's close options — see the file header. */
function closePopup(dialog: HTMLElement | null): void {
  if (!dialog || !isOpen(dialog)) return

  const hook = document.createElement('span')
  hook.className = 'pix-close-popup'
  hook.hidden = true
  dialog.appendChild(hook)
  hook.click()
  hook.remove()

  if (!isOpen(dialog)) return

  // pixfort's handler was not bound: its close button, then the browser's.
  dialog.querySelector<HTMLElement>('.pix-popup-close')?.click()

  if (!isOpen(dialog)) return

  if (dialog instanceof HTMLDialogElement && dialog.open) dialog.close()
  dialog.classList.remove('transitioned', 'displayed')
}

function setText(el: Element | null, text: string): void {
  if (el && el.textContent !== text) el.textContent = text
}

function paintBuilders(root: ParentNode): void {
  const item = pending && !pending.done ? pending.item : null

  root.querySelectorAll<HTMLElement>('[data-galaxie-gift-builder]').forEach((builder) => {
    if (builder.dataset.sample) return

    const empty = builder.querySelector<HTMLElement>('[data-gift-empty]')
    const card = builder.querySelector<HTMLElement>('[data-gift-item]')

    if (empty) empty.hidden = !!item
    if (!card) return

    card.hidden = !item
    if (!item) return

    setText(card.querySelector('[data-gift-name]'), item.name)
    setText(card.querySelector('[data-gift-meta]'), item.meta)

    const image = card.querySelector<HTMLImageElement>('[data-gift-image]')
    if (image) {
      if (item.image && image.getAttribute('src') !== item.image) image.src = item.image
      image.hidden = !item.image
    }
  })
}

/** The button shows while the box is ticked; the summary once the gift is settled. */
function paintBlock(form: HTMLFormElement, block: HTMLElement): void {
  const checked = !!checkbox(block)?.checked

  const button = block.querySelector<HTMLElement>('.galaxie-giftwrap-configure')
  if (button) button.hidden = !checked || !block.dataset.popup

  const summary = block.querySelector<HTMLElement>('.galaxie-giftwrap-summary')
  if (summary) {
    const kind = checked ? resolutions.get(form) : undefined
    const text = kind === 'confirm' ? summary.dataset.textConfirm : kind === 'bypass' ? summary.dataset.textBypass : ''

    setText(summary, text ?? '')
    summary.hidden = !text
  }
}

function initBlock(form: HTMLFormElement, block: HTMLElement): void {
  const box = checkbox(block)

  box?.addEventListener('change', () => {
    if (!box.checked) resolutions.delete(form)
    paintBlock(form, block)
  })

  block.querySelector<HTMLElement>('.galaxie-giftwrap-configure')?.addEventListener('click', (event) => {
    event.preventDefault()
    open(form, block, null)
  })

  // Another size or quantity is another gift: settle it again.
  form.addEventListener('change', (event) => {
    if (event.target === box) return
    if (resolutions.delete(form)) paintBlock(form, block)
  })

  const jq = window.jQuery
  if (jq) {
    jq(form).on('found_variation', (_event: unknown, ...args: unknown[]) => {
      const image = (args[0] as VariationImagePayload | undefined)?.image
      const src = image?.gallery_thumbnail_src || image?.thumb_src || ''

      if (src) variationImages.set(form, src)
      else variationImages.delete(form)
    })
    jq(form).on('reset_data', () => {
      variationImages.delete(form)
    })
  }

  paintBlock(form, block)
}

export function bootGiftWrap(): void {
  const run = () => {
    document.querySelectorAll<HTMLFormElement>('form.galaxie-buybox').forEach((form) => {
      const block = giftBlock(form)

      // The editor renders the checked state on request; leave it as drawn.
      if (block && !block.dataset.editing) initBlock(form, block)
    })

    // Delegated: the builder arrives with its popup, long after boot.
    document.addEventListener('click', (event) => {
      const trigger = (event.target as Element | null)?.closest<HTMLElement>('[data-galaxie-gift-action]')
      if (!trigger || trigger.closest<HTMLElement>('[data-galaxie-gift-builder]')?.dataset.sample) return

      const kind = trigger.dataset.galaxieGiftAction
      if (kind !== 'confirm' && kind !== 'bypass') return

      event.preventDefault()
      resolve(kind, trigger)
    })
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }
}
