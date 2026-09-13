/**
 * Confirmations and notices in the page's own dialog, never the browser's.
 *
 * A widget prints `<dialog class="galaxie-dialog" data-dialog="{name}">` with
 * its wording, buttons and style from Elementor (PHP Support\Dialog). This
 * opens that one as a modal. A widget without one — or a page with no widget
 * at all — gets a plain dialog built here, in the site's font and buttons, so
 * nothing ever falls through to `window.confirm()` or `window.alert()`.
 */

interface Opening {
  /** Replaces the configured message for this opening only. */
  text?: string
  /** One of the widget's extra messages, by key. */
  key?: string
  /** What the built-in dialog says when the widget has none. */
  fallback: string
  cancel: boolean
}

let generic: HTMLDialogElement | null = null

function find(scope: Element | null, name: string): HTMLDialogElement | null {
  // The dialog sits inside its widget, not necessarily around what was clicked.
  const host = scope?.closest('.elementor-widget') ?? scope
  return host?.querySelector<HTMLDialogElement>(`dialog.galaxie-dialog[data-dialog="${name}"]`) ?? null
}

function builtIn(): HTMLDialogElement {
  if (generic?.isConnected) return generic

  generic = document.createElement('dialog')
  generic.className = 'galaxie-dialog is-generic'
  generic.innerHTML =
    '<div class="galaxie-dialog-text"><span class="galaxie-dialog-message"></span></div>' +
    '<div class="galaxie-dialog-actions">' +
    '<button type="button" class="galaxie-dialog-button galaxie-dialog-cancel"><span class="btn btn-link btn-sm">Cancelar</span></button>' +
    '<button type="button" class="galaxie-dialog-button galaxie-dialog-confirm"><span class="btn btn-primary btn-sm">OK</span></button>' +
    '</div>'
  document.body.appendChild(generic)

  return generic
}

function extraText(dialog: HTMLDialogElement, key: string | undefined): string {
  if (!key) return ''

  try {
    const texts = JSON.parse(dialog.dataset.texts ?? '{}') as Record<string, string>
    return texts[key] ?? ''
  } catch {
    return ''
  }
}

function open(scope: Element | null, name: string, options: Opening): Promise<boolean> {
  const own = find(scope, name)
  const dialog = own ?? builtIn()

  // A second click while it is up is not a second question.
  if (dialog.open && !dialog.classList.contains('is-preview')) return Promise.resolve(false)
  if (dialog.classList.contains('is-preview')) {
    dialog.classList.remove('is-preview')
    dialog.close()
  }

  const message = dialog.querySelector<HTMLElement>('.galaxie-dialog-message')
  if (message) {
    message.dataset.original ??= message.textContent ?? ''
    message.textContent = options.text || extraText(dialog, options.key) || message.dataset.original || options.fallback
  }

  const cancel = dialog.querySelector<HTMLElement>('.galaxie-dialog-cancel')
  if (cancel) cancel.hidden = !options.cancel

  return new Promise((resolve) => {
    const onClick = (event: MouseEvent) => {
      const target = event.target as Element

      if (target.closest('.galaxie-dialog-confirm')) dialog.close('yes')
      else if (target.closest('.galaxie-dialog-cancel') || target === dialog) dialog.close('')
    }

    const onClose = () => {
      dialog.removeEventListener('click', onClick)
      resolve(dialog.returnValue === 'yes')
    }

    dialog.returnValue = ''
    dialog.addEventListener('click', onClick)
    dialog.addEventListener('close', onClose, { once: true })
    dialog.showModal()
  })
}

/** Resolves true only when the confirm button is pressed; Escape, the overlay and cancel all say no. */
export function ask(scope: Element | null, name: string, fallback: string): Promise<boolean> {
  return open(scope, name, { fallback, cancel: true })
}

/** A notice with a single button. */
export function tell(scope: Element | null, name: string, message: { text?: string; key?: string; fallback: string }): Promise<void> {
  return open(scope, name, { ...message, cancel: false }).then(() => undefined)
}
