import * as React from 'react'

import { Button } from '@/ui/button'
import { Field } from '@/ui/field'
import { Input } from '@/ui/input'
import type { AddressValues, CheckoutText } from './types'
import type { AddressErrors } from './validation'

interface AddressStepProps {
  initial: Partial<AddressValues>
  saved: boolean
  /**
   * Owned by the stepper, not by this component: a failed "Continue to
   * payment" has to re-open this form, because per-field messages are
   * invisible while the collapsed summary is showing.
   */
  editing: boolean
  busy: boolean
  errors: AddressErrors
  text: CheckoutText
  shippingMountRef: React.RefObject<HTMLDivElement | null>
  onEdit: () => void
  onSave: (values: AddressValues) => void
  onContinue: () => void
  /** Editor preview: a sample carrier list stands in for WooCommerce's. */
  preview: boolean
}

export function formatAddress(values: Partial<AddressValues>): string {
  const cityLine = [values.city, values.state].filter(Boolean).join(' - ')
  return [values.address_1, values.address_2, cityLine, values.postcode].filter(Boolean).join(', ')
}

/**
 * Two-stage by necessity, not decoration: the whole point of relocating the
 * shipping-method list into this step is to let the customer pick a method
 * before paying — so "Save" must actually save (and trigger WooCommerce's
 * rate calculation) before "Continue" is a separate, deliberate second click,
 * once real rates are on screen. Collapsing these into one click would skip
 * showing shipping options entirely.
 */
function AddressStep({
  initial,
  saved,
  editing,
  busy,
  errors,
  text,
  shippingMountRef,
  onEdit,
  onSave,
  onContinue,
  preview,
}: AddressStepProps) {
  const [values, setValues] = React.useState<AddressValues>({
    address_1: initial.address_1 ?? '',
    address_2: initial.address_2 ?? '',
    city: initial.city ?? '',
    state: initial.state ?? '',
    postcode: initial.postcode ?? '',
    country: initial.country ?? 'BR',
  })

  function set<K extends keyof AddressValues>(key: K, value: AddressValues[K]) {
    setValues((prev) => ({ ...prev, [key]: value }))
  }

  function handleSave(e: React.FormEvent) {
    e.preventDefault()
    if (preview) return
    onSave(values)
  }

  const showSummary = !editing && saved

  return (
    <div className="flex flex-col gap-5">
      {showSummary ? (
        <div className="flex items-start justify-between gap-4 rounded-md border border-border p-4">
          <p className="text-sm text-foreground">{formatAddress(values)}</p>
          <button type="button" onClick={onEdit} className="shrink-0 text-sm font-medium text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground">
            {text.edit}
          </button>
        </div>
      ) : (
        // `noValidate` for the same reason as the profile step: our messages
        // carry WooCommerce's verdict too, the browser's bubble does not.
        <form noValidate onSubmit={handleSave} className="flex flex-col gap-4">
          <Field label={text.postcode} error={errors.postcode} className="@[420px]:max-w-48">
            <Input
              required
              inputMode="numeric"
              autoComplete="postal-code"
              placeholder="00000-000"
              aria-invalid={!!errors.postcode}
              value={values.postcode}
              onChange={(e) => set('postcode', e.target.value)}
            />
          </Field>
          <Field label={text.address1} error={errors.address_1}>
            <Input
              required
              autoComplete="address-line1"
              aria-invalid={!!errors.address_1}
              value={values.address_1}
              onChange={(e) => set('address_1', e.target.value)}
            />
          </Field>
          <Field label={text.address2} hint={text.address2Hint}>
            <Input autoComplete="address-line2" value={values.address_2} onChange={(e) => set('address_2', e.target.value)} />
          </Field>
          <div className="grid grid-cols-[minmax(0,1fr)_5.5rem] gap-4">
            <Field label={text.city} error={errors.city}>
              <Input
                required
                autoComplete="address-level2"
                aria-invalid={!!errors.city}
                value={values.city}
                onChange={(e) => set('city', e.target.value)}
              />
            </Field>
            <Field label={text.state} error={errors.state}>
              <Input
                required
                maxLength={2}
                autoComplete="address-level1"
                aria-invalid={!!errors.state}
                value={values.state}
                onChange={(e) => set('state', e.target.value.toUpperCase())}
              />
            </Field>
          </div>
          <Button type="submit" size="lg" disabled={busy}>
            {text.addressButton}
          </Button>
        </form>
      )}

      {/* Mounted even while the form shows: WooCommerce's list is moved in
          here on every recalculation and must always have somewhere to land. */}
      <div hidden={!showSummary} className="flex flex-col gap-3">
        <h3 className="text-sm font-semibold text-foreground">{text.shippingHeading}</h3>
        {preview ? <SampleShipping /> : <div ref={shippingMountRef} className="galaxie-shipping-mount" />}
        <Button type="button" size="lg" onClick={onContinue} disabled={busy} className="mt-2">
          {text.paymentButton}
        </Button>
      </div>
    </div>
  )
}

/** The shape WooCommerce's own list takes once moved in, so the editor styles the real thing. */
function SampleShipping() {
  const rates = [
    { id: 'sedex', label: 'SEDEX (2 a 4 dias úteis)', price: 'R$ 14,90' },
    { id: 'pac', label: 'PAC (5 a 8 dias úteis)', price: 'R$ 9,67' },
  ]
  return (
    <div className="galaxie-shipping-mount">
      <ul id="shipping_method" className="woocommerce-shipping-methods">
        {rates.map((rate, i) => (
          <li key={rate.id}>
            <input type="radio" name="gx_sample_shipping" id={`gx-sample-${rate.id}`} className="shipping_method" defaultChecked={0 === i} />
            <label htmlFor={`gx-sample-${rate.id}`}>
              {rate.label}: <span className="woocommerce-Price-amount amount">{rate.price}</span>
            </label>
          </li>
        ))}
      </ul>
    </div>
  )
}

export { AddressStep }
