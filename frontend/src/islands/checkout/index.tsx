import * as React from 'react'

import { getGalaxieConfig, post } from '@/lib/wp'
import { AddressStep } from './AddressStep'
import { EntryStep } from './EntryStep'
import {
  fillNativeBilling,
  hasChosenShippingMethod,
  onCheckoutUpdated,
  relocatePayment,
  relocateShippingMethod,
  waitForCheckoutUpdate,
} from './native-checkout'
import { PaymentStep } from './PaymentStep'
import { ProfileStep } from './ProfileStep'
import { StepperNav } from './StepperNav'
import type { AddressValues, CheckoutProps, ProfileValues, StepId } from './types'

function initialStep(props: CheckoutProps): StepId {
  if (!props.loggedIn) return 'entry'
  if (!props.profile.complete) return 'profile'
  return 'address'
}

/**
 * The stepper. All four step sections are always mounted (toggled via a
 * `hidden` class, not conditionally rendered) so the shipping-relocation
 * listener and native-field mirroring — bound once, for this component's
 * whole lifetime — never lose their mount points across a step change. Same
 * shape as v1's `initStepper`, ported to React state instead of manual class
 * toggling.
 */
function Checkout(props: CheckoutProps) {
  const cfg = getGalaxieConfig()

  const [step, setStep] = React.useState<StepId>(() => initialStep(props))
  const [profileValues, setProfileValues] = React.useState<Partial<ProfileValues>>(props.profile.values)
  const [addressValues, setAddressValues] = React.useState<Partial<AddressValues>>(props.address)
  const [addressSaved, setAddressSaved] = React.useState(props.address.has_address)
  const [shippingEverSeen, setShippingEverSeen] = React.useState(false)
  const [busy, setBusy] = React.useState(false)
  const [notice, setNotice] = React.useState<string | null>(null)

  const shippingMountRef = React.useRef<HTMLDivElement>(null)
  const paymentMountRef = React.useRef<HTMLDivElement>(null)

  // Mirror what we know into the native (hidden) fields, so the real form —
  // the one WooCommerce actually submits — always carries valid data by the
  // time the customer places the order, regardless of which step built it up.
  React.useEffect(() => {
    fillNativeBilling({
      first_name: profileValues.first_name,
      last_name: profileValues.last_name,
      phone: profileValues.phone,
      email: props.userEmail,
      address_1: addressValues.address_1,
      address_2: addressValues.address_2,
      city: addressValues.city,
      state: addressValues.state,
      postcode: addressValues.postcode,
      country: addressValues.country,
    })
  }, [profileValues, addressValues, props.userEmail])

  // Shipping relocation: run once now, then again on every WC recalculation.
  React.useEffect(() => {
    function run() {
      if (relocateShippingMethod(shippingMountRef.current)) {
        setShippingEverSeen(true)
      }
    }
    run()
    return onCheckoutUpdated(run)
  }, [])

  // Already have an address on file (e.g. a returning session) — kick off a
  // rate calculation immediately so shipping options are ready without
  // requiring an extra click.
  React.useEffect(() => {
    if (props.address.has_address) {
      void waitForCheckoutUpdate()
    }
    // Only on mount — this mirrors the address the widget rendered with.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  React.useEffect(() => {
    if ('payment' === step) {
      relocatePayment(paymentMountRef.current)
    }
  }, [step])

  async function handleProfileSave(values: ProfileValues) {
    if (!cfg.checkout) return
    setBusy(true)
    setNotice(null)
    const res = await post(cfg.checkout.ajaxUrl, 'galaxie_save_profile', cfg.checkout.nonce, values)
    setBusy(false)
    if (!res.success) {
      setNotice(res.data?.message ?? props.i18n.genericError)
      return
    }
    setProfileValues(values)
    setStep('address')
  }

  async function handleAddressSave(values: AddressValues) {
    if (!cfg.checkout) return
    setBusy(true)
    setNotice(null)
    const res = await post(cfg.checkout.ajaxUrl, 'galaxie_save_address', cfg.checkout.nonce, values)
    if (!res.success) {
      setBusy(false)
      setNotice(res.data?.message ?? props.i18n.genericError)
      return
    }
    setAddressValues(values)
    setAddressSaved(true)
    await waitForCheckoutUpdate()
    setBusy(false)
  }

  function handleContinueToPayment() {
    if (!hasChosenShippingMethod(shippingMountRef.current, shippingEverSeen)) {
      setNotice(props.i18n.noShipping)
      return
    }
    setNotice(null)
    setStep('payment')
  }

  const addressSummary = [addressValues.address_1, addressValues.city, addressValues.state]
    .filter(Boolean)
    .join(', ')

  return (
    <div className="flex flex-col gap-6 py-4">
      <StepperNav step={step} />

      {notice && (
        <p role="alert" className="rounded-md border border-destructive/30 bg-destructive/5 px-4 py-2 text-sm text-destructive">
          {notice}
        </p>
      )}

      <div className={'entry' === step ? '' : 'hidden'}>
        <EntryStep authCfg={cfg.auth} genericError={props.i18n.genericError} onVerified={() => window.location.reload()} />
      </div>

      <div className={'profile' === step ? '' : 'hidden'}>
        <ProfileStep initial={profileValues} busy={busy} onSave={handleProfileSave} />
      </div>

      <div className={'address' === step ? '' : 'hidden'}>
        <AddressStep
          initial={addressValues}
          saved={addressSaved}
          busy={busy}
          shippingMountRef={shippingMountRef}
          onSave={handleAddressSave}
          onContinue={handleContinueToPayment}
        />
      </div>

      <div className={'payment' === step ? '' : 'hidden'}>
        <PaymentStep addressSummary={addressSummary} onBack={() => setStep('address')} paymentMountRef={paymentMountRef} />
      </div>
    </div>
  )
}

export { Checkout }
