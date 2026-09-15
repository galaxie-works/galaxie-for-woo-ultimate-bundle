/**
 * The Galaxie Gift Builder, once its popup is open (Modules/GiftWrap).
 *
 * gift-wrap.ts opens the popup and knows which candle the Buy Box is about to
 * add; this file turns the widget's empty frame into the gift.
 *
 * DATA. One request (`galaxie_gift_builder_data`) brings the gifts already in
 * the cart, loose gift candles, the boxes, ribbons and cards on offer, the
 * store's candle sizes and the packing options. The widget's own ids go with it
 * so the server reads that widget's category overrides from what was saved.
 *
 * STATE. Where the candle goes (a new gift, or a gift in the cart it still fits
 * in), which loose candles join, and per gift: its candles as sources (the
 * pending candle, or a cart line), a box, ribbon and card quantities, and one
 * message per card. "Organizar automaticamente" rebuilds the gifts with
 * `arrange()`; everything else is the shopper's choice.
 *
 * DRAWING. The widget printed one `<template>` per repeated part, already
 * carrying the pixfort classes the merchant chose. Parts are cloned from those
 * and their `data-slot`s filled — never built from strings here — so styling
 * reaches them. A structural change redraws the gifts; typing a message only
 * updates its counter and the total, so the textarea keeps focus.
 *
 * RULES. Box choices are only boxes `fits()` accepts; fill bars, "cabe mais",
 * stock and message limits come from gift-groups.ts — the same rules the server
 * runs again on Confirm, in PHP, before anything reaches the cart.
 */

import { arrange, fits, MAX_ITEMS, room } from '@/lib/gift-packing'
import type { Box, Candle, PackingOptions } from '@/lib/gift-packing'
import { fill, messageLength, total, validate } from '@/lib/gift-groups'
import type { Plan, PlanCandle, PlanError, PlanGroup, PlanItem } from '@/lib/gift-groups'
import { post } from '@/lib/wp'
import type { AjaxResult } from '@/lib/wp'

export interface GiftConfig {
  ajaxUrl: string
  nonce: string
}

/** What the Buy Box is about to add. */
export interface PendingCandle {
  productId: number
  variationId: number
  quantity: number
}

interface Option {
  id: number
  name: string
  image: string
  price: number
  stock: number | null
  box?: Box
}

interface Line extends Option {
  key?: string
  quantity: number
  candle: Candle | null
  message?: string
}

interface CartGift {
  id: string
  number: number
  label: string
  candles: Line[]
  box: Option | null
  ribbons: Line[]
  cards: Line[]
}

interface Size extends Candle {
  label: string
}

interface Currency {
  symbol: string
  format: string
  decimals: number
  decimalSep: string
  thousandSep: string
}

interface BuilderData {
  pending: Line
  gifts: CartGift[]
  loose: Line[]
  boxes: Option[]
  ribbons: Option[]
  cards: Option[]
  sizes: Size[]
  inCart: Record<string, number>
  options: PackingOptions
  messageMax: number
  currency: Currency
}

/** Where a gift's candles come from: `pending`, or a cart item key. */
interface Source {
  source: string
  count: number
}

interface GroupState {
  sources: Source[]
  box: number
  ribbons: Map<number, number>
  cards: Map<number, string[]>
}

export interface GiftPlan {
  mode: 'new' | 'extend'
  target: string
  groups: { candles: Source[]; box: number; ribbons: { id: number; quantity: number }[]; cards: { id: number; message: string }[] }[]
}

export interface GiftRequest {
  plan: GiftPlan
  origin: Record<string, string>
}

export interface GiftAdded {
  fragments?: Record<string, string>
  cart_hash?: string
  checkout_url?: string
}

export interface BuilderController {
  token: number
  /** Loaded, and nothing the rules refuse. */
  ready(): boolean
  /** The plan to send, or null when not ready. */
  request(): GiftRequest | null
  /** Says why Confirm cannot go ahead yet. */
  explain(): void
  /** "Organizar automaticamente". */
  organise(): void
}

type Texts = Record<string, string>

const NEW = 'new'

let config: GiftConfig | null = null
const controllers = new WeakMap<HTMLElement, BuilderController>()

export function configureGiftBuilder(value?: GiftConfig): void {
  config = value ?? null
}

