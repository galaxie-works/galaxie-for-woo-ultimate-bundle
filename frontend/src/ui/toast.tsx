/**
 * The single Toast — plain DOM, no React.
 *
 * Call `toast()` from anywhere (module code, the WC-notice interceptor, the
 * kit) and it renders with the shared tokens, semantic color carried only by
 * the icon, sonner-style. It used to be a React component, which made every
 * page that could show a toast download React and ReactDOM (~180 KB) just for
 * a box with a sentence in it; this is a few hundred bytes.
 *
 * Stacking: pixfort's popups sit at z-index 100000000 (the kit popup among
 * them), so the toaster sits above them, near the top of the 32-bit range, but
 * below 2147483647, where Stripe's 3-D Secure frame lives. The toaster itself
 * never takes clicks (pointer-events: none); only the toasts do, so a toast
 * can never block what is under the empty part of its column.
 */

export type ToastVariant = 'success' | 'error' | 'info'

export interface ToastItem {
  id: number
  message: string
  variant: ToastVariant
  duration: number
}

const SVG_OPEN =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"'

/** lucide's circle-check, circle-x, info and x — static markup, no user text. */
const ICON: Record<ToastVariant, string> = {
  success: `${SVG_OPEN} class="mt-0.5 size-4 shrink-0 text-emerald-600"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>`,
  error: `${SVG_OPEN} class="mt-0.5 size-4 shrink-0 text-destructive"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>`,
  info: `${SVG_OPEN} class="mt-0.5 size-4 shrink-0 text-violet-600"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>`,
}
const CLOSE_ICON = `${SVG_OPEN} class="size-4"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>`

const TOASTER_CLASS =
  'galaxie-ui galaxie-toaster pointer-events-none fixed top-4 right-4 z-[2147483000] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2 max-[480px]:top-auto max-[480px]:right-0 max-[480px]:bottom-0 max-[480px]:max-w-none'
const TOAST_CLASS =
  'pointer-events-auto flex items-start gap-3 rounded-lg border border-border bg-popover p-4 text-sm text-popover-foreground shadow-lg animate-in fade-in slide-in-from-top-2'
const CLOSE_CLASS = 'text-muted-foreground transition-colors hover:text-foreground'

let seq = 1
let host: HTMLElement | null = null
const nodes = new Map<number, HTMLElement>()

function toaster(): HTMLElement {
  if (host && host.isConnected) return host

  host = document.createElement('div')
  host.className = TOASTER_CLASS
  host.setAttribute('data-galaxie-toaster', '')
  host.setAttribute('aria-live', 'polite')
  document.body.appendChild(host)

  return host
}

function render(item: ToastItem): HTMLElement {
  const el = document.createElement('div')
  el.className = TOAST_CLASS
  el.setAttribute('role', item.variant === 'error' ? 'alert' : 'status')
  el.insertAdjacentHTML('beforeend', ICON[item.variant])

  const text = document.createElement('span')
  text.className = 'flex-1'
  text.textContent = item.message
  el.appendChild(text)

  const close = document.createElement('button')
  close.type = 'button'
  close.className = CLOSE_CLASS
  close.setAttribute('aria-label', 'Fechar aviso')
  close.innerHTML = CLOSE_ICON
  close.addEventListener('click', () => dismissToast(item.id))
  el.appendChild(close)

  return el
}

export function toast(message: string, opts?: { variant?: ToastVariant; duration?: number }): number {
  const item: ToastItem = {
    id: seq++,
    message,
    variant: opts?.variant ?? 'info',
    duration: opts?.duration ?? 4500,
  }

  const el = render(item)
  nodes.set(item.id, el)
  toaster().appendChild(el)

  if (item.duration > 0) {
    window.setTimeout(() => dismissToast(item.id), item.duration)
  }
  return item.id
}

export function dismissToast(id: number): void {
  const el = nodes.get(id)
  if (!el) return
  nodes.delete(id)
  el.remove()
}
