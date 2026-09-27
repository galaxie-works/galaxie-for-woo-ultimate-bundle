import * as React from 'react'

import { cn } from '@/lib/cn'
import { PixButton, useUi } from '@/lib/pix'
import type { CheckoutText, CheckoutUi } from './types'

interface ShippingChoiceProps {
  text: CheckoutText
  shippingMountRef: React.RefObject<HTMLDivElement | null>
  busy: boolean
  /** Hidden while there is no address to quote for yet — but always mounted. */
  shown: boolean
  onContinue: () => void
  preview: boolean
}

/**
 * The carriers for the chosen address, and the step forward.
 *
 * Always mounted, hidden until an address is known: WooCommerce's own rate
 * list is moved into `shippingMountRef` on every recalculation and must
 * always have somewhere to land.
 */
function ShippingChoice({ text, shippingMountRef, busy, shown, onContinue, preview }: ShippingChoiceProps) {
  const { cls, buttons } = useUi<CheckoutUi>()

  return (
    <div hidden={!shown} className="gx-co-form">
      <div role="heading" aria-level={3} className={cn('gx-co-label', cls.label)}>
        {text.shippingHeading}
      </div>
      {preview ? <SampleShipping /> : <div ref={shippingMountRef} className="galaxie-shipping-mount" />}
      <PixButton button={buttons.paymentButton} onClick={onContinue} disabled={busy} />
    </div>
  )
}

/**
 * The shape WooCommerce's own list takes once moved in and decorated (see
 * native-checkout.ts `decorateShipping`), so the editor styles the real thing.
 */
function SampleShipping() {
  const { cls } = useUi<CheckoutUi>()
  const rates = [
    { id: 'sedex', label: 'SEDEX (2 a 4 dias úteis)', price: 'R$ 14,90' },
    { id: 'pac', label: 'PAC (5 a 8 dias úteis)', price: 'R$ 9,67' },
  ]
  return (
    <div className="galaxie-shipping-mount">
      <ul id="shipping_method" className="woocommerce-shipping-methods">
        {rates.map((rate, i) => (
          <li key={rate.id} className={cls.rate}>
            <input type="radio" name="gx_sample_shipping" id={`gx-sample-${rate.id}`} className="shipping_method" defaultChecked={0 === i} />
            <label htmlFor={`gx-sample-${rate.id}`} className={cls.rateName}>
              {rate.label}: <span className="woocommerce-Price-amount amount">{rate.price}</span>
            </label>
          </li>
        ))}
      </ul>
    </div>
  )
}

export { ShippingChoice }
