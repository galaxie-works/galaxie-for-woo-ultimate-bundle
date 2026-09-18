/**
 * The kit entry (`assets/dist/galaxie-kit.js`), loaded on every storefront page
 * once "Popup do kit" is set (PHP Kit\Launcher): the kit store, the launcher
 * badge, the Kit Progress widget, the Buy Box kit button and the cart's
 * "Editar kit". The Kit Builder's code is a chunk kit-open.ts loads when the
 * popup opens. It imports no CSS: the launcher's badge style is printed by the
 * server, and the widgets load `galaxie.css` with the main entry.
 *
 * `galaxie.js` does not include any of this, so a page with both runs one kit.
 */

import { bootKitStore } from '@/globals/kit-store'
import type { GiftWrapConfig } from '@/globals/kit-store'
import { bootKitOpen } from '@/globals/kit-open'
import { bootKitBuyBox } from '@/globals/kit-buy-box'
import { bootKitLauncher } from '@/globals/kit-launcher'
import { bootKitProgress } from '@/globals/kit-progress'
import { bootKitCart } from '@/globals/kit-cart'

function boot(): void {
  const config = (window as unknown as { __GALAXIE_WOO__?: { giftWrap?: GiftWrapConfig } }).__GALAXIE_WOO__ ?? {}

  // The store first, so every part below reads the same kit.
  bootKitStore(config.giftWrap)
  bootKitOpen()
  bootKitBuyBox()
  bootKitLauncher()
  bootKitProgress()
  bootKitCart()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot)
} else {
  boot()
}
