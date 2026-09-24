/**
 * Behaviour for the account screen widgets: personal details, interests,
 * communication, account deletion and the wishlist.
 *
 * The markup is the server's, pixfort's classes and all; this only posts to the
 * handlers the React tabs used before and reports back in the widget's own
 * message line. Every listener is delegated from the document, so a widget
 * dropped in by a template or drawn as a screen default works the same.
 */

import { getGalaxieConfig, post } from '@/lib/wp'
import { ask } from '@/lib/dialog'
import { attachPhoneInput, readPhone } from '@/lib/phone'

interface WishlistConfig {
  ajaxUrl: string
  nonce: string
}

const GENERIC_ERROR = 'Algo deu errado. Tente de novo.'

function message(root: Element | null, text: string, ok: boolean): void {
  const line = root?.querySelector<HTMLElement>('.galaxie-account-message')
  if (!line) return

  const host = root as HTMLElement
  const classes = (ok ? host.dataset.okClass : host.dataset.errorClass) ?? host.dataset.msgClass ?? ''

  line.className = `galaxie-account-message ${ok ? 'is-success' : 'is-error'} ${classes}`.trim()
  line.textContent = text
  line.hidden = text === ''
}

function busy(el: Element | null, on: boolean): void {
  el?.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
    button.disabled = on
  })
  el?.classList.toggle('is-busy', on)
}

/** 12345678901 → 123.456.789-01, as the customer types. */
function maskCpf(value: string): string {
  const digits = value.replace(/\D/g, '').slice(0, 11)

  return digits
    .replace(/^(\d{3})(\d)/, '$1.$2')
    .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2')
}

function sparkle(el: HTMLElement): void {
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return

  for (let i = 0; i < 7; i++) {
    const particle = document.createElement('span')
    const angle = Math.random() * Math.PI * 2
    const distance = 18 + Math.random() * 22

    particle.className = 'galaxie-particle'
    particle.textContent = '✦'
    particle.style.setProperty('--gx-dx', `${Math.cos(angle) * distance}px`)
    particle.style.setProperty('--gx-dy', `${Math.sin(angle) * distance - 10}px`)
    particle.style.setProperty('--gx-duration', `${500 + Math.random() * 300}ms`)
    particle.style.left = '50%'
    particle.style.top = '50%'
    el.appendChild(particle)
    particle.addEventListener('animationend', () => particle.remove())
  }
}

/** The details form's phone field, which becomes intl-tel-input's flag field. */
const PHONE_FIELD = '.galaxie-details-form input[name="phone"]'

/** Loads the library only when there is a phone field to put it on. */
function attachPhones(root: ParentNode): void {
  root.querySelectorAll<HTMLInputElement>(PHONE_FIELD).forEach((input) => {
    attachPhoneInput(input).catch(() => undefined)
  })
}

