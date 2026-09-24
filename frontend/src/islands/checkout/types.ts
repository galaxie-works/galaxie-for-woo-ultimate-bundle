import type { PixButtonData, PixUi } from '@/lib/pix'
import type { LoginText } from '@/islands/login/OtpLogin'

export type StepId = 'entry' | 'profile' | 'address' | 'payment'

export const STEP_ORDER: StepId[] = ['entry', 'profile', 'address', 'payment']

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

export interface SummaryItem {
  key: string
  name: string
  /** Variation and other cart-item data, flattened to one line of text. */
  meta: string
  quantity: number
  image: string
  total: string
}

export interface SummaryRow {
  id: string
  label: string
  value: string
  /** Small print under the label — the carrier and its estimate, for shipping. */
  note: string
}

/** Built by PHP `Checkout\OrderSummary`; every amount is WooCommerce's own display string. */
export interface OrderSummaryData {
  count: number
  items: SummaryItem[]
  rows: SummaryRow[]
  total: string
}

/** Every string the island prints, from the widget's Content tab or PHP translations. */
export interface CheckoutText extends LoginText {
  stepEntry: string
  stepProfile: string
  stepAddress: string
  stepPayment: string
  edit: string
  logout: string
  profileButton: string
  addressButton: string
  shippingHeading: string
  paymentButton: string
  entryIntro: string
  tabLogin: string
  tabRegister: string
  sendCode: string
  registerButton: string
  /** Contains `%s`, replaced by the e-mail address. */
  codeHint: string
  confirmCode: string
  resendCode: string
  changeEmail: string
  marketing: string
  terms: string
  summaryTitle: string
  summaryTotal: string
  summaryShow: string
  summaryHide: string
  quantity: string
  email: string
  firstName: string
  lastName: string
  birthdate: string
  cpf: string
  phone: string
  address1: string
  address2: string
  address2Hint: string
  city: string
  state: string
  postcode: string
  previewShipping: string
  previewPayment: string
}

export interface CheckoutLayout {
  summaryPosition: 'left' | 'right'
  summarySticky: boolean
  summaryOpenMobile: boolean
}

/**
 * pixfort's parts, as the widget's Style tab configured them (PHP
 * `CheckoutWidget::ui()`): class strings for texts, boxes and pills, and
 * finished button / alert markup. Every key of `cls` is a string, possibly
 * empty when a control is left at Default.
 */
export interface CheckoutUi extends PixUi {
  cls: {
    stepTitle: string
    body: string
    small: string
    label: string
    hint: string
    error: string
    tabText: string
    rateName: string
    methodName: string
    sumTitle: string
    itemName: string
    itemMeta: string
    itemPrice: string
    rowLabel: string
    rowValue: string
    totalLabel: string
    totalValue: string
    stepBox: string
    stepDot: string
    tab: string
    field: string
    option: string
    rate: string
    method: string
    methodBox: string
    summaryBox: string
    thumb: string
    qty: string
    token: string
    tokenBadge: string
    tokenNumber: string
    tokenExpiry: string
    tokenBadgeText: string
  }
  buttons: Record<
    | 'sendCode'
    | 'registerButton'
    | 'confirmCode'
    | 'profileButton'
    | 'addressButton'
    | 'paymentButton'
    | 'placeOrder'
    | 'edit'
    | 'resendCode'
    | 'changeEmail'
    | 'logout',
    PixButtonData
  >
}

export interface CheckoutProps {
  loggedIn: boolean
  userEmail: string
  logoutUrl: string
  profile: {
    complete: boolean
    missing: string[]
    values: Partial<ProfileValues>
  }
  address: Partial<AddressValues> & { has_address: boolean }
  summary: OrderSummaryData
  layout: CheckoutLayout
  text: CheckoutText
  ui: CheckoutUi
  i18n: {
    genericError: string
    noShipping: string
  }
  /**
   * Set only in the Elementor editor: sample data, one fixed step, no
   * requests. `payment` is a sample of WooCommerce's payment block (saved
   * cards, new card, Pix, boleto) in the live block's own markup.
   */
  preview: { step: StepId; payment: string } | null
}
