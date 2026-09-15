/**
 * "Presente" on the Galaxie Buy Box, and the Galaxie Gift Builder answering it
 * from inside a pixfort popup (Modules/GiftWrap).
 *
 * The checkbox is a real field of the Buy Box form (`galaxie_gift_wrap`), so a
 * native submit carries it with no help and the AJAX add only copies it — see
 * buy-box.ts. What lives here is the popup in between; what happens inside the
 * builder once it is open is gift-builder.ts.
 *
 * OPENING. pixfort exposes one global for it, `window.loadPopup({ id })`, the
 * same call its own `.pix-popup-link` click handler makes (dist/front/common.js).
 * It lazy-loads pixfort's dialog code, then shows the `dialog#pix_popup_{id}`
 * already printed in the footer — a popup whose display conditions match this
 * page — or fetches the popup over AJAX and inserts it. So the dialog, and the
 * builder inside it, may not exist yet at the moment we ask.
 *
 * CONTINUING. The click that opened the popup is held as a callback.
 * - Confirm keeps the builder's plan for this form, closes the popup and runs
 *   the callback; the Buy Box then sends the plan to the gift endpoint instead
 *   of its own add (the whole gift goes in at once). Confirm does nothing while
 *   the builder is loading or the plan breaks a rule; it says why.
 * - "Seguir sem incrementar o presente" closes and runs the callback with no
 *   plan: the Buy Box's own add, with the gift flag alone.
 * The merchant's popup has pixfort's close button, click-outside and Esc turned
 * off, so those two buttons are the only ways out and there is no "closed
 * without choosing" to handle.
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

import { builderController, configureGiftBuilder, mountBuilder } from '@/globals/gift-builder'
import type { GiftConfig, GiftRequest, PendingCandle } from '@/globals/gift-builder'

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
  candle: PendingCandle
  token: number
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
let openings = 0

/** How each form's gift was last settled, until the choice or the cart changes. */
const resolutions = new WeakMap<HTMLFormElement, Resolution>()
/** What Confirm built, for the add that follows. */
const plans = new WeakMap<HTMLFormElement, GiftRequest>()
/**
 * The choice each settlement was made for (product, variation, quantity).
 * Compared on every use rather than trusted to change events: pixfort's +/-
 * quantity buttons only fire jQuery's `change`, which native listeners miss.
 */
const signatures = new WeakMap<HTMLFormElement, string>()
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
 * The gift Confirm built for this form's current choice, or null — no gift,
 * not settled, or settled with "Seguir sem incrementar o presente".
 */
export function giftPlanRequest(form: HTMLFormElement): GiftRequest | null {
  return giftWrapChecked(form) && settled(form) && resolutions.get(form) === 'confirm' ? (plans.get(form) ?? null) : null
}

function signatureOf(candle: PendingCandle): string {
  return `${candle.productId}:${candle.variationId}:${candle.quantity}`
}

/**
 * Whether this form's gift is settled for what the form holds right now. A
 * settlement for another size or quantity is forgotten here, however the
 * change arrived.
 */
function settled(form: HTMLFormElement): boolean {
  if (!resolutions.has(form)) return false
  if (signatures.get(form) === signatureOf(pendingCandle(form))) return true

  forget(form)
  const block = giftBlock(form)
  if (block) paintBlock(form, block)

  return false
}

/** What the form is about to add: the ids and quantity the add would send. */
export function pendingCandle(form: HTMLFormElement): PendingCandle {
  const field = form.querySelector<HTMLInputElement | HTMLSelectElement>('.galaxie-buybox-quantity .qty')

  return {
    productId: Number(form.querySelector<HTMLInputElement>('input[name="add-to-cart"]')?.value) || 0,
    variationId: Number(form.querySelector<HTMLInputElement>('input[name="variation_id"]')?.value) || 0,
    quantity: field ? Number(field.value) || 1 : 1,
  }
}

/**
 * Called by Add to Cart and Buy Now before they act. True when the gift popup
 * took the click over — the caller stops, and `proceed` runs once the shopper
 * confirms or continues without extras.
 *
 * Not taken over: the box unticked, a variable product with no variation
 * resolved yet, a gift already settled for this choice (through "Configurar
 * presente", say), no popup chosen, or no pixfort.
 */
export function interceptForGift(form: HTMLFormElement, proceed: () => void): boolean {
  const block = giftBlock(form)

  if (!block || !checkbox(block)?.checked) return false
  if (!selectionResolved(form)) return false
  if (settled(form)) return false
  if (!block.dataset.popup || !loadPopup()) return false

  open(form, block, proceed)
  return true
}