/** The controller drawing this builder for this opening, created on first call. */
export function mountBuilder(root: HTMLElement, pending: PendingCandle, token: number): BuilderController {
  const existing = controllers.get(root)
  if (existing && existing.token === token) return existing

  const controller = createController(root, pending, token)
  controllers.set(root, controller)
  return controller
}

export function builderController(root: HTMLElement): BuilderController | null {
  return controllers.get(root) ?? null
}

/** Confirm's plan into the cart, in place of the Buy Box's own add. */
export function addGift(request: GiftRequest, pending: PendingCandle): Promise<AjaxResult<GiftAdded>> {
  if (!config) {
    return Promise.resolve({ success: false, data: { message: 'Não foi possível adicionar o presente ao carrinho.' } })
  }

  return post<GiftAdded>(config.ajaxUrl, 'galaxie_gift_builder_add', config.nonce, {
    product_id: pending.productId,
    variation_id: pending.variationId,
    quantity: pending.quantity,
    plan: JSON.stringify(request.plan),
    ...request.origin,
  })
}

/** The ids Elementor put around the widget: where its saved settings live. */
function origin(root: HTMLElement): Record<string, string> {
  const element = root.closest<HTMLElement>('.elementor-element[data-id]')?.dataset.id
  const post = root.closest<HTMLElement>('[data-elementor-id]')?.dataset.elementorId

  return element && post ? { element_id: element, elementor_post: post } : {}
}

function readTexts(root: HTMLElement): Texts {
  try {
    return JSON.parse(root.dataset.texts ?? '{}') as Texts
  } catch {
    return {}
  }
}

/** A merchant's text with its one placeholder (`%s`, `%d`, `%1$s`) filled. */
function fillText(text: string | undefined, value: string | number): string {
  return (text ?? '').replace(/%(?:1\$)?[sd]/, () => String(value))
}

function clone(root: HTMLElement, name: string): HTMLElement | null {
  const template = root.querySelector<HTMLTemplateElement>(`template[data-gift-tpl="${name}"]`)
  const node = template?.content.firstElementChild?.cloneNode(true)
  return node instanceof HTMLElement ? node : null
}

function slot<T extends Element = HTMLElement>(node: ParentNode, name: string): T | null {
  return node.querySelector<T>(`[data-slot="${name}"]`)
}

function setText(el: Element | null, text: string): void {
  if (el && el.textContent !== text) el.textContent = text
}

function setImage(el: HTMLImageElement | null, src: string): void {
  if (!el) return
  if (src) el.src = src
  el.hidden = !src
}

function repeat<T>(item: T, count: number): T[] {
  return Array.from({ length: Math.max(0, count) }, () => item)
}

