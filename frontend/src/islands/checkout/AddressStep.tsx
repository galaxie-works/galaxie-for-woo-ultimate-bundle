import * as React from 'react'

import { cn } from '@/lib/cn'
import { Input } from '@/ui/input'
import { CoField, PixButton, useFieldClass, useUi } from '@/lib/pix'
import type { AddressValues, CheckoutText, CheckoutUi } from './types'
import type { AddressErrors } from './validation'
import { PlacesSearch } from './PlacesSearch'
import { ShippingChoice } from './ShippingChoice'

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
  const { cls, buttons } = useUi<CheckoutUi>()
  const field = useFieldClass()
  const id = React.useId()
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
    <div className="gx-co-form">
      {showSummary ? (
        <div className={cn('gx-co-recap', cls.option)}>
          <p className={cn('gx-co-body', cls.body)}>{formatAddress(values)}</p>
          <PixButton button={buttons.edit} onClick={onEdit} />
        </div>
      ) : (
        // `noValidate` for the same reason as the profile step: our messages
        // carry WooCommerce's verdict too, the browser's bubble does not.
        <form noValidate onSubmit={handleSave} className="gx-co-form">
          <PlacesSearch
            label={text.addressSearch}
            onPlace={(place) => {
              setValues((prev) => ({
                ...prev,
                address_1: place.address_1 || prev.address_1,
                address_2: prev.address_2 || place.neighbourhood,
                city: place.city || prev.city,
                state: place.state || prev.state,
                postcode: place.postcode || prev.postcode,
              }))
              if (!place.postcode) document.getElementById(`${id}-cep`)?.focus()
            }}
          />
          <CoField label={text.postcode} htmlFor={`${id}-cep`} error={errors.postcode} className="gx-co-field--cep">
            <Input
              unstyled
              id={`${id}-cep`}
              required
              inputMode="numeric"
              autoComplete="postal-code"
              placeholder="00000-000"
              className={field}
              aria-invalid={!!errors.postcode}
              value={values.postcode}
              onChange={(e) => set('postcode', e.target.value)}
            />
          </CoField>
          <CoField label={text.address1} htmlFor={`${id}-a1`} error={errors.address_1}>
            <Input
              unstyled
              id={`${id}-a1`}
              required
              autoComplete="address-line1"
              className={field}
              aria-invalid={!!errors.address_1}
              value={values.address_1}
              onChange={(e) => set('address_1', e.target.value)}
            />
          </CoField>
          <CoField label={text.address2} htmlFor={`${id}-a2`} hint={text.address2Hint}>
            <Input unstyled id={`${id}-a2`} autoComplete="address-line2" className={field} value={values.address_2} onChange={(e) => set('address_2', e.target.value)} />
          </CoField>
          <div className="gx-co-grid-city">
            <CoField label={text.city} htmlFor={`${id}-city`} error={errors.city}>
              <Input
                unstyled
                id={`${id}-city`}
                required
                autoComplete="address-level2"
                className={field}
                aria-invalid={!!errors.city}
                value={values.city}
                onChange={(e) => set('city', e.target.value)}
              />
            </CoField>
            <CoField label={text.state} htmlFor={`${id}-uf`} error={errors.state}>
              <Input
                unstyled
                id={`${id}-uf`}
                required
                maxLength={2}
                autoComplete="address-level1"
                className={field}
                aria-invalid={!!errors.state}
                value={values.state}
                onChange={(e) => set('state', e.target.value.toUpperCase())}
              />
            </CoField>
          </div>
          <PixButton button={buttons.addressButton} type="submit" disabled={busy} />
        </form>
      )}

      <ShippingChoice
        text={text}
        shippingMountRef={shippingMountRef}
        busy={busy}
        shown={showSummary}
        onContinue={onContinue}
        preview={preview}
      />
    </div>
  )
}

export { AddressStep }
