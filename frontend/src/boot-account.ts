import { bootAccountMenu } from '@/globals/account-menu'
import { bootAccountScreens } from '@/globals/account-screens'
import { bootAddressBook, type AddressBookConfig } from '@/globals/address-book'
import { bootPaymentMethods } from '@/globals/payment-methods'
import { bootWishlistAccount } from '@/globals/wishlist-account'
import { bootSharedWishlist } from '@/globals/shared-wishlist'

type WishlistConfig = Parameters<typeof bootWishlistAccount>[0]

/**
 * The account screens' scripts, one lazily loaded chunk (main.tsx loads it
 * once an account widget is on the page). Same order as they booted in when
 * they were part of galaxie.js.
 */
export function bootAccountGroup(wishlist?: WishlistConfig, addressBook?: AddressBookConfig): void {
  bootAccountMenu()
  bootAccountScreens(wishlist)
  bootAddressBook(addressBook)
  bootPaymentMethods()
  bootWishlistAccount(wishlist)
  bootSharedWishlist(wishlist)
}
