/**
 * Adding a card inside the payment methods widget, without leaving the page.
 *
 * The number, expiry and code are Stripe's own fields — iframes Stripe serves —
 * mounted into the widget's card design, so the card data goes from the
 * customer's keyboard straight to Stripe and never through this site. The save
 * runs the WooCommerce Stripe plugin's own flow (see PHP Support\StripeCards):
 * Stripe.js creates the PaymentMethod, the plugin confirms a SetupIntent for
 * it, Stripe.js handles any bank authentication, and our endpoint turns the
 * confirmed intent into the saved card. The list is then redrawn from the
 * server, so the new card appears exactly as it will on every later visit.
 */

interface StripeConfig {
  key: string
  ajaxUrl: string
  intentNonce: string
  saveNonce: string
  locale: string
}

interface StripeElementChange {
  brand?: string
  complete?: boolean
  error?: { message?: string }
}

interface StripeElement {
  mount: (el: HTMLElement) => void
  destroy: () => void
  focus: () => void
  on: (event: 'change' | 'focus' | 'blur', handler: (event: StripeElementChange) => void) => void
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
}

type StripeFactory = (key: string, options?: Record<string, unknown>) => StripeInstance

interface AjaxResponse<T> {
  success: boolean
  data?: T & { message?: string; error?: { message?: string } }
}

interface OpenCard {
  stripe: StripeInstance
  number: StripeElement
  expiry: StripeElement
  cvc: StripeElement
}

const STRIPE_JS = 'https://js.stripe.com/v3/'
const GENERIC_ERROR = 'Não foi possível salvar o cartão. Tente de novo.'

const open = new WeakMap<HTMLElement, OpenCard>()

let stripeLoader: Promise<StripeFactory> | null = null

/** Stripe.js once per page, whether the page or the plugin already loaded it. */
function loadStripe(): Promise<StripeFactory> {
  const existing = (window as unknown as { Stripe?: StripeFactory }).Stripe
  if (existing) return Promise.resolve(existing)

  stripeLoader ??= new Promise<StripeFactory>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = STRIPE_JS
    script.async = true
    script.onload = () => {
      const factory = (window as unknown as { Stripe?: StripeFactory }).Stripe
      if (factory) resolve(factory)
      else reject(new Error('Stripe.js did not load'))
    }
    script.onerror = () => {
      stripeLoader = null
      reject(new Error('Stripe.js did not load'))
    }
    document.head.appendChild(script)
  })

  return stripeLoader
}

function readConfig(root: HTMLElement): StripeConfig | null {
  try {
    return root.dataset.stripe ? (JSON.parse(root.dataset.stripe) as StripeConfig) : null
  } catch {
    return null
  }
}

function message(root: HTMLElement, text: string, ok: boolean): void {
  const line = root.querySelector<HTMLElement>(':scope > .galaxie-account-message')
  if (!line) return

  line.className = `galaxie-account-message ${ok ? 'is-success' : 'is-error'}`
  line.textContent = text
  line.hidden = text === ''
}

function busy(item: HTMLElement, on: boolean): void {
  item.classList.toggle('is-busy', on)
  item.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
    button.disabled = on
  })
}

/**
 * Stripe's fields live in iframes and inherit nothing from the page, so the
 * card's own computed text styles are handed to them — the number looks like
 * the number printed on the cards around it.
 */
function styleFrom(el: HTMLElement): Record<string, unknown> {
  const css = getComputedStyle(el)
  const base: Record<string, unknown> = {
    color: css.color,
    fontFamily: css.fontFamily,
    fontSize: css.fontSize,
    fontWeight: css.fontWeight,
    iconColor: css.color,
  }

  // Stripe rejects properties it does not know and values it cannot parse, so
  // only what the card really sets is passed on.
  if (css.letterSpacing && css.letterSpacing !== 'normal') base.letterSpacing = css.letterSpacing

  // Its default placeholder grey disappears on a dark card: the card's own
  // colour, faded, reads on any background.
  const rgb = css.color.match(/\d+(\.\d+)?/g)
  if (rgb && rgb.length >= 3) base['::placeholder'] = { color: `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, 0.55)` }

  return {
    base,
    invalid: { color: css.color, iconColor: css.color },
  }
}

function showBrand(item: HTMLElement, brand: string | undefined): void {
  const known = brand === 'visa' || brand === 'mastercard' ? brand : 'generic'

  item.querySelectorAll<HTMLElement>('.galaxie-pm-brand-mark').forEach((mark) => {
    mark.hidden = mark.dataset.brand !== known
  })
}

