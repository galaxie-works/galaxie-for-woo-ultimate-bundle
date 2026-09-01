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

function ensureToaster(): void {
  if (toasterMounted) return
  const host = document.createElement('div')
  host.setAttribute('data-galaxie-toaster', '')
  document.body.appendChild(host)
  createRoot(host).render(<Toaster />)
  toasterMounted = true
}

function convert(el: Element): void {
  const cls = Object.keys(VARIANT_BY_CLASS).find((c) => el.classList.contains(c))
  if (!cls) return
  const text = (el.textContent ?? '').trim()
  if (text) {
    ensureToaster()
    toast(text, { variant: VARIANT_BY_CLASS[cls] })
  }
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
