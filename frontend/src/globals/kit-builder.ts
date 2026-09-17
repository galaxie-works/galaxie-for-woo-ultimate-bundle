/**
 * The Galaxie Kit Builder, inside its pixfort popup (Modules/GiftWrap).
 *
 * Loaded on demand: kit-open.ts imports this the first time the popup opens.
 *
 * The widget prints every screen, hidden, and nothing about the visitor's kit.
 * This file decides which screen to show when the popup opens — however it was
 * opened: the Buy Box, the launcher, the Kit Progress widget, "Editar kit" —
 * and fills it from the kit endpoint (kit-store.ts).
 *
 * SCREENS
 * - welcome: no kit open. "Montar um kit" starts the steps.
 * - name → box → card: the stepper. Nothing is saved until the card step's
 *   button, which sends everything at once (`start`), with the candle a product
 *   page started the kit with. Next waits for a valid step (a box chosen; a card
 *   choice made and its message within the limit).
 * - continue: the kit exists; what still fits, and "Continuar escolhendo",
 *   which closes the popup and goes to the shop (the only screen that leaves
 *   the page; the summary's button of the same name only closes).
 * - summary: a kit is open. Name, box and card (each "trocar"), the message,
 *   the candles with −/+ and remove, the fill bar, the total and the actions.
 *   Every change goes to the server at once and the screen redraws from its
 *   answer, as do the Buy Box, the badge and the progress widget.
 *
 * Box choices are drawn with the packing engine's TypeScript twin (the same
 * rules the server applies again): a box that cannot hold the candles is shown
 * but disabled, with the reason, and each box says what it takes.
 */

import { fits, MAX_ITEMS } from '@/lib/gift-packing'
import type { Candle } from '@/lib/gift-packing'
import { cleanMessage, messageLength } from '@/lib/gift-groups'
import { cleanName, combos, defaultName, fillText, wording } from '@/lib/gift-kit'
import { closePopup, isPopupOpen } from '@/lib/pix-popup'
import { ask } from '@/lib/dialog'
import { celebrate } from '@/globals/kit-open'
import type { KitIntent } from '@/globals/kit-open'
import { currentKit, kitCall, kitConfig, kitPrevious, kitUnits, kitValues, onKit, refreshKit, roomSentence } from '@/globals/kit-store'
import type { KitAnswer, KitBox, KitCard, KitCatalog, KitPending, KitView } from '@/globals/kit-store'
import type { Wording } from '@/lib/gift-kit'


type Screen = 'welcome' | 'name' | 'box' | 'card' | 'continue' | 'summary'
type Mode = 'new' | 'change-box' | 'change-card'
type Texts = Record<string, string>

interface Controller {
  show(intent: KitIntent): void
  redraw(kit: KitView | null, previous: KitView | null): void
  act(action: string, trigger: HTMLElement): void
}

const controllers = new WeakMap<HTMLElement, Controller>()
let booted = false

function builders(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>('[data-galaxie-kit-builder]')).filter((root) => !root.dataset.sample)
}

/** Draws every Kit Builder on the page for this opening (called by kit-open.ts). */
export function showBuilders(next: KitIntent): void {
  builders().forEach((root) => controller(root).show(next))
}

function controller(root: HTMLElement): Controller {
  let found = controllers.get(root)
  if (!found) {
    found = create(root)
    controllers.set(root, found)
  }
  return found
}

function readTexts(root: HTMLElement): Texts {
  try {
    return JSON.parse(root.dataset.texts ?? '{}') as Texts
  } catch {
    return {}
  }
}

function setText(el: Element | null | undefined, text: string): void {
  if (el && el.textContent !== text) el.textContent = text
}

/**
 * Where a text goes inside pixfort's own element: the innermost element of a
 * single-child chain, so its styling wrappers stay.
 */
function textTarget(el: Element | null): Element | null {
  let node = el
  while (node && node.children.length === 1) node = node.children[0]
  return node
}

/** The text node inside pixfort's button markup whose words are the label. */
function labelTarget(button: Element): Element | null {
  let node: Element | null = button
  while (node && node.children.length > 0) {
    const next: Element | undefined = Array.from(node.children).find((child) => (child.textContent ?? '').trim() !== '')
    if (!next) break
    node = next
  }
  return node
}