async function openNewCard(root: HTMLElement, config: StripeConfig): Promise<void> {
  const item = root.querySelector<HTMLElement>('.galaxie-pm-new')
  const toolbar = root.querySelector<HTMLElement>('.galaxie-pm-toolbar')
  if (!item || open.has(item)) return

  message(root, '', true)
  item.hidden = false
  if (toolbar) toolbar.hidden = true
  root.querySelector<HTMLElement>('.galaxie-pm-empty')?.setAttribute('hidden', '')
  busy(item, true)

  try {
    const factory = await loadStripe()
    const stripe = factory(config.key, { locale: config.locale || 'auto' })
    const elements = stripe.elements()
    const slot = (field: string) => item.querySelector<HTMLElement>(`.galaxie-pm-stripe[data-field="${field}"]`)
    const numberSlot = slot('cardNumber')
    const expirySlot = slot('cardExpiry')
    const cvcSlot = slot('cardCvc')
    if (!numberSlot || !expirySlot || !cvcSlot) throw new Error('Card fields missing')

    const number = elements.create('cardNumber', { style: styleFrom(numberSlot.parentElement ?? numberSlot), placeholder: '•••• •••• •••• ••••', showIcon: false })
    const expiry = elements.create('cardExpiry', { style: styleFrom(expirySlot.parentElement ?? expirySlot) })
    const cvc = elements.create('cardCvc', { style: styleFrom(cvcSlot.parentElement ?? cvcSlot) })

    number.mount(numberSlot)
    expiry.mount(expirySlot)
    cvc.mount(cvcSlot)

    const pairs: Array<[StripeElement, HTMLElement]> = [
      [number, numberSlot],
      [expiry, expirySlot],
      [cvc, cvcSlot],
    ]

    for (const [field, box] of pairs) {
      field.on('focus', () => box.classList.add('is-focused'))
      field.on('blur', () => box.classList.remove('is-focused'))
      field.on('change', (event) => {
        box.classList.toggle('is-invalid', Boolean(event.error))
        message(root, event.error?.message ?? '', !event.error)
      })

      // The iframe is only as big as its text line; the label and the padding
      // around it are part of the field too.
      box.closest<HTMLElement>('.galaxie-pm-input')?.addEventListener('click', () => field.focus())
    }

    number.on('change', (event) => showBrand(item, event.brand))

    open.set(item, { stripe, number, expiry, cvc })
    item.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  } catch {
    closeNewCard(root)
    message(root, GENERIC_ERROR, false)
  } finally {
    busy(item, false)
  }
}

function closeNewCard(root: HTMLElement): void {
  const item = root.querySelector<HTMLElement>('.galaxie-pm-new')
  const toolbar = root.querySelector<HTMLElement>('.galaxie-pm-toolbar')
  if (!item) return

  const fields = open.get(item)
  if (fields) {
    fields.number.destroy()
    fields.expiry.destroy()
    fields.cvc.destroy()
    open.delete(item)
  }

  showBrand(item, undefined)
  item.hidden = true
  if (toolbar) toolbar.hidden = false

  if (!root.querySelector('.galaxie-pm-item:not(.galaxie-pm-new)')) {
    root.querySelector<HTMLElement>('.galaxie-pm-empty')?.removeAttribute('hidden')
  }
}

async function postForm<T>(url: string, body: Record<string, string>): Promise<AjaxResponse<T>> {
  const response = await fetch(url, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) })
  return (await response.json()) as AjaxResponse<T>
}

/** The widget as the server draws it now, swapped in place of the old one. */
async function redraw(root: HTMLElement): Promise<HTMLElement | null> {
  const all = [...document.querySelectorAll<HTMLElement>('.galaxie-payment-methods')]
  const index = all.indexOf(root)
  const html = await (await fetch(window.location.href, { credentials: 'same-origin' })).text()
  const fresh = new DOMParser().parseFromString(html, 'text/html').querySelectorAll<HTMLElement>('.galaxie-payment-methods')[index]

  if (!fresh) return null

  const node = document.importNode(fresh, true)
  root.replaceWith(node)
  return node
}

async function saveNewCard(root: HTMLElement, config: StripeConfig): Promise<void> {
  const item = root.querySelector<HTMLElement>('.galaxie-pm-new')
  const fields = item ? open.get(item) : undefined
  if (!item || !fields) return

  busy(item, true)
  message(root, '', true)

  try {
    const created = await fields.stripe.createPaymentMethod({ type: 'card', card: fields.number })
    if (created.error || !created.paymentMethod) throw new Error(created.error?.message ?? GENERIC_ERROR)

    const intent = await postForm<{ status: string; id: string; client_secret: string }>(config.ajaxUrl, {
      action: 'wc_stripe_create_and_confirm_setup_intent',
      _ajax_nonce: config.intentNonce,
      'wc-stripe-payment-method': created.paymentMethod.id,
      'wc-stripe-payment-type': 'card',
    })

    if (!intent.success || !intent.data) throw new Error(intent.data?.error?.message ?? GENERIC_ERROR)

    let setupIntentId = intent.data.id

    if (intent.data.status === 'requires_action') {
      const authenticated = await fields.stripe.handleNextAction({ clientSecret: intent.data.client_secret })
      if (authenticated.error || authenticated.setupIntent?.status !== 'succeeded') {
        throw new Error(authenticated.error?.message ?? GENERIC_ERROR)
      }
      setupIntentId = authenticated.setupIntent.id
    }

    const saved = await postForm<{ token: number }>(config.ajaxUrl, {
      action: 'galaxie_stripe_save_card',
      nonce: config.saveNonce,
      setup_intent: setupIntentId,
    })

    if (!saved.success) throw new Error(saved.data?.message ?? GENERIC_ERROR)

    const done = root.dataset.saved ?? ''
    closeNewCard(root)

    const fresh = await redraw(root)
    if (fresh) message(fresh, done, true)
  } catch (error) {
    busy(item, false)
    message(root, error instanceof Error && error.message ? error.message : GENERIC_ERROR, false)
  }
}

export function bootPaymentMethods(): void {
  document.addEventListener('click', (event) => {
    const target = event.target as Element | null
    const root = target?.closest?.<HTMLElement>('.galaxie-payment-methods')
    if (!target || !root) return

    const config = readConfig(root)
    if (!config) return

    if (target.closest('.galaxie-pm-add')) {
      event.preventDefault()
      void openNewCard(root, config)
      return
    }

    if (target.closest('.galaxie-pm-cancel')) {
      event.preventDefault()
      closeNewCard(root)
      return
    }

    if (target.closest('.galaxie-pm-save')) {
      event.preventDefault()
      void saveNewCard(root, config)
    }
  })
}
