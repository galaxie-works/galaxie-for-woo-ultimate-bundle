/**
 * The Buy Box's Alert block, shared by everything that needs to say something
 * about the same purchase.
 *
 * The widget renders one pixfort Alert per configured message — each with its
 * own type, its own icon and, where the message declares one, its own link —
 * all hidden. Showing a message is showing its element. Nothing here paints,
 * recolours or rewrites: whatever pixfort emitted for that message is what the
 * shopper sees, which is the only way the icon and the colour can be right for
 * four different sentences.
 *
 * It lives apart from `buy-box.ts` because the quantity-discount rows raise the
 * same "choose an option first" as the Buy Box does, and two copies of this
 * logic drifted apart the moment the markup changed.
 */

/** Why a Buy Box form cannot buy yet: nothing (or not everything) chosen, or a combination the shop does not sell. */
export type ChoiceProblem = 'select' | 'unavailable'

/**
 * What stands between a form and something that can be bought, or null.
 *
 * A form with no attribute selects is a simple product and never lacks a
 * choice. Otherwise WooCommerce's own variation script is the judge: it writes
 * the matched variation into `variation_id`, and nothing else counts.
 */
export function missingChoice(form: HTMLFormElement): ChoiceProblem | null {
  const selects = Array.from(form.querySelectorAll<HTMLSelectElement>('.variations select'))
  if (!selects.length) return null

  if (Number(form.querySelector<HTMLInputElement>('input[name="variation_id"]')?.value) > 0) return null

  // Every attribute chosen and still no match is a combination the shop does
  // not sell; a blank one is just an unfinished choice. Different sentence.
  return selects.every((select) => select.value !== '') ? 'unavailable' : 'select'
}

/**
 * The Buy Box's answer to a click with no valid choice: its Alert block says
 * why, and the click is stopped. Returns true only when it was said.
 *
 * With no Alert block, or that message left empty, there is nowhere to say it
 * and this returns false — Add to Cart then lets the native submit through for
 * WooCommerce to refuse its own way, and a control with no submit behind it
 * uses {@see wooChoiceNotice()} instead. A valid choice hides a stale alert.
 */
export function blockedByChoice(form: HTMLFormElement, alert: AlertController | null = findAlert()): boolean {
  const key = missingChoice(form)

  if (!key) {
    alert?.hide()
    return false
  }

  return alert?.show(key) ?? false
}

/**
 * WooCommerce's own notice for a missing choice, for a click that has no form
 * submit behind it to let WooCommerce refuse — "Configurar presente", say.
 *
 * The same sentence `wc-add-to-cart-variation.js` alerts when its add to cart
 * button is clicked with nothing chosen, after bringing the swatches into view
 * so the shopper sees where the choice is made.
 */
export function wooChoiceNotice(form: HTMLFormElement): void {
  const key = missingChoice(form)
  if (!key) return

  const picker = form.querySelector<HTMLElement>('.galaxie-variation-picker')
  picker?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  picker?.querySelector<HTMLButtonElement>('.galaxie-swatch-option:not(:disabled)')?.focus({ preventScroll: true })

  const params = (window as unknown as {
    wc_add_to_cart_variation_params?: { i18n_make_a_selection_text?: string; i18n_unavailable_text?: string }
  }).wc_add_to_cart_variation_params

  const text =
    key === 'unavailable'
      ? params?.i18n_unavailable_text || 'Essa combinação não está disponível. Escolha outra.'
      : params?.i18n_make_a_selection_text || 'Escolha uma opção antes de continuar.'

  window.alert(text)
}

export interface AlertController {
  /** Shows one message. `override` replaces its text for this showing only. */
  show(key: string, override?: string): boolean
  hide(): void
  has(key: string): boolean
}

/**
 * @param scope Where to look. Defaults to the document, because the alert block
 *   can be dragged anywhere in the widget and is not necessarily a descendant
 *   of whatever is asking for it.
 */
export function findAlert(scope: ParentNode = document): AlertController | null {
  const holder = scope.querySelector<HTMLElement>('.galaxie-buybox-alert')
  if (!holder) return null

  const messages = new Map<string, HTMLElement>()
  holder.querySelectorAll<HTMLElement>('[data-galaxie-alert]').forEach((el) => {
    const key = el.dataset.galaxieAlert
    if (key) messages.set(key, el)
  })

  if (!messages.size) return null

  // The configured wording, kept so a one-off override — a message the server
  // sent back — does not become the message from then on.
  const originals = new Map<string, string>()
  messages.forEach((el, key) => {
    originals.set(key, el.querySelector<HTMLElement>('.pix-alert-title')?.innerHTML ?? '')
  })

  const hide = () => holder.classList.remove('is-visible')

  // pixfort's close button is Bootstrap's `data-dismiss="alert"`, which removes
  // the node from the document. These have to survive to be shown again on the
  // next attempt, so the dismissal is caught on the way down and turned into a
  // hide.
  holder.addEventListener(
    'click',
    (event) => {
      if (!(event.target as HTMLElement).closest('[data-dismiss="alert"]')) return
      event.preventDefault()
      event.stopPropagation()
      hide()
    },
    true,
  )

  return {
    hide,
    has: (key) => messages.has(key),
    show(key, override) {
      const wanted = messages.get(key)
      if (!wanted) return false

      messages.forEach((el, k) => {
        el.classList.toggle('is-current', el === wanted)

        const title = el.querySelector<HTMLElement>('.pix-alert-title')
        if (!title) return

        title.innerHTML = el === wanted && override ? override : (originals.get(k) ?? title.innerHTML)
      })

      holder.classList.add('is-visible')
      return true
    },
  }
}
