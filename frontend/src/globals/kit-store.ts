/**
 * The kit being built, as every part of the page sees it (Modules/GiftWrap/Kit).
 *
 * One copy of the draft for the Buy Box button, the launcher badge, the Kit
 * Builder popup and the Kit Progress widget: whatever changes it, all of them
 * redraw from the same answer. The server is the only source — every endpoint
 * answers with the draft as it now is — and nothing about it is ever in the
 * page's HTML, which LiteSpeed caches for days.
 *
 * LOADING. `galaxie_kit_get` is uncached and costs a request, so it is only
 * asked when there can be a draft: the server sets a `galaxie_kit` cookie
 * while one exists, and a signed-in shopper's may come from another device.
 * Otherwise the page starts with "no kit" at once. The nonce for changes comes
 * with that answer, or is asked for right before the first change.
 *
 * SYNC. Every answer is broadcast to the store's other tabs, and a page shown
 * again from the back/forward cache asks again.
 */

import type { Box, Candle, PackingOptions } from '@/lib/gift-packing'
import { fillText } from '@/lib/gift-kit'
import type { RoomState } from '@/lib/gift-kit'
import type { AjaxResult } from '@/lib/wp'
import { showToast } from '@/globals/toast-notices'

export interface KitConfig {
  popup: number
  launcherIcon: boolean
  badge: boolean
  texts: Record<string, string>
  continueUrl: string
  nameFormat: string
  nameMax: number
}

export interface GiftWrapConfig {
  ajaxUrl: string
  kit?: KitConfig
}

export interface KitCandle {
  id: number
  name: string
  image: string
  price: number
  qty: number
  size: string
  label: string
  candle: Candle | null
  missing: boolean
  cap: number
}

export interface KitBox {
  id: number
  parent: number
  name: string
  title: string
  image: string
  price: number
  priceText?: string
  stock: number | null
  description: string
  attrs: Record<string, string>
  shape: Box
  /** Card product id => the card variation for this box. */
  cards?: Record<string, number>
  /** What the empty box takes (catalog only), worked out on the server. */
  holds?: { state: RoomState; combos: string }
}

export interface KitCard {
  parent: number
  id: number
  name: string
  title: string
  image: string
  price: number
  priceText?: string
  stock?: number | null
}

export interface KitSize extends Candle {
  label: string
}

export interface KitView {
  id: string
  name: string
  named: boolean
  box: KitBox | null
  card: KitCard | null
  cardParent: number
  message: string
  messageMax: number
  candles: KitCandle[]
  count: number
  full: boolean
  room: { state: RoomState | 'none'; combos: string }
  fill: number
  total: number
  totalText: string
  warnings: string[]
  options: PackingOptions
  sizes: KitSize[]
}

export interface KitCatalog {
  boxes: KitBox[]
  cards: KitCard[]
  sizes: KitSize[]
  options: PackingOptions
  messageMax: number
  shopUrl: string
  kitNames: string[]
}

export interface KitPending {
  id: number
  name: string
  image: string
  price: number
  priceText: string
  stock: number | null
  qty: number
  candle: Candle
}

export interface KitAnswer {
  kit: KitView | null
  nonce: string
  notices?: string[]
  catalog?: KitCatalog
  pending?: KitPending | null
  pendingError?: string
  fragments?: Record<string, string>
  cart_hash?: string
  cartCount?: number
  added?: string
  next?: 'new' | 'close'
  edited?: string
  message?: string
  reason?: string
  cap?: number
  current?: string
}

export interface KitResult {
  ok: boolean
  data: KitAnswer | null
}

type Listener = (kit: KitView | null, previous: KitView | null) => void

let config: GiftWrapConfig | null = null
let kit: KitView | null = null
let loaded = false
let nonce = ''
let channel: BroadcastChannel | null = null
const listeners = new Set<Listener>()

export function kitConfig(): KitConfig | null {
  return config?.kit ?? null
}

export function kitAjaxUrl(): string {
  return config?.ajaxUrl ?? ''
}

export function currentKit(): KitView | null {
  return kit
}

export function kitLoaded(): boolean {
  return loaded
}

/** Called now (once the kit is known) and after every change. Returns the unsubscribe. */
export function onKit(listener: Listener): () => void {
  listeners.add(listener)
  if (loaded) listener(kit, kit)
  return () => listeners.delete(listener)
}

function emit(previous: KitView | null): void {
  listeners.forEach((listener) => {
    try {
      listener(kit, previous)
    } catch (error) {
      console.error('Galaxie kit', error)
    }
  })
}

function apply(data: KitAnswer | null | undefined, broadcast = true): void {
  if (!data || typeof data !== 'object' || !('kit' in data)) return

  const previous = kit
  if (data.nonce) nonce = data.nonce
  kit = data.kit ?? null
  loaded = true

  for (const notice of data.notices ?? []) showToast(notice, 'info')

  emit(previous)

  if (broadcast) channel?.postMessage({ kit })
}

