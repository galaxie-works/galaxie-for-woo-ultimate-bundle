export interface DetailsValues {
  first_name: string
  last_name: string
  social_name: string
  birthdate: string
  cpf: string
  gender: string
}

export interface InterestOption {
  tagId: number
  label: string
  icon: string
  iconUrl: string
}

export interface MyAccountProps {
  values: DetailsValues & { email: string }
  genderOptions: Record<string, string>
  interests: {
    enabled: boolean
    options: InterestOption[]
    selected: number[]
  }
  communication: {
    optedIn: boolean
  }
  accountDeletionEnabled: boolean
  i18n: {
    genericError: string
    deleteModalTitle: string
    deleteModalBody: string
    deleteModalCancel: string
    deleteModalConfirm: string
  }
}
