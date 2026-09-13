/**
 * The address book: the account widget that keeps a customer's addresses, and
 * the saved-address picker on WooCommerce's block checkout.
 *
 * The widget's cards are the server's markup. Every change posts to the
 * module's handlers, which answer with the whole list, and the list is redrawn
 * from the `<template>` card the widget printed — so a redrawn card carries the
 * same pixfort classes as the ones the page loaded with.
 *
 * The checkout picker writes the chosen address into the block checkout's own
 * cart store. The checkout watches that store: it redraws its address form,
 * sends the address to the server and fetches rates for it, the same as if the
 * customer had typed it.
 */

import { post } from '@/lib/wp'

export interface AddressBookEntry {
  id: string
  label: string
  formatted: string
  values: Record<string, string>
  shipping: boolean
  billing: boolean
}

export interface AddressBookConfig {
  ajaxUrl: string
  nonce: string
  entries?: AddressBookEntry[]
  i18n?: { pickerTitle?: string }
}

const GENERIC_ERROR = 'Algo deu errado. Tente de novo.'

const entriesByRoot = new WeakMap<HTMLElement, AddressBookEntry[]>()

function readEntries(root: HTMLElement): AddressBookEntry[] {
  let entries = entriesByRoot.get(root)

  if (!entries) {
    try {
      entries = JSON.parse(root.dataset.entries ?? '[]') as AddressBookEntry[]
    } catch {
      entries = []
    }
    entriesByRoot.set(root, entries)
  }

  return entries
}

function message(root: HTMLElement, text: string, ok: boolean): void {
  const line = root.querySelector<HTMLElement>(':scope > .galaxie-account-message')
  if (!line) return

  line.className = `galaxie-account-message ${ok ? 'is-success' : 'is-error'} ${root.dataset.msgClass ?? ''}`.trim()
  line.textContent = text
  line.hidden = text === ''
}

function busy(root: HTMLElement, on: boolean): void {
  root.classList.toggle('is-busy', on)
  root.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
    button.disabled = on
  })
}

/** WooCommerce's country script listens through jQuery, which a native event does not reach. */
function change(el: Element): void {
  const $ = (window as unknown as { jQuery?: (el: Element) => { trigger: (event: string) => void } }).jQuery

  if ($) {
    $(el).trigger('change')
  } else {
    el.dispatchEvent(new Event('change', { bubbles: true }))
  }
}

function fillCard(card: HTMLElement, entry: AddressBookEntry): HTMLElement {
  card.dataset.id = entry.id

  const label = card.querySelector<HTMLElement>('.galaxie-ab-label')
  if (label) {
    label.textContent = entry.label
    label.hidden = entry.label === ''
  }

  const shipping = card.querySelector<HTMLElement>('.galaxie-ab-badge.is-shipping')
  const billing = card.querySelector<HTMLElement>('.galaxie-ab-badge.is-billing')
  if (shipping) shipping.hidden = !entry.shipping
  if (billing) billing.hidden = !entry.billing

  // Escaped by the server, which formats it with WooCommerce's own rules.
  const address = card.querySelector<HTMLElement>('.galaxie-ab-address')
  if (address) address.innerHTML = entry.formatted

  card.querySelectorAll<HTMLElement>('.galaxie-ab-default').forEach((button) => {
    button.hidden = button.dataset.type === 'shipping' ? entry.shipping : entry.billing
  })

  const remove = card.querySelector<HTMLElement>('.galaxie-ab-delete')
  if (remove) remove.hidden = entry.shipping || entry.billing

  return card
}

function render(root: HTMLElement, entries: AddressBookEntry[]): void {
  entriesByRoot.set(root, entries)

  const list = root.querySelector<HTMLElement>('.galaxie-ab-cards')
  const blank = root.querySelector<HTMLTemplateElement>('template.galaxie-ab-card-template')?.content.querySelector<HTMLElement>('.galaxie-ab-card')
  const empty = root.querySelector<HTMLElement>('.galaxie-ab-empty')
  if (!list || !blank) return

  list.replaceChildren(...entries.map((entry) => fillCard(blank.cloneNode(true) as HTMLElement, entry)))
  list.hidden = entries.length === 0
  if (empty) empty.hidden = entries.length > 0
}

function setField(form: HTMLFormElement, name: string, value: string | undefined): void {
  const field = form.elements.namedItem(name)

  if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
    field.value = value ?? ''
    if (name === 'shipping_country') change(field)
  }
}

