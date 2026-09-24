import * as React from 'react'

import { cn } from '@/lib/cn'
import { getGalaxieConfig, post } from '@/lib/wp'
import { AddressStep, formatAddress } from './AddressStep'
import { EntryStep } from './EntryStep'
import {
  fillNativeBilling,
  hasChosenShippingMethod,
  onCheckoutUpdated,
  relocatePayment,
  relocateShippingMethod,
  validateNativeFields,
  waitForCheckoutUpdate,
} from './native-checkout'
import { OrderSummary, readSummaryFragment } from './OrderSummary'
import { PaymentStep } from './PaymentStep'
import { ProfileStep } from './ProfileStep'
import { StepSection, type StepStatus } from './StepSection'
import {
  STEP_ORDER,
  type AddressValues,
  type CheckoutProps,
  type OrderSummaryData,
  type ProfileValues,
  type StepId,
} from './types'
import {
  ADDRESS_NATIVE_FIELDS,
  PROFILE_NATIVE_FIELDS,
  validateAddressStep,
  validateProfileStep,
  type AddressErrors,
  type ProfileErrors,
} from './validation'

function initialStep(props: CheckoutProps): StepId {
  if (props.preview) return props.preview.step
  if (!props.loggedIn) return 'entry'
  if (!props.profile.complete) return 'profile'
  return 'address'
}

/**
 * The checkout: the steps on one side, the order summary on the other.
 *
 * All four steps are always mounted (folded with `hidden`, never
 * conditionally rendered) so the shipping-relocation listener and the
 * native-field mirroring — bound once, for this component's whole lifetime —
 * never lose their mount points across a step change.
 *
 * The store takes no guest orders, so a signed-out shopper only ever has the
 * first step open; everything after it is shown folded, as what is coming.
 */
