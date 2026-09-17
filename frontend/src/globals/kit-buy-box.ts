/**
 * "Montar um kit ou presente" in the Galaxie Buy Box (BuyBoxWidget, section
 * "Kit (Presente)").
 *
 * One button whose label follows the kit being built (kit-store.ts):
 * - no kit: "Montar um kit ou presente" — opens the kit popup on the name step,
 *   starting with the chosen candle and quantity;
 * - a kit open: "Adicionar ao kit {kit}" — adds the candle, updates the badge
 *   and the widgets from the answer, and says so in a toast;
 * - a kit whose box cannot take this candle: disabled, "Não cabe na caixa deste
 *   kit"; otherwise the quantity field is capped to what fits.
 * With no valid variation, the click gets the same Buy Box alert as Add to Cart.
 *
 * The page is cached, so the widget prints the button hidden (`is-loading`) and
 * with no label of the visitor's; it shows once the kit is known. What it does
 * print is the product's candles' packing sizes (`data-candles`), which is what
 * lets the cap be worked out here with the engine's TypeScript twin.
 */

import { MAX_ITEMS, withDeadline } from '@/lib/gift-packing'
import type { Candle } from '@/lib/gift-packing'
import { maxQuantity } from '@/lib/gift-groups'
import { BUDGET_MS, fillText } from '@/lib/gift-kit'
import { tell } from '@/lib/dialog'
import { blockedByChoice, findAlert, missingChoice, wooChoiceNotice } from '@/globals/buy-box-alert'
import { celebrate, openKit } from '@/globals/kit-builder'
import { currentKit, kitCall, kitConfig, kitLoaded, kitUnits, kitValues, onKit } from '@/globals/kit-store'
import type { KitView } from '@/globals/kit-store'
import { showToast } from '@/globals/toast-notices'

interface ButtonState {
  form: HTMLFormElement
  holder: HTMLElement
  button: HTMLButtonElement
  label: Text | null
  candles: Record<string, Candle>
  busy: boolean
}

/** The text node pixfort printed the label into (its markup wraps it in spans). */
function labelNode(button: HTMLElement, text: string): Text | null {
  const wanted = text.trim()
  const walker = document.createTreeWalker(button, NodeFilter.SHOW_TEXT)

  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    if ((node.nodeValue ?? '').trim() === wanted && wanted !== '') return node as Text
  }

  return null
}

function quantityField(form: HTMLFormElement): HTMLInputElement | HTMLSelectElement | null {
  return form.querySelector<HTMLInputElement | HTMLSelectElement>('.galaxie-buybox-quantity .qty')
}

function chosen(form: HTMLFormElement): { id: number; qty: number } {
  const variation = Number(form.querySelector<HTMLInputElement>('input[name="variation_id"]')?.value) || 0
  const product = Number(form.querySelector<HTMLInputElement>('input[name="add-to-cart"]')?.value) || 0
  const field = quantityField(form)

  return { id: variation || (form.querySelector('.variations select') ? 0 : product), qty: field ? Math.max(1, Number(field.value) || 1) : 1 }
}

/** Caps the quantity field to what fits, and puts its own limit back without a kit. */
function capQuantity(form: HTMLFormElement, cap: number | null): void {
  const field = quantityField(form)
  if (!field) return

  if (field instanceof HTMLSelectElement) {
    Array.from(field.options).forEach((option) => {
      option.disabled = cap !== null && Number(option.value) > cap
    })
    if (cap !== null && cap > 0 && Number(field.value) > cap) {
      field.value = String(cap)
      field.dispatchEvent(new Event('change', { bubbles: true }))
    }
    return
  }

  if (field.dataset.kitMax === undefined) field.dataset.kitMax = field.getAttribute('max') ?? ''

  const own = field.dataset.kitMax ? Number(field.dataset.kitMax) : Infinity
  const limit = cap === null ? own : Math.min(own, Math.max(1, cap))

  if (Number.isFinite(limit)) field.max = String(limit)
  else field.removeAttribute('max')

  if (cap !== null && cap > 0 && Number(field.value) > limit) {
    field.value = String(limit)
    field.dispatchEvent(new Event('change', { bubbles: true }))
  }
}

const caps = new Map<string, number | null>()

/**
 * How many of this candle the kit's box still takes, or null when the search
 * ran out of time: then nothing is capped or disabled (the server checks the
 * add itself). Asked once per kit state and candle.
 */