function openForm(root: HTMLElement, entry: AddressBookEntry | null): void {
  const form = root.querySelector<HTMLFormElement>('form.galaxie-ab-form')
  if (!form) return

  form.reset()

  const country = form.elements.namedItem('shipping_country')
  if (country instanceof Element) change(country)

  setField(form, 'id', entry?.id ?? '')

  const title = form.querySelector<HTMLElement>('.galaxie-ab-form-title')
  if (title) title.textContent = (entry ? form.dataset.titleEdit : form.dataset.titleNew) ?? ''

  if (entry) {
    setField(form, 'galaxie_ab_label', entry.label)
    // Country first: changing it rebuilds the state list the state is chosen from.
    setField(form, 'shipping_country', entry.values.country)

    for (const [key, value] of Object.entries(entry.values)) {
      if (key !== 'country') setField(form, `shipping_${key}`, value)
    }
  }

  form.hidden = false

  const toolbar = root.querySelector<HTMLElement>('.galaxie-ab-toolbar')
  if (toolbar) toolbar.hidden = true

  form.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  form.querySelector<HTMLInputElement>('input:not([type="hidden"])')?.focus({ preventScroll: true })
}

function closeForm(root: HTMLElement): void {
  const form = root.querySelector<HTMLFormElement>('form.galaxie-ab-form')
  const toolbar = root.querySelector<HTMLElement>('.galaxie-ab-toolbar')

  if (form) form.hidden = true
  if (toolbar) toolbar.hidden = false
}

async function act(
  root: HTMLElement,
  config: AddressBookConfig,
  action: string,
  data: Record<string, string>,
  done: string
): Promise<boolean> {
  busy(root, true)
  message(root, '', true)

  const res = await post<{ entries?: AddressBookEntry[] }>(config.ajaxUrl, action, config.nonce, data)

  busy(root, false)

  if (!res.success) {
    message(root, res.data?.message ?? GENERIC_ERROR, false)
    return false
  }

  render(root, res.data?.entries ?? [])
  message(root, done, true)

  return true
}

// Block checkout.

type CartAddress = Record<string, string>

interface CartSelectors {
  getCustomerData?: () => { shippingAddress?: CartAddress; billingAddress?: CartAddress }
}

interface CartActions {
  setShippingAddress?: (address: CartAddress) => void
  setBillingAddress?: (address: CartAddress) => void
}

interface WpData {
  select: (store: string) => CartSelectors | undefined
  dispatch: (store: string) => CartActions | undefined
}

const CART_STORE = 'wc/store/cart'

const ADDRESS_KEYS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone']

function wpData(): WpData | null {
  return (window as unknown as { wp?: { data?: WpData } }).wp?.data ?? null
}

/** Mirrors the server's matching: the place, not the recipient. */
function fingerprint(address: Record<string, string | undefined>): string {
  return [address.country, (address.postcode ?? '').replace(/\W+/g, ''), address.address_1, address.address_2, address.city, address.state]
    .map((part) => (part ?? '').trim().toLowerCase().replace(/\s+/g, ' '))
    .join('|')
}

function toCartAddress(values: Record<string, string>): CartAddress {
  const address: CartAddress = {}

  for (const key of ADDRESS_KEYS) {
    const value = values[key] ?? ''
    // An address saved without a phone must not erase the one being typed.
    if (key === 'phone' && value === '') continue
    address[key] = value
  }

  return address
}

