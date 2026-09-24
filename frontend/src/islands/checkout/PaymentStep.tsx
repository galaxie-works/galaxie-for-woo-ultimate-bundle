import * as React from 'react'

import { cn } from '@/lib/cn'
import { useFieldClass, useUi } from '@/lib/pix'
import { decoratePayment, type NativeDecor } from './native-checkout'
import type { CheckoutUi } from './types'

interface PaymentStepProps {
  paymentMountRef: React.RefObject<HTMLDivElement | null>
  /** Read by the parent to give Stripe's card form the checkout fields' look. */
  stripeProbeRef: React.RefObject<HTMLDivElement | null>
  decor: NativeDecor
  /** Editor preview: WooCommerce's payment block as a sample, in its own markup. */
  sample: string | null
}

/**
 * The native #payment block (payment methods, saved cards, the gateway's
 * fields, place-order button) is moved into `paymentMountRef` by the parent
 * (see native-checkout.ts relocatePayment and decoratePayment). This
 * component provides the mount point and the probe Stripe's appearance is
 * read from. No address recap: the folded delivery step right above already
 * shows the address, with its own "Change".
 *
 * In the editor the mount holds PHP's sample of the same block, decorated by
 * the same code, so every control of the payment sections has something real
 * to act on; a few lines of script stand in for WooCommerce's own (switching
 * methods, showing the new-card fields only for a new card).
 */
function PaymentStep({ paymentMountRef, stripeProbeRef, decor, sample }: PaymentStepProps) {
  const { cls } = useUi<CheckoutUi>()
  const field = useFieldClass()
  const sampleRef = React.useRef<HTMLDivElement>(null)

  React.useEffect(() => {
    const root = sampleRef.current
    if (!root || null === sample) return
    decoratePayment(root, decor)
    return previewBehaviour(root)
  }, [sample, decor])

  return (
    <div className="gx-co-form">
      {null !== sample ? (
        <div ref={sampleRef} className="galaxie-payment-mount" dangerouslySetInnerHTML={{ __html: sample }} />
      ) : (
        <div ref={paymentMountRef} className="galaxie-payment-mount" />
      )}

      <div ref={stripeProbeRef} className="gx-co-stripe-probe" hidden aria-hidden="true">
        <span className={cn('gx-co-label', cls.label)}>x</span>
        <input className={field} tabIndex={-1} readOnly />
        <span className="gx-co-stripe-accent" />
      </div>
    </div>
  )
}

/**
 * What WooCommerce's checkout.js and tokenization-form.js do to the live
 * block, for the editor's sample: one method's box open at a time, and the
 * card fields plus "save this card" only while "use another card" is chosen.
 */
function previewBehaviour(root: HTMLElement): () => void {
  function sync() {
    root.querySelectorAll<HTMLElement>('li.wc_payment_method').forEach((li) => {
      const box = li.querySelector<HTMLElement>('.payment_box')
      const on = !!li.querySelector<HTMLInputElement>(':scope > input:checked')
      if (box) box.style.display = on ? '' : 'none'
    })
    const fresh = !!root.querySelector<HTMLInputElement>('.woocommerce-SavedPaymentMethods-new input:checked')
    root.querySelectorAll<HTMLElement>('.wc-upe-form, .woocommerce-SavedPaymentMethods-saveNew').forEach((el) => {
      el.style.display = fresh ? '' : 'none'
    })
  }
  sync()
  root.addEventListener('change', sync)
  return () => root.removeEventListener('change', sync)
}

export { PaymentStep }
