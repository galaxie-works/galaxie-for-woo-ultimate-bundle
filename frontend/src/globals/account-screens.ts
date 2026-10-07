/**
 * Behaviour for the account screen widgets: personal details, interests,
 * communication, account deletion and the wishlist.
 *
 * The markup is the server's, pixfort's classes and all; this only posts to the
 * handlers the React tabs used before and reports back in the widget's own
 * message line. Every listener is delegated from the document, so a widget
 * dropped in by a template or drawn as a screen default works the same.
 */

import { getGalaxieConfig, post, type OrderCancellationConfig } from '@/lib/wp'
import { ask, tell } from '@/lib/dialog'
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
  openPostedNotice()

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

    void post<{ consented?: boolean }>(config.myAccount.ajaxUrl, action, config.myAccount.nonce, body).then((res) => {
      input.disabled = false

      if (res.success) {
        // Turning a communication on without consent gave it (the line under
        // the switch said so): the consent switch follows, and the line goes.
        if (res.data?.consented) {
          root.querySelectorAll<HTMLInputElement>('input[name="opt_in"]').forEach((optIn) => {
            optIn.checked = true
          })
          root.querySelectorAll('.galaxie-comm-consent-hint').forEach((hint) => hint.remove())
        }

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

  // Orders: WooCommerce cancels an order on a plain link, so ask first. Our
  // own link is caught wherever it is drawn — Galaxie's buttons, WooCommerce's
  // orders table or order details — since without the reason it cannot go.
  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

    const target = event.target as Element | null
    const link =
      target?.closest?.<HTMLAnchorElement>('.galaxie-account-button.is-cancel') ??
      target?.closest?.<HTMLAnchorElement>('a[href*="action=galaxie_cancel_order"]')
    if (!link) return

    const root = link.closest<HTMLElement>('.galaxie-account-orders, .galaxie-account-order')
    const ours = link.href.includes('action=galaxie_cancel_order')
    if (!root && !ours) return

    event.preventDefault()
    startCancel(link, root)
  })

  openCancelFromLink()

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

/**
 * The cancel flow for one link: a paid order (Order Cancellation module) is
 * checked with Melhor Envio, then the reason is asked and posted with the
 * link's own fields; WooCommerce's unpaid-order link is just confirmed.
 */
function startCancel(link: HTMLAnchorElement, root: HTMLElement | null): void {
  const cfg = getGalaxieConfig().orderCancellation
  if (cfg && link.href.includes('action=galaxie_cancel_order')) {
    const url = new URL(link.href, window.location.href)

    // Already on its way: the in-transit notice, and nothing else.
    if ('1' === url.searchParams.get('posted')) {
      void tellPosted(root, cfg)
      return
    }

    // Asked before the reason, so nobody fills one in to hear no.
    link.setAttribute('aria-busy', 'true')
    void post<{ posted?: boolean }>(cfg.ajaxUrl, 'galaxie_cancel_check', url.searchParams.get('_wpnonce') ?? '', {
      order_id: url.searchParams.get('order_id') ?? '',
    }).then((res) => {
      link.removeAttribute('aria-busy')

      if (res.success && res.data?.posted) {
        url.searchParams.set('posted', '1')
        link.href = url.toString()
        void tellPosted(root, cfg)
        return
      }

      const form = reasonForm(cfg)
      void ask(root, 'cancel_confirm', { fallback: 'Cancelar este pedido?', extra: form.el, validate: form.validate }).then((yes) => {
        if (yes) submitCancel(link.href, form.reason(), form.comment())
      })
    })
    return
  }

  void ask(root, 'cancel_confirm', 'Cancelar este pedido?').then((yes) => {
    if (yes) window.location.href = link.href
  })
}

/**
 * A bare Cancel link (WooCommerce's own button, followed without this script
 * or from a page it does not run on) lands on the order's screen with
 * `galaxie-cancel=<order id>`: the dialog opens for that order's link, and the
 * mark leaves the address so a reload does not ask again.
 */
function openCancelFromLink(): void {
  const params = new URLSearchParams(window.location.search)
  const id = params.get('galaxie-cancel')
  if (!id) return

  params.delete('galaxie-cancel')
  const query = params.toString()
  window.history.replaceState(window.history.state, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash)

  if (!/^\d+$/.test(id)) return

  const link = [...document.querySelectorAll<HTMLAnchorElement>('a[href*="action=galaxie_cancel_order"]')].find(
    (item) => new URL(item.href, window.location.href).searchParams.get('order_id') === id
  )
  if (!link) return

  startCancel(link, link.closest<HTMLElement>('.galaxie-account-orders, .galaxie-account-order'))
}

/** The reason (required) and comment (optional) asked before a paid order is cancelled. */
function reasonForm(cfg: OrderCancellationConfig) {
  const el = document.createElement('div')
  el.className = 'galaxie-cancel-reason'

  const label = document.createElement('label')
  label.className = 'galaxie-cancel-reason-field'
  label.textContent = cfg.question

  const select = document.createElement('select')
  select.className = 'form-control'
  select.required = true
  select.add(new Option(cfg.choose, ''))
  for (const reason of cfg.reasons) select.add(new Option(reason, reason))
  label.appendChild(select)

  const commentLabel = document.createElement('label')
  commentLabel.className = 'galaxie-cancel-reason-field'
  commentLabel.textContent = cfg.comment

  const textarea = document.createElement('textarea')
  textarea.className = 'form-control'
  textarea.rows = 3
  textarea.maxLength = 500
  commentLabel.appendChild(textarea)

  const error = document.createElement('p')
  error.className = 'galaxie-cancel-reason-error'
  error.setAttribute('role', 'alert')
  error.hidden = true
  error.textContent = cfg.required

  select.addEventListener('change', () => {
    if (select.value) error.hidden = true
  })

  el.append(label, commentLabel, error)

  return {
    el,
    reason: () => select.value,
    comment: () => textarea.value,
    validate: () => {
      const ok = '' !== select.value
      error.hidden = ok
      if (!ok) select.focus()
      return ok
    },
  }
}

/** Posts the cancel link's own fields, plus the answer, to the same address. */
function submitCancel(href: string, reason: string, comment: string) {
  const url = new URL(href, window.location.href)
  const form = document.createElement('form')
  form.method = 'post'
  form.action = url.origin + url.pathname

  const fields: Record<string, string> = Object.fromEntries(url.searchParams.entries())
  fields.reason = reason
  fields.comment = comment

  for (const [name, value] of Object.entries(fields)) {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = value
    form.appendChild(input)
  }

  document.body.appendChild(form)
  form.submit()
}

/** The widget's "Cancelamento de pedido em rota" notice (OK only), or the built-in one with the default text. */
function tellPosted(scope: Element | null, cfg: OrderCancellationConfig): Promise<void> {
  return tell(scope, 'cancel_posted', { fallback: cfg.posted })
}

/**
 * Back from a cancellation the server refused because the order had just
 * been posted: the in-transit notice, once, and the mark taken out of the
 * address so a reload does not show it again.
 */
function openPostedNotice() {
  const cfg = getGalaxieConfig().orderCancellation
  const params = new URLSearchParams(window.location.search)
  if (!cfg || !params.has('galaxie_cancel_posted')) return

  params.delete('galaxie_cancel_posted')
  const query = params.toString()
  window.history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash)

  const root = document.querySelector('.galaxie-account-orders, .galaxie-account-order')
  void tellPosted(root, cfg)
}
