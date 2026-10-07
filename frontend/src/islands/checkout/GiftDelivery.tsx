import { cn } from '@/lib/cn'
import { useUi } from '@/lib/pix'
import type { GiftCheckoutConfig } from '@/globals/gift-checkout'

/**
 * The delivery step, for a gift from a shared wish list: who it goes to, with
 * the address protected — the server ships it to the list owner's saved
 * address and never sends that address here (PHP Modules\Wishlist\Gifts). The
 * form under it stays, as the buyer's own address for billing; the rates
 * listed are already quoted for the owner's address.
 */
export function GiftDelivery({ gift }: { gift: GiftCheckoutConfig }) {
  const { cls } = useUi()

  return (
    <div className="galaxie-gift-notice gx-co-gift" role="status">
      <p className={cn('gx-co-body', cls.body)}>{gift.delivery || gift.notice}</p>
      {gift.billingHint && <p className={cn('gx-co-body', cls.small)}>{gift.billingHint}</p>}
    </div>
  )
}