export function bootAccountScreens(_wishlist?: WishlistConfig): void {
  const config = getGalaxieConfig()

  // Personal details.
  attachPhones(document)
  // A screen the account menu swaps in, and, as a fallback for markup drawn
  // some other way (Elementor's editor), the first touch of the field.
  document.addEventListener('galaxie:account-screen', (event) => {
    attachPhones((event as CustomEvent<Element>).detail ?? document)
  })
  document.addEventListener('focusin', (event) => {
    const input = event.target as HTMLInputElement | null
    if (input?.matches?.(PHONE_FIELD)) attachPhoneInput(input).catch(() => undefined)
  })

  document.addEventListener('submit', (event) => {
    const form = (event.target as Element | null)?.closest?.<HTMLFormElement>('.galaxie-details-form')
    if (!form || !config.myAccount) return

    event.preventDefault()

    const myAccount = config.myAccount
    const data = Object.fromEntries(
      [...new FormData(form).entries()].filter(([key]) => key !== 'email').map(([key, value]) => [key, String(value)])
    )
    const phoneInput = form.querySelector<HTMLInputElement>('input[name="phone"]')

    busy(form, true)
    message(form, '', true)

    void (async () => {
      // Sent in E.164, +5511980409005, the format FluentCRM keeps. An invalid
      // number stops here with the handler's own message; if the library
      // could not load, the typed text goes and the server decides.
      if (phoneInput && 'phone' in data) {
        const iti = await attachPhoneInput(phoneInput).catch(() => null)
        const phone = await readPhone(phoneInput, iti)

        if (false === phone.valid) {
          busy(form, false)
          message(form, form.dataset.phoneError || GENERIC_ERROR, false)
          phoneInput.focus()
          return
        }

        data.phone = phone.value
      }

      const res = await post(myAccount.ajaxUrl, 'galaxie_myaccount_save_details', myAccount.nonce, data)
      busy(form, false)
      message(form, res.success ? (form.dataset.saved ?? '') : (res.data?.message ?? GENERIC_ERROR), res.success)
    })()
  })

  document.addEventListener('input', (event) => {
    const input = event.target as HTMLInputElement | null
    if (input?.matches?.('.galaxie-details-form input[name="cpf"]')) {
      input.value = maskCpf(input.value)
    }
  })

  // Interests.
  document.addEventListener('click', (event) => {
    const pill = (event.target as Element | null)?.closest?.<HTMLButtonElement>('.galaxie-interest')
    const root = pill?.closest<HTMLElement>('.galaxie-account-interests')
    if (!pill || !root || !config.myAccount || pill.disabled) return

    const on = !pill.classList.contains('is-selected')

    pill.classList.toggle('is-selected', on)
    pill.setAttribute('aria-pressed', String(on))
    pill.disabled = true
    if (on && root.dataset.sparkles) sparkle(pill)
    message(root, '', true)

    void post(config.myAccount.ajaxUrl, 'galaxie_myaccount_toggle_interest', config.myAccount.nonce, {
      tag_id: pill.dataset.tag ?? '',
      selected: on ? '1' : '',
    }).then((res) => {
      pill.disabled = false
      if (res.success) return

      pill.classList.toggle('is-selected', !on)
      pill.setAttribute('aria-pressed', String(!on))
      message(root, res.data?.message ?? GENERIC_ERROR, false)
    })
  })

  // Communication.
  document.addEventListener('change', (event) => {
    const input = event.target as HTMLInputElement | null
    const root = input?.closest?.<HTMLElement>('.galaxie-account-communication')
    if (!input || !root || !config.myAccount) return
    if (input.name !== 'opt_in' && input.name !== 'communication') return

    const on = input.checked
    input.disabled = true

    // The consent is a field of the customer's; a communication is a FluentCRM
    // list and nothing else. Same switch, same messages, different answer.
    const list = input.value
    const action = input.name === 'communication' ? 'galaxie_myaccount_toggle_communication' : 'galaxie_myaccount_save_communication'
    const body =
      input.name === 'communication' ? { list_id: list, selected: on ? '1' : '' } : { opt_in: on ? '1' : '' }

    void post(config.myAccount.ajaxUrl, action, config.myAccount.nonce, body).then((res) => {
      input.disabled = false

      if (res.success) {
        message(root, (on ? root.dataset.on : root.dataset.off) ?? '', true)
        return
      }

      input.checked = !on
      message(root, res.data?.message ?? GENERIC_ERROR, false)
    })
  })

  // Account deletion.
  document.addEventListener('click', (event) => {
    const target = event.target as Element | null
    const root = target?.closest?.<HTMLElement>('.galaxie-account-delete')
    const dialog = root?.querySelector<HTMLDialogElement>('.galaxie-delete-dialog')
    if (!root || !dialog) return

    if (target?.closest('.galaxie-delete-open')) {
      message(dialog, '', true)
      dialog.showModal()
      return
    }

    if (target?.closest('.galaxie-delete-cancel') || target === dialog) {
      dialog.close()
      return
    }

    if (target?.closest('.galaxie-delete-confirm') && config.accountDeletion) {
      busy(dialog, true)

      void post<{ redirect?: string }>(config.accountDeletion.ajaxUrl, 'galaxie_request_account_deletion', config.accountDeletion.nonce).then(
        (res) => {
          if (res.success) {
            window.location.href = res.data?.redirect ?? '/'
            return
          }

          busy(dialog, false)
          dialog.dataset.msgClass = root.dataset.msgClass
          message(dialog, res.data?.message ?? GENERIC_ERROR, false)
        }
      )
    }
  })

  // Orders: WooCommerce cancels an order on a plain link, so ask first.
  document.addEventListener('click', (event) => {
    const link = (event.target as Element | null)?.closest?.<HTMLAnchorElement>('.galaxie-account-button.is-cancel')
    const root = link?.closest<HTMLElement>('.galaxie-account-orders, .galaxie-account-order')
    if (!link || !root) return

    event.preventDefault()
    void ask(root, 'cancel_confirm', 'Cancelar este pedido?').then((yes) => {
      if (yes) window.location.href = link.href
    })
  })

  // Payment methods: WooCommerce deletes a saved card on a plain link, so ask first.
  document.addEventListener('click', (event) => {
    const link = (event.target as Element | null)?.closest?.<HTMLAnchorElement>('.galaxie-pm-delete')
    const root = link?.closest<HTMLElement>('.galaxie-payment-methods')
    if (!link || !root) return

    event.preventDefault()
    void ask(root, 'pm_confirm', 'Excluir este cartão?').then((yes) => {
      if (yes) window.location.href = link.href
    })
  })

  // The wishlist screen's own behaviour lives in globals/wishlist-account.ts.
}
