import * as React from 'react'

import { Button } from '@/ui/button'
import { Field } from '@/ui/field'
import { Input } from '@/ui/input'
import { PhoneInput } from '@/ui/phone-input'
import type { ProfileValues } from './types'
import { BAD_PHONE, type ProfileErrors } from './validation'

interface ProfileStepProps {
  initial: Partial<ProfileValues>
  busy: boolean
  errors: ProfileErrors
  onSave: (values: ProfileValues) => void
}

function ProfileStep({ initial, busy, errors, onSave }: ProfileStepProps) {
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
      className="mx-auto flex max-w-sm flex-col gap-4"
    >
      <div className="grid grid-cols-2 gap-3">
        <Field label="First name" error={errors.first_name}>
          <Input
            required
            aria-invalid={!!errors.first_name}
            value={values.first_name}
            onChange={(e) => set('first_name', e.target.value)}
          />
        </Field>
        <Field label="Last name" error={errors.last_name}>
          <Input
            required
            aria-invalid={!!errors.last_name}
            value={values.last_name}
            onChange={(e) => set('last_name', e.target.value)}
          />
        </Field>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Date of birth" error={errors.birthdate}>
          <Input
            type="date"
            required
            aria-invalid={!!errors.birthdate}
            value={values.birthdate}
            onChange={(e) => set('birthdate', e.target.value)}
          />
        </Field>
        <Field label="CPF" error={errors.cpf}>
          <Input
            required
            aria-invalid={!!errors.cpf}
            value={values.cpf}
            onChange={(e) => set('cpf', e.target.value)}
            placeholder="000.000.000-00"
          />
        </Field>
      </div>
      <Field label="Phone" error={phoneError ?? errors.phone}>
        <PhoneInput
          required
          aria-invalid={!!(phoneError ?? errors.phone)}
          value={values.phone}
          onChange={(phone, valid) => {
            set('phone', phone)
            setPhoneValid(valid)
          }}
        />
      </Field>
      <Button type="submit" disabled={busy}>
        Continue
      </Button>
    </form>
  )
}

export { ProfileStep }
