/**
 * Adding a card from the payment methods widget, without leaving the page.
 *
 * The form opens below the cards, the way an address is added. Stripe's
 * fields and the save itself are lib/stripe-cards.ts, shared with the
 * checkout; this file is the widget around them. The list is then redrawn
 * from the server, and the customer is asked whether the new card becomes
 * the default.
 */

import { ask } from '@/lib/dialog'
import {
  destroyCardFields,
  GENERIC_ERROR,
  mountCardFields,
  saveCardToken,
  type CardBoxes,
  type CardFields,
  type StripeConfig,
} from '@/lib/stripe-cards'

interface OpenForm {
  fields: CardFields
  boxes: CardBoxes
}

const DEFAULT_ERROR = 'Não foi possível atualizar o cartão padrão. Tente de novo.'
const SAVED_FALLBACK = 'Cartão salvo.'

const open = new WeakMap<HTMLElement, OpenForm>()

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
    const slot = (field: string) => form.querySelector<HTMLElement>(`.galaxie-pm-stripe[data-field="${field}"]`)
    const numberBox = slot('cardNumber')
    const expiryBox = slot('cardExpiry')
    const cvcBox = slot('cardCvc')
    if (!numberBox || !expiryBox || !cvcBox) throw new Error('Card fields missing')

    const boxes: CardBoxes = { number: numberBox, expiry: expiryBox, cvc: cvcBox }
    const probe = form.querySelector<HTMLElement>('.galaxie-pm-placeholder')
    const fields = await mountCardFields(config, boxes, probe, (text) => message(root, text, '' === text))

    // The iframe is only as tall as its text line; the label and the padding
    // around it are part of the field too.
    for (const [field, box] of [
      [fields.number, numberBox],
      [fields.expiry, expiryBox],
      [fields.cvc, cvcBox],
    ] as const) {
      box.closest<HTMLElement>('.galaxie-pm-field')?.addEventListener('click', () => field.focus())
    }

    open.set(form, { fields, boxes })
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

  const current = open.get(form)
  if (current) {
    destroyCardFields(current.fields, current.boxes)
    open.delete(form)
  }

  form.hidden = true
  if (toolbar) toolbar.hidden = false
}

/**
 * The widget as the server draws it now, swapped in place of the old one; null
 * when the page could not be fetched or no longer holds the widget.
 */
async function redraw(root: HTMLElement): Promise<HTMLElement | null> {
  const all = [...document.querySelectorAll<HTMLElement>('.galaxie-payment-methods')]
  const index = all.indexOf(root)

  let html: string
  try {
    const response = await fetch(window.location.href, { credentials: 'same-origin' })
    if (!response.ok) return null
    html = await response.text()
  } catch {
    return null
  }

  const fresh = new DOMParser().parseFromString(html, 'text/html').querySelectorAll<HTMLElement>('.galaxie-payment-methods')[index]

  if (!fresh) return null

  const node = document.importNode(fresh, true)
  root.replaceWith(node)
  return node
}

/**
 * The list could not be redrawn, but the server has already changed it: the
 * page is reloaded instead of leaving an old list on screen — a list the
 * customer would "fix" by saving the same card a second time.
 */
function reloadAfter(root: HTMLElement, text: string): void {
  busy(root, true)
  message(root, text, true)
  window.location.reload()
}

/**
 * With other cards already saved, the new one is not the default — WooCommerce
 * only makes the first card default by itself. Its own "make default" link is
 * on the redrawn card; following it in the background is exactly what a click
 * on that button would have done.
 */
async function offerDefault(root: HTMLElement, token: string): Promise<void> {
  const card = `.galaxie-pm-item[data-token="${token}"]`
  const link = root.querySelector<HTMLAnchorElement>(`${card} .galaxie-pm-default`)
  if (!link) return

  const yes = await ask(root, 'pm_default_ask', 'Usar este cartão como padrão nos próximos pagamentos?')
  if (!yes) return

  busy(root, true)

  let reached = false
  try {
    reached = (await fetch(link.href, { credentials: 'same-origin' })).ok
  } catch {
    reached = false
  }

  if (!reached) {
    busy(root, false)
    message(root, DEFAULT_ERROR, false)
    return
  }

  const fresh = await redraw(root)

  if (!fresh) {
    reloadAfter(root, '')
    return
  }

  // WooCommerce answers a refused change — an expired nonce, a card that is
  // not this customer's — with an error notice and a redirect to an ordinary
  // page, so a successful fetch proves nothing. The redrawn card must say it.
  if (fresh.querySelector(`${card} .galaxie-pm-card.is-default`)) {
    message(fresh, root.dataset.defaultSaved ?? '', true)
  } else {
    message(fresh, DEFAULT_ERROR, false)
  }
}

async function saveCard(root: HTMLElement, config: StripeConfig): Promise<void> {
  const form = formOf(root)
  const current = form ? open.get(form) : undefined
  if (!form || !current) return

  busy(form, true)
  message(root, '', true)

  let token = ''

  try {
    token = await saveCardToken(config, current.fields)
  } catch (error) {
    busy(form, false)
    message(root, error instanceof Error && error.message ? error.message : GENERIC_ERROR, false)
    return
  }

  // From here the card is saved. Nothing below may look like a failure: a
  // retry would save it a second time.
  closeForm(root)
  busy(form, false)

  const savedText = root.dataset.saved ?? ''
  const fresh = await redraw(root)

  if (!fresh) {
    reloadAfter(root, savedText || SAVED_FALLBACK)
    return
  }

  message(fresh, savedText, true)
  if (token) await offerDefault(fresh, token)
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