function capFor(kit: KitView, candle: Candle): number | null {
  const box = kit.box
  if (!box) return 0

  const units = kitUnits(kit)
  if (units.length >= MAX_ITEMS) return 0

  const key = JSON.stringify([box.id, units, candle, kit.options])
  if (caps.has(key)) return caps.get(key) as number | null

  const found = withDeadline(BUDGET_MS, () => maxQuantity(box.shape, units, candle, 0, kit.options))
  const cap = found.expired ? null : found.value

  if (caps.size > 50) caps.clear()
  caps.set(key, cap)
  return cap
}

function paint(state: ButtonState): void {
  const { form, holder, button } = state
  const kit = currentKit()

  holder.classList.toggle('is-loading', !kitLoaded())
  if (!kitLoaded()) return

  let text = holder.dataset.textStart ?? ''
  let disabled = false
  let cap: number | null = null

  if (kit) {
    text = fillText(holder.dataset.textAdd ?? '', kitValues(kit))

    const { id } = chosen(form)
    const candle = id ? state.candles[String(id)] : undefined

    if (candle && kit.box) {
      cap = capFor(kit, candle)

      if (cap !== null && cap < 1) {
        disabled = true
        text = holder.dataset.textFull ?? text
      }
    }
  }

  if (state.label) state.label.nodeValue = text
  button.disabled = disabled || state.busy
  button.setAttribute('aria-disabled', disabled ? 'true' : 'false')
  holder.classList.toggle('is-full', disabled)
  holder.classList.toggle('has-kit', !!kit)

  capQuantity(form, cap)
}

async function add(state: ButtonState, kit: KitView): Promise<void> {
  const { form, holder } = state
  const { id, qty } = chosen(form)
  const wasFull = kit.full

  state.busy = true
  paint(state)
  const result = await kitCall('add_candle', { candle: id, qty })
  state.busy = false
  paint(state)

  if (!result.ok) {
    const message = result.data?.message ?? ''
    if (!(findAlert()?.show('error', message) ?? false)) {
      void tell(form, 'buybox_dialog', { text: message, fallback: 'Não foi possível adicionar ao kit.' })
    }
    return
  }

  const next = result.data?.kit ?? null
  const texts = kitConfig()?.texts ?? {}

  showToast(fillText(texts.added ?? '', kitValues(next)), 'success')

  if (next?.full && !wasFull) celebrate(holder)
}

function init(holder: HTMLElement): void {
  const form = holder.closest<HTMLFormElement>('form.galaxie-buybox')
  const button = holder.querySelector<HTMLButtonElement>('.galaxie-kit-button')
  if (!form || !button) return

  let candles: Record<string, Candle> = {}
  try {
    candles = JSON.parse(holder.dataset.candles ?? '{}') as Record<string, Candle>
  } catch {
    candles = {}
  }

  const state: ButtonState = {
    form,
    holder,
    button,
    label: labelNode(button, holder.dataset.textStart ?? ''),
    candles,
    busy: false,
  }

  const repaint = (): void => paint(state)

  onKit(repaint)
  form.addEventListener('change', repaint)
  form.addEventListener('input', repaint)

  // WooCommerce's variation events and pixfort's +/- (jQuery `change` only).
  const jq = window.jQuery
  if (jq) {
    jq(form).on('found_variation reset_data change', () => window.setTimeout(repaint, 0))
  }

  button.addEventListener('click', (event) => {
    event.preventDefault()
    if (state.busy || button.getAttribute('aria-disabled') === 'true') return

    // The same answer as Add to Cart to a missing choice.
    if (missingChoice(form)) {
      if (!blockedByChoice(form)) wooChoiceNotice(form)
      return
    }

    const { id, qty } = chosen(form)
    if (!id || !candles[String(id)]) return

    const kit = currentKit()

    if (kit) {
      void add(state, kit)
    } else {
      openKit({ pending: { id, qty } })
    }
  })

  repaint()
}

export function bootKitBuyBox(): void {
  const run = (): void => {
    document.querySelectorAll<HTMLElement>('[data-galaxie-kit-button]').forEach((holder) => {
      if (holder.dataset.editing || holder.dataset.kitBound) return
      holder.dataset.kitBound = '1'

      if (!kitConfig()) {
        // No kit popup configured on this page's boot data: nothing to open.
        holder.hidden = true
        return
      }

      init(holder)
    })
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }
}
