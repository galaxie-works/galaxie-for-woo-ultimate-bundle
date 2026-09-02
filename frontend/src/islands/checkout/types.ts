export type StepId = 'entry' | 'profile' | 'address' | 'payment'

export interface ProfileValues {
  first_name: string
  last_name: string
  phone: string
  birthdate: string
  cpf: string
}

export interface AddressValues {
  address_1: string
  address_2: string
  city: string
  state: string
  postcode: string
  country: string
}

export interface CheckoutProps {
  loggedIn: boolean
  userEmail: string
  profile: {
    complete: boolean
    missing: string[]
    values: Partial<ProfileValues>
  }
  address: Partial<AddressValues> & { has_address: boolean }
  i18n: {
    genericError: string
    noShipping: string
  }
}
