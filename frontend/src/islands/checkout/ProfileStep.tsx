import * as React from 'react'

import { Button } from '@/ui/button'
import { Field } from '@/ui/field'
import { Input } from '@/ui/input'
import type { ProfileValues } from './types'

interface ProfileStepProps {
  initial: Partial<ProfileValues>
  busy: boolean
  onSave: (values: ProfileValues) => void
}

function ProfileStep({ initial, busy, onSave }: ProfileStepProps) {
  const [values, setValues] = React.useState<ProfileValues>({
    first_name: initial.first_name ?? '',
    last_name: initial.last_name ?? '',
    phone: initial.phone ?? '',
    birthdate: initial.birthdate ?? '',
    cpf: initial.cpf ?? '',
  })

  function set<K extends keyof ProfileValues>(key: K, value: ProfileValues[K]) {
    setValues((prev) => ({ ...prev, [key]: value }))
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        onSave(values)
      }}
      className="mx-auto flex max-w-sm flex-col gap-4"
    >
      <div className="grid grid-cols-2 gap-3">
        <Field label="First name">
          <Input required value={values.first_name} onChange={(e) => set('first_name', e.target.value)} />
        </Field>
        <Field label="Last name">
          <Input required value={values.last_name} onChange={(e) => set('last_name', e.target.value)} />
        </Field>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Date of birth">
          <Input type="date" value={values.birthdate} onChange={(e) => set('birthdate', e.target.value)} />
        </Field>
        <Field label="CPF">
          <Input value={values.cpf} onChange={(e) => set('cpf', e.target.value)} placeholder="000.000.000-00" />
        </Field>
      </div>
      <Field label="Phone">
        <Input type="tel" value={values.phone} onChange={(e) => set('phone', e.target.value)} />
      </Field>
      <Button type="submit" disabled={busy}>
        Continue
      </Button>
    </form>
  )
}

export { ProfileStep }
