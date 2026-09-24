/**
 * Opening the kit popup, and loading the Kit Builder's script only then.
 *
 * kit.js (the kit entry) runs on every storefront page for the launcher and
 * badge; the builder's code is only needed once the popup is on screen. This
 * watches the popup — opened by the Buy Box, the progress widget, "Editar kit"
 * or pixfort's own launcher — and imports kit-builder.ts the first time it
 * opens, then hands it the intent (which screen, which candle) of the opening.
 */

import { burst } from '@/globals/confetti'
import { kitConfig } from '@/globals/kit-store'
import { isPopupOpen, openPopup, popupAvailable, popupElement, watchPopup } from '@/lib/pix-popup'

export interface KitIntent {
  screen?: 'welcome' | 'summary'
  /** The candle a product page starts a kit with. */
  pending?: { id: number; qty: number }
}

type Builder = typeof import('@/globals/kit-builder')

let intent: KitIntent | null = null
let builder: Promise<Builder> | null = null
let lastBurst = 0

function loadBuilder(): Promise<Builder> {
  builder ??= import('@/globals/kit-builder').then((module) => {
    module.bootKitBuilder()
    return module
  })

  return builder
}

function show(): void {
  const next = intent ?? {}
  intent = null
  void loadBuilder().then((module) => module.showBuilders(next))
}

/**
 * Opens the kit popup on the screen that fits the intent. False when there is
 * no kit popup or no pixfort on the page (nothing was changed then).
 */
export function openKit(next: KitIntent = {}): boolean {
  const config = kitConfig()
  if (!config) return false

  // Fetched ahead, so the popup is drawn as soon as it shows.
  void loadBuilder()

  if (isPopupOpen(popupElement(config.popup))) {
    intent = next
    show()
    return true
  }

  if (!openPopup(config.popup)) return false

  intent = next
  return true
}

/** Whether openKit() can open the popup on this page. */
export function canOpenKit(): boolean {
  return !!kitConfig() && popupAvailable()
}

/** One burst per filling, however many widgets see it. */
export function celebrate(origin: Element | null, colors: string[] = [], count = 150): void {
  const now = Date.now()
  if (now - lastBurst < 2000) return
  lastBurst = now

  const visible = origin instanceof HTMLElement && origin.offsetParent !== null
  burst({ origin: visible ? origin.getBoundingClientRect() : null, count, colors })
}

export function bootKitOpen(): void {
  const config = kitConfig()
  if (!config) return

  watchPopup(config.popup, () => show())
}
