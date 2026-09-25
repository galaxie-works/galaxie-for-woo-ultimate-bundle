import { createRoot } from 'react-dom/client'

import { Toaster, toast, type ToastVariant } from '@/ui/toast'

/**
 * Global behavior (not an island): intercepts WooCommerce's native notice
 * blocks anywhere on the front-end and re-renders them as toasts, removing the
 * original so the theme never shows its own notice. Ported from the v1
 * toast-notices.js. Boots only when the PHP side sets the `toastNotices` flag.
 */

const VARIANT_BY_CLASS: Record<string, ToastVariant> = {
  'woocommerce-error': 'error',
  'woocommerce-message': 'success',
  'woocommerce-info': 'info',
}

const NOTICE_SELECTOR = '.woocommerce-error, .woocommerce-message, .woocommerce-info'

let toasterMounted = false

/** Messages toasted a moment ago: WooCommerce queues one per recalculation. */
const recent = new Set<string>()

function ensureToaster(): void {
  if (toasterMounted) return
  const host = document.createElement('div')
  host.setAttribute('data-galaxie-toaster', '')
  document.body.appendChild(host)
  createRoot(host).render(<Toaster />)
  toasterMounted = true
}

/**
 * The words of one message. WooCommerce puts its action link inside the
 * notice ("…added to your cart. View cart"), which read as one run-on
 * sentence once flattened to text, and a toast has no room for a link.
 */
function messageText(el: Element): string {
  const copy = el.cloneNode(true) as Element
  copy.querySelectorAll('a.button, a.wc-forward, .restore-item').forEach((a) => a.remove())
  return (copy.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function convert(el: Element): void {
  const cls = Object.keys(VARIANT_BY_CLASS).find((c) => el.classList.contains(c))
  if (!cls) return
  // An error list (`ul.woocommerce-error`) carries one message per item:
  // one toast each, not every message glued into a single line. The same
  // message twice in a row (one notice per recalculation) shows once.
  const items = el.matches('ul') ? Array.from(el.querySelectorAll(':scope > li')) : [el]
  const texts = items.map(messageText).filter((text) => text && !recent.has(text))
  texts.forEach((text) => {
    recent.add(text)
    window.setTimeout(() => recent.delete(text), 1500)
    ensureToaster()
    toast(text, { variant: VARIANT_BY_CLASS[cls] })
  })
  el.remove()
}

function scan(root: ParentNode): void {
  root.querySelectorAll(NOTICE_SELECTOR).forEach(convert)
}

export function bootToastNotices(): void {
  const run = () => scan(document)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run)
  } else {
    run()
  }

  // WooCommerce injects notices dynamically (AJAX add-to-cart, checkout, etc.).
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      m.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) return
        if (node.matches(NOTICE_SELECTOR)) {
          convert(node)
        } else {
          scan(node)
        }
      })
    })
  })
  observer.observe(document.body, { childList: true, subtree: true })
}