function Checkout(props: CheckoutProps) {
  const cfg = getGalaxieConfig()
  const { text, layout } = props
  const preview = null !== props.preview

  const [step, setStep] = React.useState<StepId>(() => initialStep(props))
  const [profileValues, setProfileValues] = React.useState<Partial<ProfileValues>>(props.profile.values)
  const [addressValues, setAddressValues] = React.useState<Partial<AddressValues>>(props.address)
  const [addressSaved, setAddressSaved] = React.useState(props.address.has_address)
  const [addressEditing, setAddressEditing] = React.useState(!props.address.has_address)
  const [shippingEverSeen, setShippingEverSeen] = React.useState(false)
  const [shippingNote, setShippingNote] = React.useState(() => shippingNoteOf(props.summary))
  const [busy, setBusy] = React.useState(false)
  const [notice, setNotice] = React.useState<string | null>(null)
  const [profileErrors, setProfileErrors] = React.useState<ProfileErrors>({})
  const [addressErrors, setAddressErrors] = React.useState<AddressErrors>({})

  const shippingMountRef = React.useRef<HTMLDivElement>(null)
  const paymentMountRef = React.useRef<HTMLDivElement>(null)

  // Mirror what we know into the native (hidden) fields, so the real form —
  // the one WooCommerce actually submits — always carries valid data by the
  // time the customer places the order, regardless of which step built it up.
  React.useEffect(() => {
    if (preview) return
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
  }, [preview, profileValues, addressValues, props.userEmail])

  // Shipping relocation: run once now, then again on every WC recalculation.
  // The same round trip rewrites the summary fragment, so the carrier shown
  // on the folded delivery step is read from there too.
  React.useEffect(() => {
    if (preview) return
    function run() {
      if (relocateShippingMethod(shippingMountRef.current)) {
        setShippingEverSeen(true)
      }
      const summary = readSummaryFragment()
      if (summary) setShippingNote(shippingNoteOf(summary))
    }
    run()
    return onCheckoutUpdated(run)
  }, [preview])

  // Already have an address on file (e.g. a returning session) — kick off a
  // rate calculation immediately so shipping options are ready without
  // requiring an extra click.
  React.useEffect(() => {
    if (!preview && props.loggedIn && props.address.has_address) {
      void waitForCheckoutUpdate()
    }
    // Only on mount — this mirrors the address the widget rendered with.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  React.useEffect(() => {
    if (!preview && 'payment' === step) {
      relocatePayment(paymentMountRef.current)
    }
  }, [preview, step])

  function goTo(next: StepId) {
    if (preview) return
    setNotice(null)
    setStep(next)
  }

  async function handleProfileSave(values: ProfileValues) {
    if (preview || !cfg.checkout) return

    // Mirror before asking: the effect above only runs on *committed* state,
    // so the native fields still hold the previous values at this point and
    // WooCommerce would be judging data the shopper has already replaced.
    fillNativeBilling({
      first_name: values.first_name,
      last_name: values.last_name,
      phone: values.phone,
      email: props.userEmail,
    })
    const check = validateProfileStep(values, props.userEmail, validateNativeFields(PROFILE_NATIVE_FIELDS))
    setProfileErrors(check.errors)
    setNotice(check.notice)
    if (!check.ok) return

    setBusy(true)
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
    if (preview || !cfg.checkout) return

    fillNativeBilling({
      address_1: values.address_1,
      address_2: values.address_2,
      city: values.city,
      state: values.state,
      postcode: values.postcode,
      country: values.country,
    })
    const check = validateAddressStep(values, validateNativeFields(ADDRESS_NATIVE_FIELDS))
    setAddressErrors(check.errors)
    setNotice(check.notice)
    if (!check.ok) return

    setBusy(true)
    const res = await post(cfg.checkout.ajaxUrl, 'galaxie_save_address', cfg.checkout.nonce, values)
    if (!res.success) {
      setBusy(false)
      setNotice(res.data?.message ?? props.i18n.genericError)
      return
    }
    setAddressValues(values)
    setAddressSaved(true)
    setAddressEditing(false)
    await waitForCheckoutUpdate()
    setBusy(false)
  }

  function handleContinueToPayment() {
    if (preview) return
    // Re-checked rather than trusted: the address may have been saved before
    // a `updated_checkout` round trip rewrote a native field, and this is the
    // last point where a rejection is still cheap to explain.
    const values: AddressValues = {
      address_1: addressValues.address_1 ?? '',
      address_2: addressValues.address_2 ?? '',
      city: addressValues.city ?? '',
      state: addressValues.state ?? '',
      postcode: addressValues.postcode ?? '',
      country: addressValues.country ?? 'BR',
    }
    const check = validateAddressStep(values, validateNativeFields(ADDRESS_NATIVE_FIELDS))
    if (!check.ok) {
      setAddressErrors(check.errors)
      setAddressEditing(true)
      setNotice(check.notice)
      return
    }

    if (!hasChosenShippingMethod(shippingMountRef.current, shippingEverSeen)) {
      setNotice(props.i18n.noShipping)
      return
    }
    setAddressErrors({})
    setNotice(null)
    setStep('payment')
  }

  const active = STEP_ORDER.indexOf(step)
  const status = (id: StepId): StepStatus => {
    const i = STEP_ORDER.indexOf(id)
    return i === active ? 'active' : i < active ? 'done' : 'upcoming'
  }

  const fullName = [profileValues.first_name, profileValues.last_name].filter(Boolean).join(' ')
  const addressLine = formatAddress(addressValues)

  return (
    <div
      className={cn(
        'gx-co',
        'left' === layout.summaryPosition && 'gx-co--left',
        layout.summarySticky && 'gx-co--sticky'
      )}
    >
      <div className="gx-co-grid">
        <div className="gx-co-main flex min-w-0 flex-col gap-3 @container">
          {notice && (
            <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
              {notice}
            </p>
          )}

          <StepSection
            index={1}
            title={text.stepEntry}
            status={status('entry')}
            editLabel={text.edit}
            className={layout.stepClass}
            summary={
              <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-foreground">{props.userEmail}</span>
                {props.logoutUrl && (
                  <a href={props.logoutUrl} className="underline underline-offset-4 hover:text-foreground">
                    {text.logout}
                  </a>
                )}
              </span>
            }
          >
            <EntryStep
              authCfg={cfg.auth}
              text={text}
              genericError={props.i18n.genericError}
              onVerified={() => window.location.reload()}
              preview={preview}
            />
          </StepSection>

          <StepSection
            index={2}
            title={text.stepProfile}
            status={status('profile')}
            editLabel={text.edit}
            onEdit={() => goTo('profile')}
            className={layout.stepClass}
            summary={[fullName, profileValues.phone].filter(Boolean).join(' · ')}
          >
            <ProfileStep initial={profileValues} busy={busy} errors={profileErrors} text={text} onSave={handleProfileSave} />
          </StepSection>

          <StepSection
            index={3}
            title={text.stepAddress}
            status={status('address')}
            editLabel={text.edit}
            onEdit={() => goTo('address')}
            className={layout.stepClass}
            summary={
              <>
                <span className="block text-foreground">{addressLine}</span>
                {shippingNote && <span className="block">{shippingNote}</span>}
              </>
            }
          >
            <AddressStep
              initial={addressValues}
              saved={addressSaved}
              editing={addressEditing}
              busy={busy}
              errors={addressErrors}
              text={text}
              shippingMountRef={shippingMountRef}
              onEdit={() => setAddressEditing(true)}
              onSave={handleAddressSave}
              onContinue={handleContinueToPayment}
              preview={preview}
            />
          </StepSection>

          <StepSection index={4} title={text.stepPayment} status={status('payment')} editLabel={text.edit} className={layout.stepClass}>
            <PaymentStep
              addressSummary={addressLine}
              text={text}
              onBack={() => goTo('address')}
              paymentMountRef={paymentMountRef}
              preview={preview}
            />
          </StepSection>
        </div>

        <aside className="gx-co-aside min-w-0 @container">
          <OrderSummary
            initial={props.summary}
            text={text}
            openOnPhones={layout.summaryOpenMobile}
            className={layout.summaryClass}
            live={!preview}
          />
        </aside>
      </div>
    </div>
  )
}

/** The chosen carrier, as the summary's shipping row words it ("SEDEX · 2 a 4 dias úteis"). */
function shippingNoteOf(summary: OrderSummaryData): string {
  return summary.rows.find((row) => 'shipping' === row.id)?.note ?? ''
}

export { Checkout }