function buildPicker(config: AddressBookConfig, entries: AddressBookEntry[], kind: 'shipping' | 'billing', data: WpData): HTMLElement {
  const picker = document.createElement('div')
  picker.className = 'galaxie-ab-picker'

  const title = document.createElement('p')
  title.className = 'galaxie-ab-picker-title'
  title.textContent = config.i18n?.pickerTitle ?? 'Endereços salvos'

  const list = document.createElement('div')
  list.className = 'galaxie-ab-picker-list'
  list.setAttribute('role', 'group')
  list.setAttribute('aria-label', title.textContent)

  const current = (): string => {
    const customer = data.select(CART_STORE)?.getCustomerData?.()
    return fingerprint((kind === 'shipping' ? customer?.shippingAddress : customer?.billingAddress) ?? {})
  }

  const mark = (): void => {
    const place = current()
    list.querySelectorAll<HTMLButtonElement>('.galaxie-ab-picker-item').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.place === place))
    })
  }

  for (const entry of entries) {
    const button = document.createElement('button')
    const name = document.createElement('span')
    const line = document.createElement('span')
    const v = entry.values

    button.type = 'button'
    button.className = 'galaxie-ab-picker-item'
    button.dataset.place = fingerprint(v)

    name.className = 'galaxie-ab-picker-item-label'
    name.textContent = entry.label || [v.first_name, v.last_name].filter(Boolean).join(' ')

    line.className = 'galaxie-ab-picker-item-address'
    line.textContent = [v.address_1, v.address_2, [v.city, v.state].filter(Boolean).join(' - '), v.postcode].filter(Boolean).join(', ')

    button.append(name, line)
    button.addEventListener('click', () => {
      const actions = data.dispatch(CART_STORE)
      const customer = data.select(CART_STORE)?.getCustomerData?.()
      const address = toCartAddress(v)

      // Spread over what is already there, so the billing email survives.
      if (kind === 'shipping') {
        actions?.setShippingAddress?.({ ...(customer?.shippingAddress ?? {}), ...address })
      } else {
        actions?.setBillingAddress?.({ ...(customer?.billingAddress ?? {}), ...address })
      }

      mark()
    })

    list.append(button)
  }

  picker.append(title, list)
  mark()

  return picker
}

/**
 * The checkout is a React tree drawn after load and redrawn at will, so the
 * picker is placed by watching for the address step rather than once: it goes
 * back in whenever the step is drawn without it. Shipping when the order
 * ships, billing when there is no shipping step to fill.
 */
function bootCheckoutPicker(config: AddressBookConfig): void {
  const entries = config.entries ?? []
  if (entries.length === 0) return

  let queued = false

  const place = (): void => {
    queued = false

    const data = wpData()
    if (!data) return

    const step = document.querySelector<HTMLElement>('#shipping-fields') ?? document.querySelector<HTMLElement>('#billing-fields')
    if (!step || step.querySelector('.galaxie-ab-picker')) return

    const kind = step.id === 'shipping-fields' ? 'shipping' : 'billing'
    const content = step.querySelector<HTMLElement>('.wc-block-components-checkout-step__content') ?? step

    content.prepend(buildPicker(config, entries, kind, data))
  }

  new MutationObserver(() => {
    if (queued) return
    queued = true
    window.requestAnimationFrame(place)
  }).observe(document.body, { childList: true, subtree: true })

  place()
}

export function bootAddressBook(config?: AddressBookConfig): void {
  if (!config) return

  document.addEventListener('click', (event) => {
    const target = event.target as Element | null
    const root = target?.closest?.<HTMLElement>('.galaxie-address-book')
    if (!target || !root) return

    if (target.closest('.galaxie-ab-add')) {
      message(root, '', true)
      openForm(root, null)
      return
    }

    if (target.closest('.galaxie-ab-cancel')) {
      closeForm(root)
      return
    }

    const card = target.closest<HTMLElement>('.galaxie-ab-card')
    const entry = card ? readEntries(root).find((item) => item.id === card.dataset.id) : undefined
    if (!entry) return

    if (target.closest('.galaxie-ab-edit')) {
      message(root, '', true)
      openForm(root, entry)
      return
    }

    if (target.closest('.galaxie-ab-delete')) {
      if (window.confirm(root.dataset.confirm ?? '')) {
        void act(root, config, 'galaxie_address_book_delete', { id: entry.id }, root.dataset.saved ?? '')
      }
      return
    }

    const makeDefault = target.closest<HTMLElement>('.galaxie-ab-default')
    if (makeDefault) {
      void act(root, config, 'galaxie_address_book_default', { id: entry.id, type: makeDefault.dataset.type ?? '' }, root.dataset.saved ?? '')
    }
  })

  document.addEventListener('submit', (event) => {
    const form = (event.target as Element | null)?.closest?.<HTMLFormElement>('form.galaxie-ab-form')
    const root = form?.closest<HTMLElement>('.galaxie-address-book')
    if (!form || !root) return

    event.preventDefault()

    const data = Object.fromEntries([...new FormData(form).entries()].map(([key, value]) => [key, String(value)]))

    void act(root, config, 'galaxie_address_book_save', data, root.dataset.saved ?? '').then((ok) => {
      if (ok) closeForm(root)
    })
  })

  bootCheckoutPicker(config)
}
