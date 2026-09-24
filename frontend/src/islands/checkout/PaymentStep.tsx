import * as React from 'react'

import { cn } from '@/lib/cn'
import { PixButton, useUi } from '@/lib/pix'
import type { CheckoutText, CheckoutUi } from './types'

interface PaymentStepProps {
  addressSummary: string
  text: CheckoutText
  onBack: () => void
  paymentMountRef: React.RefObject<HTMLDivElement | null>
  /** Editor preview: WooCommerce's payment block is not on the page. */
  preview: boolean
}

/**
 * The native #payment block (payment methods + place-order button) is moved
 * into `paymentMountRef` by the parent (see native-checkout.ts relocatePayment
 * and decoratePayment). This component provides the mount point and the
 * "delivering to" recap.
 */
function PaymentStep({ addressSummary, text, onBack, paymentMountRef, preview }: PaymentStepProps) {
  const { cls, buttons, marker } = useUi<CheckoutUi>()

  return (
    <div className="gx-co-form">
      <div className={cn('gx-co-recap', cls.option)}>
        <p className={cn('gx-co-body', cls.body)}>
          {text.deliveringTo}: {addressSummary}
        </p>
        <PixButton button={buttons.edit} onClick={onBack} />
      </div>

      {preview ? (
        <div className="galaxie-payment-mount">
          <div id="payment">
            <ul className="wc_payment_methods payment_methods methods">
              <li className={cn('wc_payment_method', cls.method)}>
                <input id="gx-sample-pay" type="radio" className="input-radio" defaultChecked />
                <label htmlFor="gx-sample-pay" className={cls.methodName}>
                  Pix
                </label>
                <div className={cn('payment_box', cls.methodBox)}>
                  <p className={cn('gx-co-small', cls.small)}>{text.previewPayment}</p>
                </div>
              </li>
            </ul>
            <div className="form-row place-order">
              <PixButton
                id="place_order"
                button={{ ...buttons.placeOrder, html: buttons.placeOrder.html.split(marker).join('Finalizar pedido') }}
              />
            </div>
          </div>
        </div>
      ) : (
        <div ref={paymentMountRef} className="galaxie-payment-mount" />
      )}
    </div>
  )
}

export { PaymentStep }
