import * as React from 'react'
import type { Iti } from 'intl-tel-input'

import { attachPhoneInput, readPhone } from '@/lib/phone'
import { Input } from '@/ui/input'

type PhoneInputProps = Omit<React.ComponentProps<'input'>, 'type' | 'value' | 'defaultValue' | 'onChange'> & {
  /** Read once, on mount: after that the library owns the text (it formats as the customer types). */
  value: string
  /** The number in E.164 when readable, and whether the library considers it valid (null: can't tell). */
  onChange: (value: string, valid: boolean | null) => void
}

/** The phone field with a country flag (Brazil first), see lib/phone.ts. */
function PhoneInput({ value, onChange, ...props }: PhoneInputProps) {
  const ref = React.useRef<HTMLInputElement>(null)
  const onChangeRef = React.useRef(onChange)

  React.useEffect(() => {
    onChangeRef.current = onChange
  })

  React.useEffect(() => {
    const input = ref.current
    if (!input) return

    let iti: Iti | null = null
    let gone = false

    const report = () => {
      void readPhone(input, iti).then((reading) => {
        if (!gone) onChangeRef.current(reading.value, reading.valid)
      })
    }

    input.addEventListener('input', report)
    input.addEventListener('countrychange', report)

    attachPhoneInput(input).then(
      (instance) => {
        if (gone) {
          instance.destroy()
          return
        }
        iti = instance
        // A number stored the old way, (11) 98040-9005, reaches the state as +55… once the utils are in.
        report()
      },
      () => undefined
    )

    return () => {
      gone = true
      input.removeEventListener('input', report)
      input.removeEventListener('countrychange', report)
      iti?.destroy()
    }
  }, [])

  return <Input ref={ref} type="tel" inputMode="tel" autoComplete="tel" defaultValue={value} {...props} />
}

export { PhoneInput }
