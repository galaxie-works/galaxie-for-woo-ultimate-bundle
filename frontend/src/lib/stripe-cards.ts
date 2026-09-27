/**
 * Saving a card with Stripe, shared by My Account's Payment Methods widget and
 * the checkout's payment step.
 *
 * The number, expiry and code are Stripe's own fields — iframes Stripe serves —
 * mounted into boxes of ours, so the card data goes from the customer's
 * keyboard straight to Stripe and never through this site. The save runs the
 * WooCommerce Stripe plugin's own flow (see PHP Support\StripeCards): Stripe.js
 * creates the PaymentMethod, the plugin confirms a SetupIntent for it,
 * Stripe.js handles any bank authentication, and our endpoint turns the
 * confirmed intent into a saved WooCommerce payment token.
 *
 * What each caller does around it — redrawing an account list, or refreshing
 * the checkout and selecting the new card — stays with the caller.
 */

/** PHP `StripeCards::client_config()`. */
export interface StripeConfig {
  key: string
  ajaxUrl: string
  intentNonce: string
  saveNonce: string
  locale: string
}

interface StripeElementChange {
  complete?: boolean
  error?: { message?: string }
}

export interface StripeElement {
  mount: (el: HTMLElement) => void
  destroy: () => void
  focus: () => void
  on: (event: 'change' | 'focus' | 'blur' | 'ready', handler: (event: StripeElementChange) => void) => void
}

interface StripeElements {
  create: (type: 'cardNumber' | 'cardExpiry' | 'cardCvc', options: Record<string, unknown>) => StripeElement
}

interface StripeResult {
  error?: { message?: string }
  paymentMethod?: { id: string }
  setupIntent?: { id: string; status: string }
}

interface StripeInstance {
  elements: (options?: Record<string, unknown>) => StripeElements
  createPaymentMethod: (options: Record<string, unknown>) => Promise<StripeResult>
  handleNextAction: (options: { clientSecret: string }) => Promise<StripeResult>
  confirmCardSetup: (clientSecret: string) => Promise<StripeResult>
  retrieveSetupIntent: (clientSecret: string) => Promise<StripeResult>
}

interface SetupIntentAnswer {
  status: string
  id: string
  client_secret: string
}

type StripeFactory = (key: string, options?: Record<string, unknown>) => StripeInstance

export interface AjaxResponse<T> {
  success: boolean
  data?: T & { message?: string; error?: { message?: string } }
}

/** The three mounted fields of one open form. */
export interface CardFields {
  stripe: StripeInstance
  number: StripeElement
  expiry: StripeElement
  cvc: StripeElement
}

export interface CardBoxes {
  number: HTMLElement
  expiry: HTMLElement
  cvc: HTMLElement
}

const STRIPE_JS = 'https://js.stripe.com/v3/'
export const GENERIC_ERROR = 'Não foi possível salvar o cartão. Tente de novo.'
const PENDING_ERROR = 'O banco ainda está confirmando este cartão, por isso ele não foi salvo. Aguarde alguns minutos e tente de novo.'

/** How long a SetupIntent still "processing" is watched before giving up: 8 × 2 s. */
const PROCESSING_CHECKS = 8
const PROCESSING_INTERVAL = 2000

let stripeLoader: Promise<StripeFactory> | null = null

/**
 * Stripe.js once per page, whether the page or the plugin already loaded it.
 * On the checkout the Stripe plugin's own script is on its way too, so a
 * `<script>` it already inserted is waited for rather than doubled.
 */
function loadStripe(): Promise<StripeFactory> {
  const existing = (window as unknown as { Stripe?: StripeFactory }).Stripe
  if (existing) return Promise.resolve(existing)

  stripeLoader ??= new Promise<StripeFactory>((resolve, reject) => {
    const done = () => {
      const factory = (window as unknown as { Stripe?: StripeFactory }).Stripe
      if (factory) resolve(factory)
      else reject(new Error('Stripe.js did not load'))
    }
    const fail = () => {
      stripeLoader = null
      reject(new Error('Stripe.js did not load'))
    }

    const pending = document.querySelector<HTMLScriptElement>(`script[src^="${STRIPE_JS}"]`)
    if (pending) {
      pending.addEventListener('load', done, { once: true })
      pending.addEventListener('error', fail, { once: true })
      return
    }

    const script = document.createElement('script')
    script.src = STRIPE_JS
    script.async = true
    script.onload = done
    script.onerror = fail
    document.head.appendChild(script)
  })

  return stripeLoader
}

function faded(color: string, alpha: number): string {
  const rgb = color.match(/\d+(\.\d+)?/g)
  return rgb && rgb.length >= 3 ? `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${alpha})` : color
}

/**
 * Stripe's fields live in iframes and inherit nothing from the page, so the
 * field box's computed text styles — whatever the widget's controls set — are
 * handed to them. The placeholder colour comes from a hidden probe the
 * Placeholder color control paints, or the text colour faded.
 */
function styleFrom(box: HTMLElement, probe: HTMLElement | null): Record<string, unknown> {
  const css = getComputedStyle(box)
  const base: Record<string, unknown> = {
    color: css.color,
    fontFamily: css.fontFamily,
    fontSize: css.fontSize,
    fontWeight: css.fontWeight,
    iconColor: css.color,
  }

  // Stripe rejects properties it does not know and values it cannot parse, so
  // only what the box really sets is passed on.
  if (css.letterSpacing && css.letterSpacing !== 'normal') base.letterSpacing = css.letterSpacing

  const probed = probe ? getComputedStyle(probe).color : ''
  base['::placeholder'] = { color: probed && probed !== css.color ? probed : faded(css.color, 0.5) }

  return {
    base,
    invalid: { color: css.color, iconColor: css.color },
  }
}