/** Whether the server said there may be a draft (see the file header). */
function mayHaveDraft(): boolean {
  return document.cookie.split(';').some((part) => part.trim().startsWith('galaxie_kit=')) || document.body.classList.contains('logged-in')
}

async function post(action: string, data: Record<string, string | number>, sendNonce: boolean): Promise<{ status: number; text: string }> {
  const body = new FormData()
  body.append('action', `galaxie_kit_${action}`)
  if (sendNonce) body.append('nonce', nonce)
  for (const [key, value] of Object.entries(data)) body.append(key, String(value))

  const response = await fetch(kitAjaxUrl(), { method: 'POST', credentials: 'same-origin', cache: 'no-store', body })
  return { status: response.status, text: await response.text() }
}

function parse(text: string): AjaxResult<KitAnswer> | null {
  try {
    const json = JSON.parse(text) as AjaxResult<KitAnswer>
    return json && typeof json === 'object' ? json : null
  } catch {
    return null
  }
}

/** Reads the draft again; with `catalog`, what the popup lists too. */
export async function refreshKit(extra: { catalog?: boolean; pendingId?: number; pendingQty?: number } = {}): Promise<KitAnswer | null> {
  if (!config) return null

  const data: Record<string, string | number> = {}
  if (extra.catalog) data.catalog = 1
  if (extra.pendingId) {
    data.pending_id = extra.pendingId
    data.pending_qty = extra.pendingQty ?? 1
  }

  try {
    const { text } = await post('get', data, false)
    const json = parse(text)
    if (!json?.success || !json.data) return null

    apply(json.data)
    return json.data
  } catch {
    return null
  }
}

const offline: KitResult = { ok: false, data: { kit: null, nonce: '', message: 'Não foi possível falar com a loja. Tente de novo.' } }

/**
 * One change to the kit. A refusal for the nonce itself (403, or WordPress's
 * bare "-1") gets one retry with a nonce asked for again.
 */
export async function kitCall(action: string, data: Record<string, string | number> = {}): Promise<KitResult> {
  if (!config) return offline

  for (let attempt = 0; attempt < 2; attempt++) {
    if (!nonce || attempt > 0) await refreshKit()

    try {
      const { status, text } = await post(action, data, true)

      if ((status === 403 || text.trim() === '-1') && attempt === 0) continue

      const json = parse(text)
      if (!json) return offline

      const answer = (json.data ?? null) as KitAnswer | null
      // A refusal still carries the draft as it is.
      apply(answer)

      if (json.success && answer?.fragments) {
        document.dispatchEvent(new CustomEvent('galaxie:kit-cart', { detail: answer }))
      }

      return { ok: !!json.success, data: answer }
    } catch {
      return offline
    }
  }

  return offline
}

/** The candles of a kit, one per unit (those still sold). */
export function kitUnits(view: KitView | null): Candle[] {
  const out: Candle[] = []

  for (const line of view?.candles ?? []) {
    if (!line.candle) continue
    for (let i = 0; i < line.qty; i++) out.push(line.candle)
  }

  return out
}

/** "Ainda cabem …" / "Ainda cabe …" / "Caixa completa! 🎉", from the store's texts. */
export function roomSentence(room: { state: string; combos: string } | null | undefined, values: Record<string, string> = {}): string {
  const texts = kitConfig()?.texts ?? {}
  if (!room || room.state === 'none' || room.state === 'unknown') return ''

  const template = room.state === 'full' ? texts.full : room.state === 'one' ? texts.room_one : texts.room_many
  return fillText(template ?? '', { combos: room.combos, ...values })
}

/** The placeholders every kit text may use. */
export function kitValues(view: KitView | null): Record<string, string> {
  if (!view) return {}

  const room = roomSentence(view.room, { kit: view.name, preço: view.totalText })

  return { kit: view.name, combos: view.room.combos, room, preço: view.totalText, n: String(view.count) }
}

export function bootKitStore(value?: GiftWrapConfig): void {
  config = value?.kit?.popup ? value : null
  if (!config) return

  if (typeof BroadcastChannel === 'function') {
    channel = new BroadcastChannel('galaxie-kit')
    channel.onmessage = (event: MessageEvent<{ kit?: KitView | null }>) => {
      if (!event.data || !('kit' in event.data)) return
      const previous = kit
      kit = event.data.kit ?? null
      loaded = true
      emit(previous)
    }
  }

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) void refreshKit()
  })

  if (mayHaveDraft()) {
    void refreshKit().then((answer) => {
      // An answer that never came still has to unblock the page's controls.
      if (!answer && !loaded) {
        loaded = true
        emit(null)
      }
    })
  } else {
    loaded = true
    emit(null)
  }
}