function createController(root: HTMLElement, pending: PendingCandle, token: number): BuilderController {
  const texts = readTexts(root)
  const q = <T extends Element = HTMLElement>(selector: string): T | null => root.querySelector<T>(selector)

  const loading = q('[data-gift-loading]')
  const errorBox = q('[data-gift-error]')
  const modesPart = q('[data-gift-modes]')
  const modeList = q('[data-gift-mode-list]')
  const loosePart = q('[data-gift-loose]')
  const looseList = q('[data-gift-loose-list]')
  const arrangeWrap = q('[data-gift-arrange-wrap]')
  const groupsBox = q('[data-gift-groups]')
  const totalWrap = q('[data-gift-total]')
  const confirm = q('[data-galaxie-gift-action="confirm"]')

  let data: BuilderData | null = null
  let failed = false
  let mode = NEW
  let groups: GroupState[] = []
  let errors: PlanError[] = []
  let notice = ''
  const include = new Set<string>()

  // The popup is reused between openings: start from an empty frame.
  for (const part of [modesPart, loosePart, arrangeWrap, totalWrap]) if (part) part.hidden = true
  for (const list of [modeList, looseList, groupsBox]) if (list) list.replaceChildren()
  if (loading) loading.hidden = false
  showError('')
  setConfirm(false)

  const controller: BuilderController = {
    token,
    ready: () => !!data && errors.length === 0,
    request: () => (controller.ready() ? { plan: plan(), origin: origin(root) } : null),
    explain: () => {
      if (failed) showError(texts.error ?? '')
      else if (data && errors.length) showError(explainError(errors[0]))
    },
    organise: () => {
      if (data) organise()
    },
  }

  // Bound once for the life of the popup, which outlives each opening: the
  // click goes to whichever controller is drawing the builder now.
  const arrangeButton = arrangeWrap?.querySelector<HTMLElement>('[data-gift-arrange]')
  if (arrangeButton && !arrangeButton.dataset.bound) {
    arrangeButton.dataset.bound = '1'
    arrangeButton.addEventListener('click', (event) => {
      event.preventDefault()
      controllers.get(root)?.organise()
    })
  }

  if (!config) {
    fail()
  } else {
    void post<BuilderData>(config.ajaxUrl, 'galaxie_gift_builder_data', config.nonce, {
      product_id: pending.productId,
      variation_id: pending.variationId,
      quantity: pending.quantity,
      ...origin(root),
    }).then((json) => {
      // Another opening started meanwhile: this answer is for a candle no longer asked about.
      if (controllers.get(root) !== controller) return

      if (!json.success || !json.data || !json.data.pending) {
        fail()
        return
      }

      data = json.data
      start()
    })
  }

  return controller

  // ---------------------------------------------------------------- flow

  function fail(): void {
    failed = true
    if (loading) loading.hidden = true
    showError(texts.error ?? '')
    setConfirm(false)
  }

  function start(): void {
    if (loading) loading.hidden = true
    drawModes()
    drawLoose()
    organise()
  }

  function current(): BuilderData {
    return data as BuilderData
  }

  /** The gift being added to, or null for a new one. */
  function target(): CartGift | null {
    return mode === NEW ? null : (current().gifts.find((gift) => gift.id === mode) ?? null)
  }

  function lineOf(source: string): { line: Line; added: boolean } | null {
    const d = current()
    if (source === 'pending') return { line: d.pending, added: true }

    const loose = d.loose.find((line) => line.key === source)
    return loose ? { line: loose, added: false } : null
  }

  /**
   * A gift's candles, one per unit, with what is already in it when adding to
   * a gift. A candle with no dimensions still counts (as a zero-sized one) but
   * makes the gift unsized: no box can be offered for it.
   */
  function candlesOf(group: GroupState): { candles: PlanCandle[]; sized: boolean } {
    const out: PlanCandle[] = []
    let sized = true

    const push = (line: Line, count: number, added: boolean): void => {
      if (!line.candle) sized = false
      const candle = line.candle ?? { size: '', length: 0, width: 0, height: 0 }
      for (let i = 0; i < count; i++) out.push({ ...candle, id: line.id, added })
    }

    for (const line of target()?.candles ?? []) push(line, line.quantity, false)

    for (const source of group.sources) {
      const found = lineOf(source.source)
      if (found) push(found.line, source.count, found.added)
    }

    return { candles: out, sized }
  }

  /** A gift in the cart the pending candle can join: no box, or its box still closes. */
  function joinable(gift: CartGift): boolean {
    const d = current()
    const box = gift.box?.box

    if (!box) return true
    if (!d.pending.candle || gift.candles.some((line) => !line.candle)) return false

    const candles = [...gift.candles.flatMap((line) => repeat(line.candle as Candle, line.quantity)), ...repeat(d.pending.candle, d.pending.quantity)]

    return candles.length <= MAX_ITEMS && fits(box, candles, d.options)
  }

  function organise(): void {
    const d = current()
    notice = ''

    const gift = target()
    if (gift) {
      groups = [{ sources: [{ source: 'pending', count: d.pending.quantity }], box: gift.box?.id ?? 0, ribbons: new Map(), cards: new Map() }]
      draw()
      return
    }

    const all: Source[] = [{ source: 'pending', count: d.pending.quantity }]
    for (const line of d.loose) if (line.key && include.has(line.key)) all.push({ source: line.key, count: line.quantity })

    const refs: (Candle & { ref: string })[] = []
    let sized = true

    for (const source of all) {
      const found = lineOf(source.source)
      if (!found?.line.candle) {
        sized = false
        continue
      }
      for (let i = 0; i < source.count; i++) refs.push({ ...found.line.candle, ref: source.source })
    }

    const boxes = d.boxes.filter((option) => option.box && option.stock !== 0).map((option) => ({ ...(option.box as Box), id: option.id, price: option.price }))
    let next: GroupState[] = []

    if (sized && refs.length <= MAX_ITEMS && boxes.length) {
      next = arrange(refs, boxes, d.options).map((packed) => {
        const counts = new Map<string, number>()
        for (const candle of packed.candles) counts.set(candle.ref, (counts.get(candle.ref) ?? 0) + 1)

        return {
          sources: Array.from(counts, ([source, count]) => ({ source, count })),
          box: Number(packed.box.id),
          ribbons: new Map(),
          cards: new Map(),
        }
      })

      if (!next.length) notice = texts.no_fit ?? ''
    }

    groups = next.length ? next : [{ sources: all, box: 0, ribbons: new Map(), cards: new Map() }]
    draw()
  }

  // ------------------------------------------------------------- drawing

  function drawModes(): void {
    const d = current()
    const joinables = d.gifts.filter(joinable)

    if (!modesPart || !modeList || !joinables.length) return

    const options: { value: string; label: string; meta: string }[] = [{ value: NEW, label: texts.mode_new ?? '', meta: '' }]

    for (const gift of joinables) {
      let meta = gift.box?.name ?? texts.no_box ?? ''
      const box = gift.box?.box

      if (box && d.pending.candle) {
        const candles = [...gift.candles.flatMap((line) => repeat(line.candle as Candle, line.quantity)), ...repeat(d.pending.candle, d.pending.quantity)]
        meta += ` · ${roomText(box, candles)}`
      }

      options.push({ value: gift.id, label: fillText(texts.mode_extend, gift.label), meta })
    }

    for (const option of options) {
      const node = clone(root, 'mode')
      if (!node) continue

      const input = slot<HTMLInputElement>(node, 'input')
      if (input) {
        input.name = `galaxie-gift-mode-${token}`
        input.value = option.value
        input.checked = option.value === mode
        input.addEventListener('change', () => {
          if (!input.checked) return
          mode = option.value
          if (loosePart) loosePart.hidden = mode !== NEW || !current().loose.length
          if (arrangeWrap) arrangeWrap.hidden = mode !== NEW
          organise()
        })
      }

      setText(slot(node, 'label'), option.label)
      const meta = slot(node, 'meta')
      setText(meta, option.meta)
      if (meta) meta.hidden = !option.meta

      modeList.appendChild(node)
    }

    modesPart.hidden = false
  }

  function drawLoose(): void {
    const d = current()
    if (!loosePart || !looseList || !d.loose.length) return

    for (const line of d.loose) {
      const node = clone(root, 'loose')
      if (!node || !line.key) continue

      const key = line.key
      const input = slot<HTMLInputElement>(node, 'input')
      input?.addEventListener('change', () => {
        if (input.checked) include.add(key)
        else include.delete(key)
        organise()
      })

      setText(slot(node, 'label'), `${line.quantity} × ${line.name}`)
      looseList.appendChild(node)
    }

    loosePart.hidden = mode !== NEW
  }

  function draw(): void {
    if (!groupsBox) return

    if (arrangeWrap) arrangeWrap.hidden = mode !== NEW
    groupsBox.replaceChildren(...groups.map(drawGroup).filter((node): node is HTMLElement => !!node))

    refresh()
  }

  function drawGroup(group: GroupState, index: number): HTMLElement | null {
    const d = current()
    const node = clone(root, 'group')
    if (!node) return null

    const gift = target()
    const { candles, sized } = candlesOf(group)

    setText(slot(node, 'title'), gift ? gift.label : fillText(texts.group_title, d.gifts.length + index + 1))
    setText(slot(node, 'candles'), describe(group.sources))

    const existing = slot(node, 'existing')
    if (existing && gift) {
      const parts = [...gift.candles, ...gift.ribbons, ...gift.cards].map((line) => `${line.quantity} × ${line.name}`)
      if (gift.box) parts.splice(gift.candles.length, 0, gift.box.name)
      setText(existing, fillText(texts.existing, parts.join(', ')))
      existing.hidden = false
    }

    // Boxes: none, or any the candles fit in (the one a gift already has even if sold out since).
    const allowed = d.boxes.filter(
      (option) =>
        option.box &&
        sized &&
        candles.length <= MAX_ITEMS &&
        (option.stock !== 0 || option.id === gift?.box?.id) &&
        fits(option.box, candles, d.options)
    )

    if (group.box && !allowed.some((option) => option.id === group.box)) group.box = 0

    const boxes = slot(node, 'boxes')
    if (boxes) {
      const choices: (Option | null)[] = [null, ...allowed]
      for (const option of choices) {
        const button = clone(root, 'box')
        if (!button) continue

        const id = option?.id ?? 0
        button.setAttribute('aria-pressed', group.box === id ? 'true' : 'false')
        setText(slot(button, 'name'), option ? option.name : (texts.no_box ?? ''))
        setText(slot(button, 'price'), option ? money(option.price) : '')
        setImage(slot<HTMLImageElement>(button, 'image'), option?.image ?? '')
        button.addEventListener('click', (event) => {
          event.preventDefault()
          group.box = id
          draw()
        })

        boxes.appendChild(button)
      }
    }

    const fillWrap = slot(node, 'fill-wrap')
    const chosen = d.boxes.find((option) => option.id === group.box)?.box

    if (fillWrap) fillWrap.hidden = !chosen

    if (chosen) {
      const bar = slot(node, 'fill')
      if (bar) bar.style.width = `${fill(chosen, candles, d.sizes, d.options)}%`
      setText(slot(node, 'room'), roomText(chosen, candles))
    }

    drawRows(node, 'ribbons', d.ribbons, group)
    drawRows(node, 'cards', d.cards, group)

    return node
  }

  function drawRows(node: HTMLElement, kind: 'ribbons' | 'cards', options: Option[], group: GroupState): void {
    const wrap = slot(node, `${kind}-wrap`)
    const list = slot(node, kind)

    if (wrap) wrap.hidden = !options.length
    if (!list) return

    const d = current()

    for (const option of options) {
      const row = clone(root, 'row')
      if (!row) continue

      const quantity = kind === 'ribbons' ? (group.ribbons.get(option.id) ?? 0) : (group.cards.get(option.id)?.length ?? 0)
      const soldOut = option.stock === 0
      const max = option.stock === null ? 99 : Math.max(0, option.stock - Math.trunc(d.inCart[String(option.id)] ?? 0))

      setText(slot(row, 'name'), option.name)
      setText(slot(row, 'price'), soldOut ? (texts.out_of_stock ?? '') : money(option.price))
      setImage(slot<HTMLImageElement>(row, 'image'), option.image)

      const set = (value: number): void => {
        const next = Math.max(0, Math.min(max, Math.trunc(value) || 0))

        if (kind === 'ribbons') {
          if (next) group.ribbons.set(option.id, next)
          else group.ribbons.delete(option.id)
        } else {
          const messages = (group.cards.get(option.id) ?? []).slice(0, next)
          while (messages.length < next) messages.push('')
          if (next) group.cards.set(option.id, messages)
          else group.cards.delete(option.id)
        }

        draw()
      }

      const field = slot<HTMLInputElement | HTMLSelectElement>(row, 'qty')
      if (field) {
        field.value = String(quantity)
        field.disabled = soldOut
        if (field instanceof HTMLInputElement) field.max = String(max)
        field.addEventListener('change', () => set(Number(field.value)))
      }

      row.querySelectorAll<HTMLButtonElement>('[data-step]').forEach((button) => {
        button.disabled = soldOut
        button.addEventListener('click', (event) => {
          event.preventDefault()
          set(quantity + Number(button.dataset.step))
        })
      })

      const messages = slot(row, 'messages')
      if (messages && kind === 'cards') {
        ;(group.cards.get(option.id) ?? []).forEach((text, i) => {
          const box = clone(root, 'message')
          if (!box) return

          const input = slot<HTMLTextAreaElement>(box, 'input')
          const count = slot(box, 'count')

          const paint = (): void => {
            const length = messageLength(input?.value ?? '')
            setText(count, d.messageMax > 0 ? `${length}/${d.messageMax}` : String(length))
            box.classList.toggle('is-over', d.messageMax > 0 && length > d.messageMax)
          }

          if (input) {
            input.value = text
            input.addEventListener('input', () => {
              const list = group.cards.get(option.id)
              if (list) list[i] = input.value
              paint()
              refresh()
            })
          }

          paint()
          messages.appendChild(box)
        })
      }

      list.appendChild(row)
    }
  }

  // ---------------------------------------------------------- the plan

  function plan(): GiftPlan {
    const gift = target()

    return {
      mode: gift ? 'extend' : 'new',
      target: gift?.id ?? '',
      groups: groups.map((group) => ({
        candles: group.sources,
        box: group.box,
        ribbons: Array.from(group.ribbons, ([id, quantity]) => ({ id, quantity })),
        cards: Array.from(group.cards).flatMap(([id, messages]) => messages.map((message) => ({ id, message }))),
      })),
    }
  }

  /** The plan as gift-groups.ts checks it — the server runs the PHP twin on the same. */
  function checked(): Plan {
    const d = current()
    const gift = target()
    const stock: Record<string, number | null> = { [String(d.pending.id)]: d.pending.stock }

    const planGroups: PlanGroup[] = groups.map((group) => {
      const option = d.boxes.find((box) => box.id === group.box)
      const items: PlanItem[] = []

      if (option) stock[String(option.id)] = option.stock

      for (const [id, quantity] of group.ribbons) {
        stock[String(id)] = d.ribbons.find((o) => o.id === id)?.stock ?? null
        items.push({ id, kind: 'ribbon', quantity })
      }

      for (const [id, messages] of group.cards) {
        stock[String(id)] = d.cards.find((o) => o.id === id)?.stock ?? null
        for (const message of messages) items.push({ id, kind: 'card', quantity: 1, message })
      }

      return {
        candles: candlesOf(group).candles,
        box: option?.box ? { ...option.box, id: option.id, price: option.price, added: option.id !== gift?.box?.id } : null,
        items,
      }
    })

    return { groups: planGroups, stock, in_cart: d.inCart, message_max: d.messageMax }
  }

  function refresh(): void {
    const d = current()
    const gift = target()
    const lines = [{ price: d.pending.price, quantity: d.pending.quantity }]

    for (const group of groups) {
      const box = d.boxes.find((option) => option.id === group.box)
      if (box && box.id !== gift?.box?.id) lines.push({ price: box.price, quantity: 1 })

      for (const [id, quantity] of group.ribbons) lines.push({ price: d.ribbons.find((o) => o.id === id)?.price ?? 0, quantity })
      for (const [id, messages] of group.cards) lines.push({ price: d.cards.find((o) => o.id === id)?.price ?? 0, quantity: messages.length })
    }

    setText(totalWrap?.querySelector('[data-slot="total"]') ?? null, money(total(lines) / 100))
    if (totalWrap) totalWrap.hidden = false

    errors = validate(checked(), d.options)
    showError(errors.length ? explainError(errors[0]) : notice)
    setConfirm(errors.length === 0)
  }

  // ------------------------------------------------------------- words

  function describe(sources: Source[]): string {
    return sources
      .map((source) => {
        const found = lineOf(source.source)
        return found ? `${source.count} × ${found.line.name}` : ''
      })
      .filter(Boolean)
      .join(' · ')
  }

  /** "Cabe mais 1 × 50g ou 1 × 190g", or "Caixa cheia". */
  function roomText(box: Box, candles: Candle[]): string {
    const d = current()
    const labels = new Map(d.sizes.map((size) => [size.size, size.label]))
    const more = candles.length < MAX_ITEMS ? room(box, candles, d.sizes, d.options) : []

    return more.length ? fillText(texts.room, more.map((size) => `1 × ${labels.get(size) ?? size}`).join(' ou ')) : (texts.full ?? '')
  }

  function explainError(error: PlanError): string {
    const d = current()
    const name = [d.pending, ...d.boxes, ...d.ribbons, ...d.cards].find((option) => String(option.id) === error.id)?.name ?? ''
    const label = target()?.label ?? fillText(texts.group_title, d.gifts.length + error.group + 1)

    switch (error.code) {
      case 'message_too_long':
        return `A mensagem de um cartão do ${label} passa de ${d.messageMax} caracteres.`
      case 'out_of_stock':
        return `Não há estoque suficiente de ${name}.`
      case 'box_too_small':
        return `As velas do ${label} não cabem na caixa escolhida.`
      case 'no_candles':
        return `O ${label} está sem velas.`
      default:
        return `Quantidade inválida no ${label}.`
    }
  }

  function money(amount: number): string {
    const c = current().currency
    const decimals = Math.max(0, Math.trunc(c.decimals))
    const [whole, part = ''] = Math.abs(amount).toFixed(decimals).split('.')
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, c.thousandSep)
    const number = (amount < 0 ? '-' : '') + grouped + (decimals ? c.decimalSep + part : '')

    return c.format.replace('%1$s', c.symbol).replace('%2$s', number).replace(/&nbsp;/g, ' ')
  }

  // ------------------------------------------------------------- chrome

  function showError(text: string): void {
    if (!errorBox) return
    setText(errorBox, text)
    errorBox.hidden = !text
  }

  function setConfirm(enabled: boolean): void {
    if (!confirm) return
    confirm.classList.toggle('is-disabled', !enabled)
    confirm.setAttribute('aria-disabled', enabled ? 'false' : 'true')
  }
}
