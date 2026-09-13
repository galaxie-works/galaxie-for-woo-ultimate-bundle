/**
 * Adding a card from the payment methods widget, without leaving the page.
 *
 * The form opens below the cards, the way an address is added. The number,
 * expiry and code are Stripe's own fields — iframes Stripe serves — mounted
 * into the form's field boxes, so the card data goes from the customer's
 * keyboard straight to Stripe and never through this site. The save runs the
 * WooCommerce Stripe plugin's own flow (see PHP Support\StripeCards): Stripe.js
 * creates the PaymentMethod, the plugin confirms a SetupIntent for it, Stripe.js
 * handles any bank authentication, and our endpoint turns the confirmed intent
 * into the saved card. The list is then redrawn from the server, and the
 * customer is asked whether the new card becomes the default.
 */

import { ask } from '@/lib/dialog'

interface StripeConfig {
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

interface StripeElement {
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
}

type StripeFactory = (key: string, options?: Record<string, unknown>) => StripeInstance

interface AjaxResponse<T> {
  success: boolean
  data?: T & { message?: string; error?: { message?: string } }
}

interface OpenForm {
  stripe: StripeInstance
  number: StripeElement
  expiry: StripeElement
  cvc: StripeElement
}

const STRIPE_JS = 'https://js.stripe.com/v3/'
const GENERIC_ERROR = 'Não foi possível salvar o cartão. Tente de novo.'

const open = new WeakMap<HTMLElement, OpenForm>()

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

function busy(el: HTMLElement, on: boolean): void {
  el.classList.toggle('is-busy', on)
  el.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
    button.disabled = on
  })
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

function formOf(root: HTMLElement): HTMLElement | null {
  return root.querySelector<HTMLElement>('.galaxie-pm-form')
}

async function openForm(root: HTMLElement, config: StripeConfig): Promise<void> {
  const form = formOf(root)
  const toolbar = root.querySelector<HTMLElement>('.galaxie-pm-toolbar')
  if (!form || open.has(form)) return

  message(root, '', true)
  form.hidden = false
  if (toolbar) toolbar.hidden = true
  busy(form, true)

  try {
    const factory = await loadStripe()
    const stripe = factory(config.key, { locale: config.locale || 'auto' })
    const elements = stripe.elements()
    const slot = (field: string) => form.querySelector<HTMLElement>(`.galaxie-pm-stripe[data-field="${field}"]`)
    const numberBox = slot('cardNumber')
    const expiryBox = slot('cardExpiry')
    const cvcBox = slot('cardCvc')
    if (!numberBox || !expiryBox || !cvcBox) throw new Error('Card fields missing')

    const probe = form.querySelector<HTMLElement>('.galaxie-pm-placeholder')

    // Stripe's own brand icon inside the number, since there is no card picture
    // to show it; its Link button off, which offered a different checkout.
    const number = elements.create('cardNumber', { style: styleFrom(numberBox, probe), showIcon: true, disableLink: true })
    const expiry = elements.create('cardExpiry', { style: styleFrom(expiryBox, probe) })
    const cvc = elements.create('cardCvc', { style: styleFrom(cvcBox, probe) })

    const pairs: Array<[StripeElement, HTMLElement]> = [
      [number, numberBox],
      [expiry, expiryBox],
      [cvc, cvcBox],
    ]

    for (const [field, box] of pairs) {
      field.mount(box)
      field.on('focus', () => box.classList.add('is-focused'))
      field.on('blur', () => box.classList.remove('is-focused'))
      field.on('change', (event) => {
        box.classList.toggle('is-invalid', Boolean(event.error))
        message(root, event.error?.message ?? '', !event.error)
      })

      // The iframe is only as tall as its text line; the label and the padding
      // around it are part of the field too.
      box.closest<HTMLElement>('.galaxie-pm-field')?.addEventListener('click', () => field.focus())
    }

    number.on('ready', () => number.focus())
    open.set(form, { stripe, number, expiry, cvc })
    form.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  } catch {
    closeForm(root)
    message(root, GENERIC_ERROR, false)
  } finally {
    busy(form, false)
  }
}

function closeForm(root: HTMLElement): void {
  const form = formOf(root)
  const toolbar = root.querySelector<HTMLElement>('.galaxie-pm-toolbar')
  if (!form) return

  const fields = open.get(form)
  if (fields) {
    fields.number.destroy()
    fields.expiry.destroy()
    fields.cvc.destroy()
    open.delete(form)
  }

  form.querySelectorAll('.galaxie-pm-stripe').forEach((box) => box.classList.remove('is-focused', 'is-invalid'))
  form.hidden = true
  if (toolbar) toolbar.hidden = false
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

/**
 * With other cards already saved, the new one is not the default — WooCommerce
 * only makes the first card default by itself. Its own "make default" link is
 * on the redrawn card; following it in the background is exactly what a click
 * on that button would have done.
 */
async function offerDefault(root: HTMLElement, token: string): Promise<void> {
  const link = root.querySelector<HTMLAnchorElement>(`.galaxie-pm-item[data-token="${token}"] .galaxie-pm-default`)
  if (!link) return

  const yes = await ask(root, 'pm_default_ask', 'Usar este cartão como padrão nos próximos pagamentos?')
  if (!yes) return

  busy(root, true)

  try {
    await fetch(link.href, { credentials: 'same-origin' })
    const fresh = await redraw(root)
    if (fresh) message(fresh, root.dataset.defaultSaved ?? '', true)
  } catch {
    busy(root, false)
    message(root, 'Não foi possível atualizar o cartão padrão. Tente de novo.', false)
  }
}

async function saveCard(root: HTMLElement, config: StripeConfig): Promise<void> {
  const form = formOf(root)
  const fields = form ? open.get(form) : undefined
  if (!form || !fields) return

  busy(form, true)
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

    closeForm(root)

    const fresh = await redraw(root)
    if (!fresh) return

    message(fresh, root.dataset.saved ?? '', true)
    if (saved.data?.token) await offerDefault(fresh, String(saved.data.token))
  } catch (error) {
    busy(form, false)
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
      void openForm(root, config)
      return
    }

    if (target.closest('.galaxie-pm-cancel')) {
      event.preventDefault()
      closeForm(root)
      return
    }

    if (target.closest('.galaxie-pm-save')) {
      event.preventDefault()
      void saveCard(root, config)
    }
  })
}
