import '@/styles/index.css'

import { bootElementorIslands, mountIslands, registerIsland } from '@/runtime'
import { bootToastNotices } from '@/globals/toast-notices'
import { bootVariationSwatches } from '@/globals/variation-swatches'
import { bootVariationSpotlight } from '@/globals/variation-spotlight'
import { bootVariationBadgesWidget } from '@/globals/variation-badges-widget'
import { bootBuyBox } from '@/globals/buy-box'
import { bootWishlist } from '@/globals/wishlist'
import { bootQuantityDiscounts } from '@/globals/quantity-discounts'
import { bootProductData } from '@/globals/product-data'
import { bootCart } from '@/globals/cart'
import { bootCartCountdown } from '@/globals/countdown'
import { bootAddressAutocomplete } from '@/globals/address-autocomplete'
import { bootCartFragments } from '@/globals/cart-fragments'
import { bootCoupon } from '@/globals/coupon'
import { bootFreeProgress } from '@/globals/free-progress'
import { bootGiftCheckout, type GiftCheckoutConfig } from '@/globals/gift-checkout'
import type { AddressBookConfig } from '@/globals/address-book'

// Each module registers its island(s) here. Each is a separate chunk, with
// React, fetched only when its mount is on the page (see runtime.ts).
registerIsland('checkout', () => import('@/islands/checkout').then((m) => m.Checkout))
registerIsland('login', () => import('@/islands/login').then((m) => m.Login))
registerIsland('my-account', () => import('@/islands/my-account').then((m) => m.MyAccount))

interface GalaxieConfig {
  toastNotices?: boolean
  variationSwatches?: { attributes?: string[]; buyBox?: { ajaxUrl: string; nonce: string } }
  variationSpotlight?: { ajaxUrl: string; nonce: string }
  wishlist?: { ajaxUrl: string; nonce: string }
  productData?: boolean
  cart?: { ajaxUrl: string; nonce: string }
  addressAutocomplete?: { country: string; placeholder: string }
  addressBook?: AddressBookConfig
  giftCheckout?: GiftCheckoutConfig
}

/**
 * The account screens' widgets (menu, details, interests, communication,
 * delete, orders, payment methods, address book, wishlists). Their code is a
 * chunk of its own, fetched once one of these is on the page. Every screen
 * the account menu swaps in arrives through the menu itself, which is in the
 * same chunk, so its listeners are in place before any swapped-in markup.
 */
const ACCOUNT_SELECTOR = [
  '.galaxie-account-menu',
  '.galaxie-account-menu-select',
  '.galaxie-account-content',
  '.galaxie-details-form',
  '.galaxie-account-interests',
  '.galaxie-account-communication',
  '.galaxie-account-delete',
  '.galaxie-account-orders',
  '.galaxie-account-order',
  '.galaxie-payment-methods',
  '.galaxie-address-book',
  '.galaxie-account-wishlist',
  '.galaxie-shared-wishlist',
].join(', ')

function inElementorEditor(): boolean {
  return (
    document.body.classList.contains('elementor-editor-active') ||
    new URLSearchParams(window.location.search).has('elementor-preview')
  )
}

/**
 * Runs `load` once something matching `selector` is in the page: now, or —
 * for markup drawn later (a popup's content, a section loaded over AJAX) —
 * the first time it appears. In Elementor's editor any widget can be dropped
 * in at any moment, so there it runs straight away.
 */
function whenPresent(selector: string, load: () => void): void {
  if (inElementorEditor() || document.querySelector(selector)) {
    load()
    return
  }

  // One look per burst of changes. A timer, not requestAnimationFrame, which
  // never fires in a background tab.
  let queued = false
  const observer = new MutationObserver(() => {
    if (queued) return
    queued = true
    window.setTimeout(() => {
      queued = false
      if (!document.querySelector(selector)) return
      observer.disconnect()
      load()
    }, 50)
  })
  observer.observe(document.body, { childList: true, subtree: true })
}

function bootAccount(config: GalaxieConfig): void {
  import('@/boot-account')
    .then(({ bootAccountGroup }) => bootAccountGroup(config.wishlist, config.addressBook))
    .catch((error: unknown) => console.error('[galaxie] account scripts failed to load', error))
}

function boot(): void {
  mountIslands()
  bootElementorIslands()

  const config: GalaxieConfig =
    (window as unknown as { __GALAXIE_WOO__?: GalaxieConfig }).__GALAXIE_WOO__ ?? {}

  bootBuyBox(config.variationSwatches?.buyBox)

  // The kit flow is its own entry (kit.ts, `galaxie-kit.js`), loaded wherever
  // the kit popup is set up.
  bootVariationBadgesWidget(config.variationSwatches?.buyBox)
  bootQuantityDiscounts()
  bootCartCountdown()
  bootAddressAutocomplete(config.addressAutocomplete)
  bootCartFragments()
  bootCoupon()
  bootFreeProgress()
  whenPresent(ACCOUNT_SELECTOR, () => bootAccount(config))
  bootGiftCheckout(config.giftCheckout)

  if (config.productData) {
    bootProductData()
  }

  if (config.cart) {
    bootCart(config.cart)
  }

  if (config.toastNotices) {
    bootToastNotices()
  }

  if (config.variationSwatches) {
    bootVariationSwatches(config.variationSwatches)
  }

  if (config.variationSpotlight) {
    bootVariationSpotlight(config.variationSpotlight)
  }

  if (config.wishlist) {
    bootWishlist(config.wishlist)
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot)
} else {
  boot()
}
