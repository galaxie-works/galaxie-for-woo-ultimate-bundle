import * as React from 'react'

import { cn } from '@/lib/cn'
import { CoField, useFieldClass, useUi } from '@/lib/pix'
import { Input } from '@/ui/input'
import type { CheckoutText, CheckoutUi } from './types'
import { NO_NUMBER } from './validation'

interface StreetValues {
  address_1: string
  number: string
  neighborhood: string
}

interface StreetFieldsProps {
  id: string
  values: StreetValues
  errors?: Partial<Record<keyof StreetValues, string>>
  text: CheckoutText
  onChange: (key: keyof StreetValues, value: string) => void
  /** Drawn between Rua/Número and Bairro: the Complemento, in the order a Brazilian address is written. */
  children?: React.ReactNode
}

/**
 * Rua, Número (with "Sem número") and Bairro, shared by both delivery forms.
 *
 * Three fields, not one "Endereço" line, because that is how the order is
 * read downstream: Melhor Envio prints `address_1` as the street and the
 * `_shipping_number` / `_shipping_neighborhood` order meta beside it, and the
 * Brazilian checkout plugin (Link Nacional) can require `billing_number` and
 * `billing_neighborhood` on a form the shopper never sees — so an address typed
 * as one line was either refused invisibly or printed on the label without a
 * number and with "N/I" for the bairro.
 *
 * "Sem número" writes "S/N" — the plugin's own convention — and locks the box.
 */
export function StreetFields({ id, values, errors = {}, text, onChange, children }: StreetFieldsProps) {
  const { cls } = useUi<CheckoutUi>()
  const field = useFieldClass()
  const none = NO_NUMBER === values.number

  return (
    <>
      <div className="gx-co-grid-city">
        <CoField label={text.address1} htmlFor={`${id}-a1`} error={errors.address_1}>
          <Input
            unstyled
            id={`${id}-a1`}
            required
            autoComplete="address-line1"
            className={field}
            aria-invalid={!!errors.address_1}
            value={values.address_1}
            onChange={(e) => onChange('address_1', e.target.value)}
          />
        </CoField>
        <CoField label={text.number} htmlFor={`${id}-num`} error={errors.number}>
          <Input
            unstyled
            id={`${id}-num`}
            required
            inputMode={none ? undefined : 'numeric'}
            readOnly={none}
            className={field}
            aria-invalid={!!errors.number}
            value={values.number}
            onChange={(e) => onChange('number', e.target.value)}
          />
          <label className="gx-co-check">
            <input
              type="checkbox"
              checked={none}
              onChange={(e) => {
                onChange('number', e.target.checked ? NO_NUMBER : '')
                if (!e.target.checked) document.getElementById(`${id}-num`)?.focus()
              }}
            />
            <span className={cn('gx-co-small', cls.small)}>{text.numberNone}</span>
          </label>
        </CoField>
      </div>
      {children}
      <CoField label={text.neighborhood} htmlFor={`${id}-bairro`} error={errors.neighborhood}>
        <Input
          unstyled
          id={`${id}-bairro`}
          required
          className={field}
          aria-invalid={!!errors.neighborhood}
          value={values.neighborhood}
          onChange={(e) => onChange('neighborhood', e.target.value)}
        />
      </CoField>
    </>
  )
}