/**
 * Whether the form names something that can be bought: a simple product, or a
 * variable one whose choices WooCommerce has matched to a variation.
 *
 * Checked here and not left to the Buy Box's own `blocked()`: that one only
 * stops a click when its Alert block can say why, and with no Alert block (or
 * an empty "choose an option" message) it lets the click through on purpose,
 * for WooCommerce to refuse the usual way. Opening the builder in between would
 * ask the shopper to settle a gift for a candle not chosen yet, and only then
 * show the refusal. Declining here keeps that path exactly what it is without
 * the gift box; the builder opens on the first click with a valid choice.
 */
function selectionResolved(form: HTMLFormElement): boolean {
  if (!form.querySelector('.variations select')) return true

  return Number(form.querySelector<HTMLInputElement>('input[name="variation_id"]')?.value) > 0
}

/** After an add succeeded: the next one is a new gift, and asks again. */
export function giftWrapAdded(form: HTMLFormElement): void {
  plans.delete(form)
  signatures.delete(form)
  if (!resolutions.delete(form)) return

  const block = giftBlock(form)
  if (block) paintBlock(form, block)
}

function describe(form: HTMLFormElement, block: HTMLElement): GiftItem {
  const choices = Array.from(form.querySelectorAll<HTMLSelectElement>('.variations select'))
    .filter((select) => select.value !== '')
    .map((select) => select.selectedOptions[0]?.textContent?.trim() ?? '')
    .filter((label) => label !== '')

  const quantity = pendingCandle(form).quantity
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

  const current: Pending = {
    form,
    block,
    item: describe(form, block),
    candle: pendingCandle(form),
    token: ++openings,
    popupId,
    proceed,
    done: false,
  }
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
  const builder = trigger.closest<HTMLElement>('[data-galaxie-gift-builder]')

  // Confirm hands over the builder's plan, or stays open saying what is missing.
  if (current && kind === 'confirm' && builder) {
    const controller = builderController(builder)
    const request = controller && controller.token === current.token ? controller.request() : null

    if (!request) {
      controller?.explain()
      return
    }

    plans.set(current.form, request)
  }

  if (current) {
    current.done = true
    pending = null
    stopWatching?.()
    stopWatching = null

    if (kind === 'bypass') plans.delete(current.form)

    resolutions.set(current.form, kind)
    signatures.set(current.form, signatureOf(current.candle))
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
  const current = pending && !pending.done ? pending : null
  const item = current?.item ?? null

  root.querySelectorAll<HTMLElement>('[data-galaxie-gift-builder]').forEach((builder) => {
    if (builder.dataset.sample) return

    const empty = builder.querySelector<HTMLElement>('[data-gift-empty]')
    const card = builder.querySelector<HTMLElement>('[data-gift-item]')

    if (empty) empty.hidden = !!item

    if (card) {
      card.hidden = !item

      if (item) {
        setText(card.querySelector('[data-gift-name]'), item.name)
        setText(card.querySelector('[data-gift-meta]'), item.meta)

        const image = card.querySelector<HTMLImageElement>('[data-gift-image]')
        if (image) {
          if (item.image && image.getAttribute('src') !== item.image) image.src = item.image
          image.hidden = !item.image
        }
      }
    }

    // Once per opening: the builder asks the cart and the store, then draws.
    if (current) mountBuilder(builder, current.candle, current.token)
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

function forget(form: HTMLFormElement): boolean {
  plans.delete(form)
  signatures.delete(form)
  return resolutions.delete(form)
}

function initBlock(form: HTMLFormElement, block: HTMLElement): void {
  const box = checkbox(block)

  box?.addEventListener('change', () => {
    if (!box.checked) forget(form)
    paintBlock(form, block)
  })

  block.querySelector<HTMLElement>('.galaxie-giftwrap-configure')?.addEventListener('click', (event) => {
    event.preventDefault()
    if (!selectionResolved(form)) return
    open(form, block, null)
  })

  // Another size or quantity is another gift: settle it again.
  form.addEventListener('change', (event) => {
    if (event.target === box) return
    if (forget(form)) paintBlock(form, block)
  })

  const jq = window.jQuery
  if (jq) {
    // pixfort's +/- buttons trigger jQuery's change only; settled() catches it
    // anyway, this keeps the summary line honest straight away.
    jq(form).on('change', (event: unknown) => {
      if ((event as { target?: unknown }).target === box) return
      if (forget(form)) paintBlock(form, block)
    })
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

export function bootGiftWrap(config?: GiftConfig): void {
  configureGiftBuilder(config)

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
