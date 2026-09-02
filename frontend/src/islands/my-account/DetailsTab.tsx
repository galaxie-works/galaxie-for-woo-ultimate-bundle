import * as React from 'react'

import { Button } from '@/ui/button'
import { Field } from '@/ui/field'
import { Input } from '@/ui/input'
import type { DetailsValues } from './types'

interface DetailsTabProps {
  initial: DetailsValues
  email: string
  genderOptions: Record<string, string>
  busy: boolean
  onSave: (values: DetailsValues) => void
}

function DetailsTab({ initial, email, genderOptions, busy, onSave }: DetailsTabProps) {
  const [values, setValues] = React.useState<DetailsValues>(initial)

  function set<K extends keyof DetailsValues>(key: K, value: DetailsValues[K]) {
    setValues((prev) => ({ ...prev, [key]: value }))
  }

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        onSave(values)
      }}
      className="flex max-w-lg flex-col gap-4"
    >
      <div className="grid grid-cols-2 gap-3">
        <Field label="First name">
          <Input required value={values.first_name} onChange={(e) => set('first_name', e.target.value)} />
        </Field>
        <Field label="Last name">
          <Input required value={values.last_name} onChange={(e) => set('last_name', e.target.value)} />
        </Field>
      </div>

      <Field label="Social name" hint="If different from your legal name.">
        <Input value={values.social_name} onChange={(e) => set('social_name', e.target.value)} />
      </Field>

      <Field label="Email address">
        <Input value={email} disabled />
      </Field>

      <div className="grid grid-cols-2 gap-3">
        <Field label="Date of birth">
          <Input type="date" value={values.birthdate} onChange={(e) => set('birthdate', e.target.value)} />
        </Field>
        <Field label="CPF">
          <Input value={values.cpf} onChange={(e) => set('cpf', e.target.value)} placeholder="000.000.000-00" />
        </Field>
      </div>

      <Field label="Gender">
        <select
          value={values.gender}
          onChange={(e) => set('gender', e.target.value)}
          className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
          {Object.entries(genderOptions).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </Field>

      <div>
        <Button type="submit" disabled={busy}>
          Save changes
        </Button>
      </div>
    </form>
  )
}

export { DetailsTab }
