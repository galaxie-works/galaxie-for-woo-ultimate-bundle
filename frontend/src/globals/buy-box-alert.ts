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
