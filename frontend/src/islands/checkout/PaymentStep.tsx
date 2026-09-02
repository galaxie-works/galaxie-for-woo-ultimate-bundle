import * as React from 'react'

interface PaymentStepProps {
  addressSummary: string
  onBack: () => void
  paymentMountRef: React.RefObject<HTMLDivElement | null>
}

/**
 * The native #payment block (payment methods + place-order button) is moved
 * into `paymentMountRef` by the parent (see native-checkout.ts relocatePayment).
 * This component just provides the mount point and the "delivering to" recap.
 */
function PaymentStep({ addressSummary, onBack, paymentMountRef }: PaymentStepProps) {
  return (
    <div className="mx-auto flex max-w-sm flex-col gap-4">
      <div className="flex items-center justify-between rounded-md border border-border p-3 text-sm">
        <span className="text-muted-foreground">Delivering to: {addressSummary}</span>
        <button type="button" onClick={onBack} className="text-muted-foreground underline underline-offset-2">
          Change
        </button>
      </div>

      <div ref={paymentMountRef} className="galaxie-payment-mount" />
    </div>
  )
}

export { PaymentStep }