function setImage(el: HTMLImageElement | null, src: string): void {
  if (!el) return
  if (src && el.getAttribute('src') !== src) el.src = src
  el.hidden = !src
}

function slot<T extends Element = HTMLElement>(node: ParentNode, name: string): T | null {
  return node.querySelector<T>(`[data-slot="${name}"]`)
}

function clone(root: HTMLElement, name: string): HTMLElement | null {
  const template = root.querySelector<HTMLTemplateElement>(`template[data-kit-tpl="${name}"]`)
  const node = template?.content.firstElementChild?.cloneNode(true)
  return node instanceof HTMLElement ? node : null
}

function setDisabled(button: HTMLElement | null, disabled: boolean): void {
  if (!button) return
  button.classList.toggle('is-disabled', disabled)
  button.setAttribute('aria-disabled', disabled ? 'true' : 'false')
}

function repeat(candle: Candle, count: number): Candle[] {
  return Array.from({ length: Math.max(0, count) }, () => candle)
}

function create(root: HTMLElement): Controller {
  const texts = readTexts(root)
  const config = kitConfig()
  const q = <T extends Element = HTMLElement>(selector: string): T | null => root.querySelector<T>(selector)

  const loading = q('[data-kit-loading]')
  const errorBox = q('[data-kit-error]')
  const starting = q('[data-kit-starting]')
  const steps = q('[data-kit-steps]')
  const screens = new Map<Screen, HTMLElement>()
  root.querySelectorAll<HTMLElement>('[data-kit-screen]').forEach((el) => screens.set(el.dataset.kitScreen as Screen, el))

  let screen: Screen = 'welcome'
  let mode: Mode = 'new'
  let catalog: KitCatalog | null = null
  let pending: KitPending | null = null
  let busy = false
  let form = { name: '', box: 0, card: -1, message: '' }
  let messageTimer = 0

  const confetti = root.dataset.confetti === '1'

  // ------------------------------------------------------------------ helpers

  function error(text: string): void {
    setText(errorBox, text)
    if (errorBox) errorBox.hidden = !text
  }

  function kitNames(): string[] {
    return catalog?.kitNames ?? []
  }

  function defaultKitName(): string {
    return defaultName(kitNames(), config?.nameFormat ?? 'Kit %d')
  }

  function labels(): Record<string, string> {
    return Object.fromEntries((catalog?.sizes ?? []).map((size) => [size.size, size.label]))
  }

  function pendingText(): string {
    return pending ? `${pending.qty} × ${pending.name}` : ''
  }

  /** The candles a box has to hold on this screen. */
  function unitsToHold(): Candle[] {
    if (mode === 'change-box') return kitUnits(currentKit())
    return pending ? repeat(pending.candle, pending.qty) : []
  }

  function candlesText(): string {
    if (mode === 'change-box') {
      return (currentKit()?.candles ?? []).map((line) => `${line.qty} × ${line.label || line.name}`).join(' + ')
    }
    return pendingText()
  }

  /**
   * Whether a box takes these candles, and what is left beside them — each
   * asked once per opening (the search is budgeted, but a click redraws every
   * box). An empty box's answer comes with the catalog.
   */
  const packed = new Map<string, { holds: boolean; room: Wording }>()

  function packingFor(box: KitBox, units: Candle[]): { holds: boolean; room: Wording } {
    if (!units.length && box.holds) return { holds: true, room: box.holds }

    const key = `${box.id}|${units.map((u) => `${u.size}:${u.length}x${u.width}x${u.height}`).join(',')}`
    let found = packed.get(key)

    if (!found) {
      // Past the packing search's dozen nothing is a fit (and nothing is searched).
      const holds = !units.length || (units.length <= MAX_ITEMS && fits(box.shape, units, catalog?.options ?? {}))
      const room = holds ? wording(combos(box.shape, units, catalog?.sizes ?? [], catalog?.options ?? {}), labels()) : { state: 'full' as const, combos: '' }
      found = { holds, room }
      packed.set(key, found)
    }

    return found
  }

  function boxOf(id: number): KitBox | null {
    return catalog?.boxes.find((box) => box.id === id) ?? null
  }

  /** One card per card product that has a size for this box. */
  function cardsFor(box: KitBox | null): KitCard[] {
    if (!catalog || !box) return []

    const out: KitCard[] = []
    const seen = new Set<number>()

    for (const card of catalog.cards) {
      if (seen.has(card.parent)) continue
      seen.add(card.parent)

      const id = box.cards?.[String(card.parent)]
      const variation = id ? catalog.cards.find((row) => row.id === id) : undefined
      if (variation && variation.stock !== 0) out.push(variation)
    }

    return out
  }

  // ------------------------------------------------------------------ screens

  function go(next: Screen): void {
    screen = next
    error('')

    screens.forEach((el, name) => {
      el.hidden = name !== next
    })

    const order: Screen[] = ['name', 'box', 'card', 'continue']
    const index = order.indexOf(next)
    const stepped = mode === 'new' && index >= 0

    if (steps) {
      steps.hidden = !stepped
      steps.querySelectorAll<HTMLElement>('[data-step]').forEach((step, i) => {
        step.classList.toggle('is-current', i === index)
        step.classList.toggle('is-done', stepped && i < index)
      })
    }

    if (starting) {
      const show = mode === 'new' && !!pending && ['name', 'box', 'card'].includes(next)
      starting.hidden = !show
      if (show) setText(starting, fillText(texts.starting ?? '', { candles: pendingText() }))
    }

    draw()
  }

  /** "Recuperar kit anterior" wherever the screen has it. */
  function drawPrevious(): void {
    const previous = kitPrevious()

    root.querySelectorAll<HTMLElement>('[data-kit-previous]').forEach((holder) => {
      holder.hidden = !previous
      const label = holder.querySelector('[data-kit-action="restore-previous"]')
      if (previous && label) {
        const text = fillText(texts.action_previous ?? '', { kit: previous.name })
        const target = labelTarget(label)
        if (target && target.textContent !== text) target.textContent = text
      }
    })
  }

  function draw(): void {
    drawPrevious()

    switch (screen) {
      case 'name':
        drawName()
        break
      case 'box':
        drawBoxes()
        break
      case 'card':
        drawCards()
        break
      case 'continue':
        drawContinue()
        break
      case 'summary':
        drawSummary()
        break
    }
  }

  function screenEl(name: Screen): HTMLElement {
    return screens.get(name) as HTMLElement
  }

  function drawName(): void {
    const el = screenEl('name')
    const input = el.querySelector<HTMLInputElement>('[data-kit-name]')
    if (input && document.activeElement !== input) input.value = form.name
    setText(slot(el, 'text'), fillText(texts.name_text ?? '', { kit: defaultKitName() }))
    setDisabled(el.querySelector('[data-kit-action="next"]'), busy)
  }

  function drawBoxes(): void {
    const el = screenEl('box')
    const list = el.querySelector<HTMLElement>('[data-kit-boxes]')
    const none = el.querySelector<HTMLElement>('[data-kit-box-none]')
    if (!list || !catalog) return

    const units = unitsToHold()
    const kit = currentKit()
    const current = mode === 'change-box' ? (kit?.box?.id ?? 0) : 0
    let usable = 0

    const nodes = catalog.boxes.map((box) => {
      const node = clone(root, 'choice')
      if (!node) return null

      const answer = packingFor(box, units)
      const holds = answer.holds
      const sold = (box.available === false || box.stock === 0) && box.id !== current
      const disabled = !holds || sold
      if (!disabled) usable++

      // What it takes empty (worked out on the server, once for everyone), or
      // what is still left beside these candles.
      const room = answer.room
      const holdsText = units.length
        ? holds
          ? roomSentence(room, kitValues(kit))
          : ''
        : room.state === 'full' || room.state === 'unknown'
          ? ''
          : fillText(config?.texts.box_holds ?? '', { combos: room.combos })

      const reason = sold ? (texts.box_sold ?? '') : holds ? '' : fillText(texts.box_reason ?? '', { candles: candlesText() })

      if (disabled && form.box === box.id) form.box = 0

      node.setAttribute('aria-pressed', form.box === box.id ? 'true' : 'false')
      node.setAttribute('aria-disabled', disabled ? 'true' : 'false')
      setText(slot(node, 'name'), box.name)
      setImage(slot<HTMLImageElement>(node, 'image'), box.image)

      for (const [name, value] of [
        ['price', box.priceText ?? ''],
        ['description', box.description],
        ['holds', holdsText],
        ['reason', reason],
      ] as const) {
        const part = slot(node, name)
        setText(part, value)
        if (part) part.hidden = !value
      }

      node.addEventListener('click', (event) => {
        event.preventDefault()
        if (disabled) return
        form.box = box.id
        drawBoxes()
      })

      return node
    })

    list.replaceChildren(...nodes.filter((node): node is HTMLElement => !!node))

    if (none) {
      const text = !catalog.boxes.length ? (texts.box_empty ?? '') : usable ? '' : fillText(texts.box_none ?? '', { candles: candlesText() })
      setText(none, text)
      none.hidden = !text
    }

    setDisabled(el.querySelector('[data-kit-action="next"]'), busy || !form.box)
  }

  function drawCards(): void {
    const el = screenEl('card')
    const list = el.querySelector<HTMLElement>('[data-kit-cards]')
    const wrap = el.querySelector<HTMLElement>('[data-kit-message-wrap]')
    const input = el.querySelector<HTMLTextAreaElement>('[data-kit-message]')
    const count = el.querySelector<HTMLElement>('[data-kit-message-count]')
    if (!list || !catalog) return

    const box = boxOf(mode === 'change-card' ? (currentKit()?.box?.id ?? 0) : form.box)
    const cards = cardsFor(box)
    const choices: { parent: number; label: string; image: string }[] = cards.map((card) => ({
      parent: card.parent,
      label: fillText(texts.card_add ?? '', { preço: card.priceText ?? '', card: card.title }),
      image: card.image,
    }))
    choices.push({ parent: 0, label: texts.card_skip ?? '', image: '' })

    if (form.card > 0 && !cards.some((card) => card.parent === form.card)) form.card = -1

    list.replaceChildren(
      ...choices
        .map((choice) => {
          const node = clone(root, 'choice')
          if (!node) return null

          node.setAttribute('aria-pressed', form.card === choice.parent ? 'true' : 'false')
          setText(slot(node, 'name'), choice.label)
          setImage(slot<HTMLImageElement>(node, 'image'), choice.image)
          node.querySelectorAll<HTMLElement>('[data-slot="price"], [data-slot="description"], [data-slot="holds"], [data-slot="reason"]').forEach((part) => {
            part.hidden = true
          })

          node.addEventListener('click', (event) => {
            event.preventDefault()
            form.card = choice.parent
            drawCards()
          })

          return node
        })
        .filter((node): node is HTMLElement => !!node)
    )

    if (wrap) wrap.hidden = form.card <= 0
    if (input && document.activeElement !== input) input.value = form.message

    const max = catalog.messageMax
    const length = messageLength(cleanMessage(form.message))
    setText(count, max > 0 ? `${length}/${max}` : String(length))
    wrap?.classList.toggle('is-over', max > 0 && length > max)

    setDisabled(el.querySelector('[data-kit-action="next"]'), busy || form.card < 0 || (form.card > 0 && max > 0 && length > max))
  }

  function drawContinue(): void {
    const el = screenEl('continue')
    const kit = currentKit()
    const values = kitValues(kit)

    setText(textTarget(slot(el, 'title')), fillText(texts.continue_title ?? '', values))
    const state = kit?.room.state
    // Nothing settled in time: say nothing rather than a list with a hole in it.
    setText(slot(el, 'text'), kit?.full ? (texts.continue_full ?? '') : state === 'many' || state === 'one' ? fillText(texts.continue_text ?? '', values) : '')
  }

  function drawSummary(): void {
    const el = screenEl('summary')
    const kit = currentKit()

    if (!kit) {
      go('welcome')
      return
    }

    const name = el.querySelector<HTMLInputElement>('[data-kit-summary-name]')
    if (name && document.activeElement !== name) name.value = kit.name
    if (name) name.maxLength = config?.nameMax ?? 40

    const boxRow = el.querySelector<HTMLElement>('[data-kit-summary-box]')
    if (boxRow) {
      setText(slot(boxRow, 'name'), kit.box?.name ?? '')
      setText(slot(boxRow, 'price'), kit.box?.priceText ?? '')
      setImage(slot<HTMLImageElement>(boxRow, 'image'), kit.box?.image ?? '')
    }

    const cardRow = el.querySelector<HTMLElement>('[data-kit-summary-card]')
    if (cardRow) {
      setText(slot(cardRow, 'name'), kit.card?.name ?? texts.summary_no_card ?? '')
      setText(slot(cardRow, 'price'), '')
      setImage(slot<HTMLImageElement>(cardRow, 'image'), kit.card?.image ?? '')
    }

    const messageWrap = el.querySelector<HTMLElement>('[data-kit-summary-message-wrap]')
    const message = el.querySelector<HTMLTextAreaElement>('[data-kit-summary-message]')
    if (messageWrap) messageWrap.hidden = !kit.card
    if (message && document.activeElement !== message) message.value = kit.message
    paintSummaryCount(message?.value ?? kit.message, kit.messageMax)

    const warning = el.querySelector<HTMLElement>('[data-kit-warning]')
    if (warning) warning.hidden = !kit.warnings.includes('card_without_message')

    const list = el.querySelector<HTMLElement>('[data-kit-candles]')
    const empty = el.querySelector<HTMLElement>('[data-kit-candles-empty]')
    if (list) {
      list.replaceChildren(
        ...kit.candles
          .map((line) => {
            const node = clone(root, 'line')
            if (!node) return null

            setText(slot(node, 'name'), line.name)
            setText(slot(node, 'price'), '')
            setText(slot(node, 'qty'), String(line.qty))
            setImage(slot<HTMLImageElement>(node, 'image'), line.image)

            node.querySelectorAll<HTMLButtonElement>('[data-step]').forEach((button) => {
              const step = Number(button.dataset.step)
              const next = line.qty + step
              button.disabled = busy || (step > 0 && next > line.cap) || next < 0

              button.addEventListener('click', (event) => {
                event.preventDefault()
                if (button.disabled) return
                void change('update_candle', { candle: line.id, qty: next })
              })
            })

            node.querySelector<HTMLElement>('[data-kit-remove]')?.addEventListener('click', (event) => {
              event.preventDefault()
              void change('remove_candle', { candle: line.id })
            })

            return node
          })
          .filter((node): node is HTMLElement => !!node)
      )
    }
    if (empty) empty.hidden = kit.candles.length > 0

    const fill = el.querySelector<HTMLElement>('[data-kit-fill]')
    if (fill) {
      fill.classList.toggle('is-full', kit.full)
      const bar = slot(fill, 'bar')
      if (bar) bar.style.width = `${Math.max(0, Math.min(100, kit.fill))}%`
      setText(slot(fill, 'room'), roomSentence(kit.room, kitValues(kit)))
    }

    setText(el.querySelector('[data-kit-total]'), kit.totalText)

    el.querySelectorAll<HTMLElement>('[data-kit-when]').forEach((part) => {
      part.hidden = (part.dataset.kitWhen === 'full') !== kit.full
    })

    el.querySelectorAll<HTMLElement>('[data-kit-action="to-cart"], [data-kit-action="to-cart-new"]').forEach((button) => setDisabled(button, busy || kit.count < 1))
  }

  function paintSummaryCount(value: string, max: number): void {
    const el = screenEl('summary')
    const count = el.querySelector<HTMLElement>('[data-kit-summary-message-count]')
    const length = messageLength(cleanMessage(value))
    setText(count, max > 0 ? `${length}/${max}` : String(length))
    el.querySelector('[data-kit-summary-message-wrap]')?.classList.toggle('is-over', max > 0 && length > max)
  }

  // ------------------------------------------------------------------ server

  async function change(action: string, data: Record<string, string | number> = {}): Promise<KitAnswer | null> {
    if (busy) return null

    busy = true
    draw()
    const result = await kitCall(action, data)
    busy = false

    if (!result.ok) {
      draw()
      error(result.data?.message ?? texts.error ?? '')
      return null
    }

    error('')
    draw()
    return result.data
  }

  async function load(next: KitIntent): Promise<void> {
    if (loading) loading.hidden = false
    screens.forEach((el) => {
      el.hidden = true
    })
    if (steps) steps.hidden = true
    if (starting) starting.hidden = true
    error('')

    const answer = await refreshKit({ catalog: true, pendingId: next.pending?.id, pendingQty: next.pending?.qty })

    if (loading) loading.hidden = true

    if (!answer?.catalog) {
      screenEl('welcome').hidden = false
      error(texts.error ?? '')
      return
    }

    catalog = answer.catalog
    packed.clear()
    pending = next.pending ? (answer.pending ?? null) : null
    mode = 'new'

    if (next.pending && !pending && answer.pendingError) {
      go('welcome')
      error(answer.pendingError)
      return
    }

    if (answer.kit) {
      go('summary')
    } else if (pending) {
      form = { name: '', box: 0, card: -1, message: '' }
      go('name')
    } else {
      form = { name: '', box: 0, card: -1, message: '' }
      go('welcome')
    }
  }

  // ------------------------------------------------------------------ actions

  async function next(): Promise<void> {
    if (screen === 'name') {
      form.name = cleanName(root.querySelector<HTMLInputElement>('[data-kit-name]')?.value ?? '')
      go('box')
      return
    }

    if (screen === 'box' && form.box) {
      if (mode === 'change-box') {
        if (form.box === currentKit()?.box?.id) {
          mode = 'new'
          go('summary')
          return
        }

        if (await change('set_box', { box: form.box })) {
          mode = 'new'
          go('summary')
        }
        return
      }

      go('card')
      return
    }

    if (screen === 'card' && form.card >= 0) {
      if (mode === 'change-card') {
        const ok = (await change('set_card', { card: Math.max(0, form.card) })) && (form.card === 0 || (await change('set_message', { message: form.message })))
        if (ok) {
          mode = 'new'
          go('summary')
        }
        return
      }

      const data: Record<string, string | number> = { name: form.name, box: form.box, card: Math.max(0, form.card), message: form.card > 0 ? form.message : '' }
      if (pending) {
        data.candle = pending.id
        data.qty = pending.qty
      }

      const answer = await change('start', data)
      if (!answer) return

      pending = null
      form = { name: '', box: 0, card: -1, message: '' }
      go('continue')

      if (answer.kit?.full && confetti) celebrate(root)
    }
  }

  function back(): void {
    if (mode !== 'new') {
      mode = 'new'
      go('summary')
      return
    }

    if (screen === 'card') go('box')
    else if (screen === 'box') go('name')
    else if (screen === 'name') {
      if (pending) closePopup(root)
      else go('welcome')
    }
  }

  function keepChoosing(): void {
    const url = config?.continueUrl ?? ''
    closePopup(root)

    if (url && !sameAddress(url)) window.location.href = url
  }

  function sameAddress(url: string): boolean {
    try {
      const target = new URL(url, window.location.href)
      return target.origin === window.location.origin && target.pathname.replace(/\/$/, '') === window.location.pathname.replace(/\/$/, '')
    } catch {
      return false
    }
  }

  async function toCart(again: boolean): Promise<void> {
    const kit = currentKit()

    if (!kit || kit.count < 1) {
      error(texts.need_candle ?? '')
      return
    }

    const answer = await change(again ? 'to_cart_and_new' : 'to_cart')
    if (!answer) return

    if (again) {
      catalog = (await refreshKit({ catalog: true }))?.catalog ?? catalog
      form = { name: '', box: 0, card: -1, message: '' }
      pending = null
      go('name')
    } else {
      closePopup(root)
    }
  }

  async function restorePrevious(trigger: HTMLElement): Promise<void> {
    let result = await kitCall('restore_previous')

    if (!result.ok && result.data?.reason === 'needs_confirm') {
      const yes = await ask(trigger, 'kit_restore', result.data.message ?? '')
      if (!yes) return
      result = await kitCall('restore_previous', { confirm: 1 })
    }

    if (!result.ok) {
      error(result.data?.message ?? texts.error ?? '')
      return
    }

    mode = 'new'
    go('summary')
  }

  async function discard(trigger: HTMLElement): Promise<void> {
    const kit = currentKit()
    if (!kit) return

    const yes = await ask(trigger, 'kit_discard', fillText(texts.discard_confirm ?? '', { kit: kit.name }))
    if (!yes) return

    if (await change('discard')) go('welcome')
  }

  // Fields: the stepper keeps them in `form`; the summary saves as the shopper goes.
  root.addEventListener('input', (event) => {
    const target = event.target as HTMLElement

    if (target.matches('[data-kit-message]')) {
      form.message = (target as HTMLTextAreaElement).value
      drawCards()
    } else if (target.matches('[data-kit-summary-message]')) {
      const value = (target as HTMLTextAreaElement).value
      const kit = currentKit()
      paintSummaryCount(value, kit?.messageMax ?? 0)

      window.clearTimeout(messageTimer)
      messageTimer = window.setTimeout(() => {
        const max = kit?.messageMax ?? 0
        if (max > 0 && messageLength(cleanMessage(value)) > max) return
        if (cleanMessage(value) !== (currentKit()?.message ?? '')) void change('set_message', { message: value })
      }, 700)
    }
  })

  root.addEventListener('change', (event) => {
    const target = event.target as HTMLElement

    if (target.matches('[data-kit-summary-name]')) {
      const value = cleanName((target as HTMLInputElement).value)
      const kit = currentKit()
      if (kit && (value !== kit.name || (value === '' && kit.named))) void change('rename', { name: value })
    }
  })

  root.addEventListener('keydown', (event) => {
    const target = event.target as HTMLElement
    if (event.key !== 'Enter') return

    if (target.matches('[data-kit-name]')) {
      event.preventDefault()
      void next()
    } else if (target.matches('[data-kit-summary-name]')) {
      event.preventDefault()
      ;(target as HTMLInputElement).blur()
    }
  })

  return {
    show(intentNow: KitIntent) {
      void load(intentNow)
    },

    redraw(kit, previous) {
      if (!catalog) return

      drawPrevious()

      // A kit that went away elsewhere (another tab, the cart) leaves the summary.
      if (!kit && screen === 'summary') {
        go('welcome')
        return
      }

      if (kit && !previous && screen === 'welcome') {
        go('summary')
        return
      }

      if (screen === 'summary' || screen === 'continue') draw()

      if (confetti && kit?.full && previous && !previous.full && kit.id === previous.id && isPopupOpen(root.closest<HTMLElement>('dialog, .pix-popup'))) {
        celebrate(root)
      }
    },

    act(action, trigger) {
      if (trigger.getAttribute('aria-disabled') === 'true') return

      switch (action) {
        case 'begin':
          mode = 'new'
          form = { name: '', box: 0, card: -1, message: '' }
          go('name')
          break
        case 'next':
          void next()
          break
        case 'back':
          back()
          break
        case 'continue':
          // Screen 4 of the stepper: close and go to the shop.
          keepChoosing()
          break
        case 'close':
          // The summary's "Continuar escolhendo": close only.
          closePopup(root)
          break
        case 'summary':
          mode = 'new'
          go('summary')
          break
        case 'change-box':
          mode = 'change-box'
          form.box = currentKit()?.box?.id ?? 0
          go('box')
          break
        case 'change-card': {
          const kit = currentKit()
          mode = 'change-card'
          form.card = kit ? (kit.card ? kit.cardParent : 0) : -1
          form.message = kit?.message ?? ''
          go('card')
          break
        }
        case 'to-cart':
          void toCart(false)
          break
        case 'to-cart-new':
          void toCart(true)
          break
        case 'discard':
          void discard(trigger)
          break
        case 'restore-previous':
          void restorePrevious(trigger)
          break
      }
    },
  }
}

/** Called by kit-open.ts when this chunk first loads; binds once. */
export function bootKitBuilder(): void {
  if (booted || !kitConfig()) return
  booted = true

  document.addEventListener('click', (event) => {
    const trigger = (event.target as Element | null)?.closest<HTMLElement>('[data-kit-action]')
    const root = trigger?.closest<HTMLElement>('[data-galaxie-kit-builder]')
    if (!trigger || !root || root.dataset.sample) return

    event.preventDefault()
    controller(root).act(trigger.dataset.kitAction ?? '', trigger)
  })

  onKit((kit, previous) => {
    builders().forEach((root) => controllers.get(root)?.redraw(kit, previous))
  })
}
