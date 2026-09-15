/**
 * The phone field with a country flag: intl-tel-input, set up the same way
 * everywhere we ask for a phone (My Account's details, the checkout steps).
 *
 * FluentCRM keeps phones with their country code, `+5511980409005`, and so do
 * we: whatever the customer types, `readPhone` hands back E.164, and the server
 * (`Support\Phone`) normalises again before it writes `billing_phone`.
 *
 * The styles are part of galaxie.css — CSS in a lazy chunk would need a second
 * stylesheet — but the library itself, its Portuguese strings and the
 * validation utils (~270 KB) load only when a phone field is on the page, as
 * chunks next to galaxie.js. No CDN.
 */

import 'intl-tel-input/styles'
import '@/styles/phone.css'

import type { Iti, SomeOptions } from 'intl-tel-input'

type IntlTelInput = typeof import('intl-tel-input').default
type Translations = NonNullable<SomeOptions['uiTranslations']>

let library: Promise<{ intlTelInput: IntlTelInput; pt: Translations }> | null = null

function load() {
  library ??= Promise.all([import('intl-tel-input'), import('intl-tel-input/locale/pt')]).then(([core, pt]) => ({
    intlTelInput: core.default,
    pt: (pt as unknown as { default: Translations }).default,
  }))

  // A failed chunk (deploy mid-visit, flaky network) may be retried on the next call.
  library.catch(() => {
    library = null
  })

  return library
}

/** Turns the input into a phone field with a flag, once; later calls return the same instance. */
export async function attachPhoneInput(input: HTMLInputElement, options: SomeOptions = {}): Promise<Iti> {
  const { intlTelInput, pt } = await load()
  const existing = intlTelInput.getInstance(input)
  if (existing) return existing

  return intlTelInput(input, {
    initialCountry: 'br',
    countryOrder: ['br'],
    separateDialCode: true,
    // Typed and shown the way a Brazilian writes it, (11) 98040-9005, next to
    // the +55 the flag already shows. A stored +55… value is shown that way too.
    numberDisplayFormat: 'NATIONAL',
    formatAsYouType: true,
    countryNameLocale: 'pt-BR',
    uiTranslations: pt,
    loadUtils: () => import('intl-tel-input/utils'),
    ...options,
  })
}

export interface PhoneReading {
  /** E.164 when the library could read it; what was typed otherwise; '' when empty. */
  value: string
  /** False only when the library says the number is invalid. Null: it could not tell (utils failed to load). */
  valid: boolean | null
}

export async function readPhone(input: HTMLInputElement, iti: Iti | null): Promise<PhoneReading> {
  const typed = input.value.trim()
  if ('' === typed) return { value: '', valid: true }
  if (!iti) return { value: typed, valid: null }

  // Resolves once the utils are in, which validation and getNumber need.
  await iti.promise.catch(() => undefined)

  const valid = iti.isValidNumber()
  if (null === valid) return { value: typed, valid: null }

  return { value: iti.getNumber() || typed, valid }
}
