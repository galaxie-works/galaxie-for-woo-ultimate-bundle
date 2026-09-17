/**
 * pixfort popups, opened and closed from our code.
 *
 * OPENING. pixfort exposes one global for it, `window.loadPopup({ id })`, the
 * same call its own `.pix-popup-link` click handler makes (dist/front/common.js).
 * It lazy-loads pixfort's dialog code, then shows the `dialog#pix_popup_{id}`
 * already printed in the footer, or fetches the popup over AJAX and inserts it.
 * So the dialog, and anything inside it, may not exist yet when we ask.
 *
 * CLOSING. pixfort exports no close function: the popup manager stays inside
 * its chunk. It does bind one delegated body handler when a popup opens
 * (dist/front/dialog.*.js):
 *
 *     $("body").on("click", "dialog .pix-popup-close,
 *       dialog .pix-dialog-backdrop:not(.is-disabled), .pix-popup .pix-close-popup",
 *       … $("#pix_launcher_"+t).removeClass("opened"), i[0].close(), l.getPopup(t).closePopup() )
 *
 * Clicking an element with `pix-close-popup` inside the dialog is therefore
 * pixfort's own close, whatever the popup's close options — and it also resets
 * the launcher's "opened" state. The close button is a fallback behind it, and
 * the native `dialog.close()` behind that.
 *
 * WATCHING. pixfort adds `displayed` to the dialog once it is shown and removes
 * it when closed, whichever way it was opened (our call, its launcher, a link).
 */

type LoadPopup = (options: { id: string }) => unknown

export function popupAvailable(): boolean {
  return typeof (window as unknown as { loadPopup?: LoadPopup }).loadPopup === 'function'
}

export function popupElement(id: number | string): HTMLElement | null {
  return document.getElementById(`pix_popup_${id}`)
}

export function isPopupOpen(dialog: HTMLElement | null): boolean {
  return !!dialog && (dialog.classList.contains('displayed') || (dialog instanceof HTMLDialogElement && dialog.open))
}

/** Opens the popup; false when pixfort is not on the page. */
export function openPopup(id: number | string): boolean {
  const load = (window as unknown as { loadPopup?: LoadPopup }).loadPopup
  if (typeof load !== 'function' || !id) return false

  const dialog = popupElement(id)
  if (isPopupOpen(dialog)) return true

  // pixfort's loader is async; a rejection leaves the page as it was.
  Promise.resolve(load({ id: String(id) })).catch(() => undefined)
  return true
}

/** pixfort's own close, whatever the popup's close options — see the header. */
export function closePopup(target: HTMLElement | number | string | null): void {
  const dialog = target instanceof HTMLElement ? target.closest<HTMLElement>('dialog, .pix-popup') : target ? popupElement(target) : null
  if (!dialog || !isPopupOpen(dialog)) return

  const hook = document.createElement('span')
  hook.className = 'pix-close-popup'
  hook.hidden = true
  dialog.appendChild(hook)
  hook.click()
  hook.remove()

  if (!isPopupOpen(dialog)) return

  dialog.querySelector<HTMLElement>('.pix-popup-close')?.click()

  if (!isPopupOpen(dialog)) return

  if (dialog instanceof HTMLDialogElement && dialog.open) dialog.close()
  dialog.classList.remove('transitioned', 'displayed')
  document.getElementById(`pix_launcher_${dialog.dataset.id ?? ''}`)?.classList.remove('opened')
}

/**
 * Calls `onOpen` each time the popup becomes visible, however it was opened.
 * The check is one id lookup per page mutation.
 */
export function watchPopup(id: number, onOpen: (dialog: HTMLElement) => void): void {
  let wasOpen = false

  const check = (): void => {
    const dialog = popupElement(id)
    const open = isPopupOpen(dialog)

    if (open && !wasOpen && dialog) onOpen(dialog)
    wasOpen = open
  }

  new MutationObserver(check).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'open'] })
  check()
}
