import * as React from 'react'

import { Input } from '@/ui/input'
import { PhoneInput } from '@/ui/phone-input'
import { CoField, PixButton, useFieldClass, useUi } from './pix'
import type { CheckoutText, ProfileValues } from './types'
import { BAD_PHONE, type ProfileErrors } from './validation'

interface ProfileStepProps {
  initial: Partial<ProfileValues>
  busy: boolean
  errors: ProfileErrors
  text: CheckoutText
  onSave: (values: ProfileValues) => void
}

function ProfileStep({ initial, busy, errors, text, onSave }: ProfileStepProps) {
  const { buttons } = useUi()
  const field = useFieldClass()
  const id = React.useId()
  const [values, setValues] = React.useState<ProfileValues>({
    first_name: initial.first_name ?? '',
    last_name: initial.last_name ?? '',
    phone: initial.phone ?? '',
    birthdate: initial.birthdate ?? '',
    cpf: initial.cpf ?? '',
  })

  // The flag field's verdict on the number (null: it can't tell yet), and the
  // message it earns on submit. Kept here, not in validation.ts, because only
  // the field knows the selected country's rules.
  const [phoneValid, setPhoneValid] = React.useState<boolean | null>(null)
  const [phoneError, setPhoneError] = React.useState<string | undefined>()

  function set<K extends keyof ProfileValues>(key: K, value: ProfileValues[K]) {
    setValues((prev) => ({ ...prev, [key]: value }))
  }

  return (
    // `noValidate`: the fields keep `required` for assistive tech, but the
    // browser's own bubble would pre-empt our messages — which are the ones
    // that also carry WooCommerce's verdict, in the shopper's language.
    <form
      noValidate
      onSubmit={(e) => {
        e.preventDefault()
        if ('' !== values.phone && false === phoneValid) {
          setPhoneError(BAD_PHONE)
          return
        }
        setPhoneError(undefined)
        onSave(values)
      }}
      className="gx-co-form"
    >
      <div className="gx-co-grid-2">
        <CoField label={text.firstName} htmlFor={`${id}-fn`} error={errors.first_name}>
          <Input
            unstyled
            id={`${id}-fn`}
            required
            autoComplete="given-name"
            className={field}
            aria-invalid={!!errors.first_name}
            value={values.first_name}
            onChange={(e) => set('first_name', e.target.value)}
          />
        </CoField>
        <CoField label={text.lastName} htmlFor={`${id}-ln`} error={errors.last_name}>
          <Input
            unstyled
            id={`${id}-ln`}
            required
            autoComplete="family-name"
            className={field}
            aria-invalid={!!errors.last_name}
            value={values.last_name}
            onChange={(e) => set('last_name', e.target.value)}
          />
        </CoField>
        <CoField label={text.birthdate} htmlFor={`${id}-bd`} error={errors.birthdate}>
          <Input
            unstyled
            id={`${id}-bd`}
            type="date"
            required
            className={field}
            aria-invalid={!!errors.birthdate}
            value={values.birthdate}
            onChange={(e) => set('birthdate', e.target.value)}
          />
        </CoField>
        <CoField label={text.cpf} htmlFor={`${id}-cpf`} error={errors.cpf}>
          <Input
            unstyled
            id={`${id}-cpf`}
            required
            inputMode="numeric"
            className={field}
            aria-invalid={!!errors.cpf}
            value={values.cpf}
            onChange={(e) => set('cpf', e.target.value)}
            placeholder="000.000.000-00"
          />
        </CoField>
      </div>
      <CoField label={text.phone} htmlFor={`${id}-ph`} error={phoneError ?? errors.phone}>
        <PhoneInput
          unstyled
          id={`${id}-ph`}
          required
          className={field}
          aria-invalid={!!(phoneError ?? errors.phone)}
          value={values.phone}
          onChange={(phone, valid) => {
            set('phone', phone)
            setPhoneValid(valid)
          }}
        />
      </CoField>
      <PixButton button={buttons.profileButton} type="submit" disabled={busy} />
    </form>
  )
}

export { ProfileStep }
