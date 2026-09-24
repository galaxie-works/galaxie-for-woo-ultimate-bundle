import * as React from 'react'

import type { CheckoutText } from './types'

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
 * into `paymentMountRef` by the parent (see native-checkout.ts relocatePayment).
 * This component just provides the mount point and the "delivering to" recap.
 */
function PaymentStep({ addressSummary, text, onBack, paymentMountRef, preview }: PaymentStepProps) {
  return (
    <div className="flex flex-col gap-5">
      <div className="flex items-start justify-between gap-4 rounded-md border border-border p-4 text-sm">
        <span className="text-muted-foreground">
          {text.deliveringTo}: <span className="text-foreground">{addressSummary}</span>
        </span>
        <button type="button" onClick={onBack} className="shrink-0 font-medium text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground">
          {text.edit}
        </button>
      </div>

      {preview ? (
        <div className="galaxie-payment-mount">
          <div id="payment">
            <p className="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground">{text.previewPayment}</p>
            <div className="form-row place-order">
              <button type="button" id="place_order" className="button alt">
                Finalizar pedido
              </button>
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