/**
 * Mounts Stripe's number, expiry and code fields into the three boxes, each
 * styled from its box. The boxes get `is-focused` / `is-invalid`, and
 * `onError` hears each field's validation message ('' once it clears).
 */
export async function mountCardFields(
  config: StripeConfig,
  boxes: CardBoxes,
  probe: HTMLElement | null,
  onError: (message: string) => void
): Promise<CardFields> {
  const factory = await loadStripe()
  const stripe = factory(config.key, { locale: config.locale || 'auto' })
  const elements = stripe.elements()

  // Stripe's own brand icon inside the number, since there is no card picture
  // to show it; its Link button off, which offered a different checkout.
  const number = elements.create('cardNumber', { style: styleFrom(boxes.number, probe), showIcon: true, disableLink: true })
  const expiry = elements.create('cardExpiry', { style: styleFrom(boxes.expiry, probe) })
  const cvc = elements.create('cardCvc', { style: styleFrom(boxes.cvc, probe) })

  const pairs: Array<[StripeElement, HTMLElement]> = [
    [number, boxes.number],
    [expiry, boxes.expiry],
    [cvc, boxes.cvc],
  ]

  for (const [field, box] of pairs) {
    field.mount(box)
    field.on('focus', () => box.classList.add('is-focused'))
    field.on('blur', () => box.classList.remove('is-focused'))
    field.on('change', (event) => {
      box.classList.toggle('is-invalid', Boolean(event.error))
      onError(event.error?.message ?? '')
    })
  }

  number.on('ready', () => number.focus())

  return { stripe, number, expiry, cvc }
}

export function destroyCardFields(fields: CardFields, boxes?: CardBoxes): void {
  fields.number.destroy()
  fields.expiry.destroy()
  fields.cvc.destroy()
  if (boxes) {
    for (const box of [boxes.number, boxes.expiry, boxes.cvc]) box.classList.remove('is-focused', 'is-invalid')
  }
}

export async function postForm<T>(url: string, body: Record<string, string>): Promise<AjaxResponse<T>> {
  const response = await fetch(url, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) })
  return (await response.json()) as AjaxResponse<T>
}

const wait = (ms: number) => new Promise<void>((resolve) => window.setTimeout(resolve, ms))

/**
 * Brings the SetupIntent the Stripe plugin answered with to `succeeded`, and
 * returns its id. The plugin counts `requires_action`, `requires_confirmation`
 * and `processing` as success, yet none of them is a card that can be saved:
 * the bank's authentication is run, a confirmation still owed is made, and a
 * `processing` intent is watched for a while. One still processing after that
 * is reported as pending — the card is not saved — rather than as an error.
 */
async function settleIntent(stripe: StripeInstance, answer: SetupIntentAnswer): Promise<string> {
  let { id, status } = answer
  const secret = answer.client_secret

  for (let step = 0; step < 4 && status !== 'succeeded'; step++) {
    let result: StripeResult

    if (status === 'requires_action') {
      result = await stripe.handleNextAction({ clientSecret: secret })
    } else if (status === 'requires_confirmation') {
      result = await stripe.confirmCardSetup(secret)
    } else if (status === 'processing') {
      result = {}
      for (let check = 0; check < PROCESSING_CHECKS; check++) {
        await wait(PROCESSING_INTERVAL)
        result = await stripe.retrieveSetupIntent(secret)
        if (result.error || result.setupIntent?.status !== 'processing') break
      }
      if (!result.error && result.setupIntent?.status === 'processing') throw new Error(PENDING_ERROR)
    } else {
      break
    }

    if (result.error || !result.setupIntent) throw new Error(result.error?.message ?? GENERIC_ERROR)

    id = result.setupIntent.id
    status = result.setupIntent.status
  }

  if (status === 'processing') throw new Error(PENDING_ERROR)
  if (status !== 'succeeded') throw new Error(GENERIC_ERROR)

  return id
}

/**
 * The whole save, from the typed fields to a WooCommerce payment token.
 * Resolves with the token id ('' if the server did not say); rejects with an
 * Error whose message is fit to show the customer. Once it resolves the card
 * IS saved: a caller must not present anything after it as a failure, or the
 * customer saves the same card twice.
 */
export async function saveCardToken(config: StripeConfig, fields: CardFields): Promise<string> {
  const created = await fields.stripe.createPaymentMethod({ type: 'card', card: fields.number })
  if (created.error || !created.paymentMethod) throw new Error(created.error?.message ?? GENERIC_ERROR)

  const intent = await postForm<SetupIntentAnswer>(config.ajaxUrl, {
    action: 'wc_stripe_create_and_confirm_setup_intent',
    _ajax_nonce: config.intentNonce,
    'wc-stripe-payment-method': created.paymentMethod.id,
    'wc-stripe-payment-type': 'card',
  })

  if (!intent.success || !intent.data) throw new Error(intent.data?.error?.message ?? GENERIC_ERROR)

  const setupIntentId = await settleIntent(fields.stripe, intent.data)

  const saved = await postForm<{ token: number }>(config.ajaxUrl, {
    action: 'galaxie_stripe_save_card',
    nonce: config.saveNonce,
    setup_intent: setupIntentId,
  })

  if (!saved.success) throw new Error(saved.data?.message ?? GENERIC_ERROR)

  return saved.data?.token ? String(saved.data.token) : ''
}
