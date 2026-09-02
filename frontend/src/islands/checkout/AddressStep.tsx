import * as React from 'react'

import { Button } from '@/ui/button'
import { Field } from '@/ui/field'
import { Input } from '@/ui/input'
import type { AddressValues } from './types'

interface AddressStepProps {
  initial: Partial<AddressValues>
  saved: boolean
  busy: boolean
  shippingMountRef: React.RefObject<HTMLDivElement | null>
  onSave: (values: AddressValues) => void
  onContinue: () => void
}

/**
 * Two-stage by necessity, not decoration: the whole point of relocating the
 * shipping-method list into this step is to let the customer pick a method
 * before paying — so "Save" must actually save (and trigger WooCommerce's
 * rate calculation) before "Continue" is a separate, deliberate second click,
 * once real rates are on screen. Collapsing these into one click would skip
 * showing shipping options entirely.
 */
function AddressStep({ initial, saved, busy, shippingMountRef, onSave, onContinue }: AddressStepProps) {
  const [editing, setEditing] = React.useState(!saved)
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
    onSave(values)
    setEditing(false)
  }

  const summary = [values.address_1, values.address_2, values.city, values.state, values.postcode]
    .filter(Boolean)
    .join(', ')

  return (
    <div className="mx-auto flex max-w-sm flex-col gap-4">
      {!editing && saved ? (
        <div className="flex flex-col gap-4">
          <div className="rounded-md border border-border p-3">
            <p className="text-sm">{summary}</p>
            <button
              type="button"
              onClick={() => setEditing(true)}
              className="mt-2 text-sm text-muted-foreground underline underline-offset-2"
            >
              Edit address
            </button>
          </div>

          <div>
            <p className="mb-2 text-sm font-medium">Shipping method</p>
            <div ref={shippingMountRef} className="galaxie-shipping-mount" />
          </div>

          <Button type="button" onClick={onContinue} disabled={busy}>
            Continue to payment
          </Button>
        </div>
      ) : (
        <form onSubmit={handleSave} className="flex flex-col gap-4">
          <Field label="Address">
            <Input required value={values.address_1} onChange={(e) => set('address_1', e.target.value)} />
          </Field>
          <Field label="Complement" hint="Apartment, suite, etc. (optional)">
            <Input value={values.address_2} onChange={(e) => set('address_2', e.target.value)} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="City">
              <Input required value={values.city} onChange={(e) => set('city', e.target.value)} />
            </Field>
            <Field label="State">
              <Input required maxLength={2} value={values.state} onChange={(e) => set('state', e.target.value.toUpperCase())} />
            </Field>
          </div>
          <Field label="Postcode">
            <Input required value={values.postcode} onChange={(e) => set('postcode', e.target.value)} />
          </Field>
          <Button type="submit" disabled={busy}>
            Save address
          </Button>
        </form>
      )}
    </div>
  )
}

export { AddressStep }
