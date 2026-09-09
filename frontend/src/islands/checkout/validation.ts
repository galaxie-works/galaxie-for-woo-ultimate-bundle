import type { AddressValues, ProfileValues } from './types'

/**
 * Step-local validation for the things WooCommerce cannot answer for us.
 *
 * The division of labour with `validateNativeFields` is deliberate. Whether
 * `billing_state` or `billing_phone` is required at all is decided by the
 * store's own locale and checkout-field configuration, so that verdict has to
 * come from WooCommerce — we only translate it into a message under the right
 * input. What is left over is ours: the fields the native billing form has
 * never heard of (CPF, birthdate), and the CEP shape, which WooCommerce
 * happily accepts as any non-empty string but Correios does not, so a typo
 * there otherwise survives all the way to an empty shipping-rate list.
 *
 * Everything here is a pure function of the step's own values so the step
 * components can render per-field errors without a round trip.
 */

export type ProfileErrors = Partial<Record<keyof ProfileValues, string>>
export type AddressErrors = Partial<Record<keyof AddressValues, string>>

export interface StepValidation<E> {
  errors: E
  /** For failures no single input can own — the caller shows these as the stepper's `role="alert"`. */
  notice: string | null
  ok: boolean
}

const REQUIRED = 'Preencha este campo antes de continuar.'
const REJECTED = 'Confira este campo antes de continuar.'
const BAD_CEP = 'Informe um CEP válido, com 8 dígitos.'
const BAD_EMAIL = 'O e-mail da sua conta parece inválido. Corrija-o na sua conta antes de continuar.'
const BAD_NATIVE = 'Alguns dados do pedido não foram aceitos. Revise as informações e tente novamente.'

/** Eight digits, hyphen after the fifth optional — the only two shapes a Brazilian CEP is written in. */
const CEP_SHAPE = /^\d{5}-?\d{3}$/

/** Deliberately loose: this exists to catch a missing "@" or domain, not to relitigate RFC 5322. */
const EMAIL_SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

/**
 * Native field id -> the step input its rejection should light up. `null`
 * means this step renders no input for it (we always mirror `BR` into
 * `billing_country`, for instance), so a rejection there has nowhere to land
 * and has to surface as a notice instead.
 */
const PROFILE_NATIVE: Record<string, keyof ProfileValues | null> = {
  billing_first_name: 'first_name',
  billing_last_name: 'last_name',
  billing_phone: 'phone',
}

const ADDRESS_NATIVE: Record<string, keyof AddressValues | null> = {
  billing_country: null,
  billing_address_1: 'address_1',
  billing_city: 'city',
  billing_state: 'state',
  billing_postcode: 'postcode',
}

// Derived rather than written twice, so the ids we ask WooCommerce about and
// the ids we know how to report on can never drift apart.
export const PROFILE_NATIVE_FIELDS = Object.keys(PROFILE_NATIVE)
export const ADDRESS_NATIVE_FIELDS = Object.keys(ADDRESS_NATIVE)

function result<E extends object>(errors: E, notice: string | null): StepValidation<E> {
  return { errors, notice, ok: null === notice && 0 === Object.keys(errors).length }
}

/**
 * Folds WooCommerce's verdict into errors we already have. Our own message
 * wins on a field both rejected — ours says what is actually wrong with the
 * value, WooCommerce's only says that something is.
 */
function applyNative<K extends string>(
  failures: string[],
  map: Record<string, K | null>,
  errors: Partial<Record<K, string>>
): boolean {
  let unattributed = false
  for (const id of failures) {
    const key = map[id]
    if (key) {
      if (!errors[key]) {
        errors[key] = REJECTED
      }
      continue
    }
    unattributed = true
  }
  return unattributed
}

/**
 * `accountEmail` is not a field of this step, but it is what gets mirrored
 * into `billing_email` and it is the one contact detail the shopper cannot
 * see here — so a malformed one has to stop the step rather than fail at the
 * place-order button, where the cause would be invisible.
 */
export function validateProfileStep(
  values: ProfileValues,
  accountEmail: string,
  nativeFailures: string[]
): StepValidation<ProfileErrors> {
  const errors: ProfileErrors = {}

  // All five, not just the two the markup marks `required`: `CustomerProfile`
  // counts the profile as incomplete while any of them is empty, so letting a
  // shopper past with a blank CPF only drops them back onto this step later.
  for (const key of ['first_name', 'last_name', 'phone', 'cpf', 'birthdate'] as const) {
    if ('' === values[key].trim()) {
      errors[key] = REQUIRED
    }
  }

  const unattributed = applyNative(nativeFailures, PROFILE_NATIVE, errors)

  if (!EMAIL_SHAPE.test(accountEmail)) {
    return result(errors, BAD_EMAIL)
  }
  return result(errors, unattributed ? BAD_NATIVE : null)
}

export function validateAddressStep(values: AddressValues, nativeFailures: string[]): StepValidation<AddressErrors> {
  const errors: AddressErrors = {}

  // `address_2` stays out: it is the complement, optional by design.
  for (const key of ['address_1', 'city', 'state', 'postcode'] as const) {
    if ('' === values[key].trim()) {
      errors[key] = REQUIRED
    }
  }

  // Guarded by country so this stays a Brazilian rule rather than a global
  // one, should the store ever ship elsewhere.
  if (!errors.postcode && 'BR' === values.country && !CEP_SHAPE.test(values.postcode.trim())) {
    errors.postcode = BAD_CEP
  }

  const unattributed = applyNative(nativeFailures, ADDRESS_NATIVE, errors)
  return result(errors, unattributed ? BAD_NATIVE : null)
}
