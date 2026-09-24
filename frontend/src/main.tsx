import '@/styles/index.css'

import { bootElementorIslands, mountIslands, registerIsland } from '@/runtime'
import { Demo } from '@/islands/demo'
import { Checkout } from '@/islands/checkout'
import { Login } from '@/islands/login'
import { MyAccount } from '@/islands/my-account'
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
import { bootAccountMenu } from '@/globals/account-menu'
import { bootAccountScreens } from '@/globals/account-screens'
import { bootAddressBook, type AddressBookConfig } from '@/globals/address-book'
import { bootPaymentMethods } from '@/globals/payment-methods'
import { bootWishlistAccount } from '@/globals/wishlist-account'
import { bootSharedWishlist } from '@/globals/shared-wishlist'
import { bootGiftCheckout, type GiftCheckoutConfig } from '@/globals/gift-checkout'

// Each module registers its island(s) here as they are ported.
registerIsland('demo', Demo)
registerIsland('checkout', Checkout)
registerIsland('login', Login)
registerIsland('my-account', MyAccount)

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
  bootAccountMenu()
  bootAccountScreens(config.wishlist)
  bootAddressBook(config.addressBook)
  bootPaymentMethods()
  bootWishlistAccount(config.wishlist)
  bootSharedWishlist(config.wishlist)
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
