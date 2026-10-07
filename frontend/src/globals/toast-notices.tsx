import { createRoot } from 'react-dom/client'

import { Toaster, toast, type ToastVariant } from '@/ui/toast'

/**
 * Global behavior (not an island): turns WooCommerce's notices into toasts,
 * removing the original so the theme never shows its own notice. Ported from
 * the v1 toast-notices.js. Boots only when the PHP side sets the
 * `toastNotices` flag — which it leaves off on the order pages (order-pay,
 * order-received, view-order), where a notice is the whole page.
 *
 * Only notices WooCommerce QUEUED are taken: the ones it prints into a
 * `.woocommerce-notices-wrapper` (or a checkout `.woocommerce-NoticeGroup`),
 * and notice lists injected after load (AJAX add-to-cart, checkout errors).
 * It used to take every `.woocommerce-info/-message/-error` on the page, and
 * those classes are also WooCommerce's styling for things that are not
 * messages at all: the "Tem um cupom? Clique aqui" toggle (whose link then
 * vanished with it), the empty-cart line, a gateway's own error inside
 * `#payment`, the thank-you box — and on order-pay the one sentence explaining
 * why there was nothing to pay, gone after 4.5 s from an otherwise empty page.
 * A notice carrying a link of its own (other than WooCommerce's "Ver carrinho"
 * button) stays where it is too: a toast has no room for it.
 */

const VARIANT_BY_CLASS: Record<string, ToastVariant> = {
  'woocommerce-error': 'error',
  'woocommerce-message': 'success',
  'woocommerce-info': 'info',
}

const NOTICE_SELECTOR = '.woocommerce-error, .woocommerce-message, .woocommerce-info'

/** Where WooCommerce prints the notices it queued. */
const QUEUE_SELECTOR = '.woocommerce-notices-wrapper, .woocommerce-NoticeGroup'

/** Never taken, wherever they sit: WooCommerce's notice look on things that are not queued notices. */
const KEEP_SELECTOR = [
  '#payment',
  '.woocommerce-form-coupon-toggle',
  '.cart-empty',
  '.woocommerce-order',
  '.galaxie-checkout--endpoint',
  '.woocommerce-MyAccount-content .woocommerce-order-details',
].join(', ')

/** Body classes of the order pages, should the PHP flag ever be cached onto one. */
const ORDER_PAGES = ['woocommerce-order-pay', 'woocommerce-order-received', 'woocommerce-view-order']

/** The links a toast may drop: WooCommerce's "Ver carrinho" / "Continuar comprando" buttons. */
const DROPPABLE_LINKS = 'a.button, a.wc-forward'

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

/** A toast from module code (the kit's "Adicionada ao kit…"), with the same look. */
export function showToast(message: string, variant: ToastVariant = 'success'): void {
  if (!message) return
  ensureToaster()
  toast(message, { variant })
}

/**
 * The words of one message. WooCommerce puts its action link inside the
 * notice ("…added to your cart. View cart"), which read as one run-on
 * sentence once flattened to text, and a toast has no room for a link.
 */
function messageText(el: Element): string {
  const copy = el.cloneNode(true) as Element
  copy.querySelectorAll(DROPPABLE_LINKS).forEach((a) => a.remove())
  return (copy.textContent ?? '').replace(/\s+/g, ' ').trim()
}

/** A link the shopper needs ("Desfazer?", "Clique aqui", a login link) keeps the notice on the page. */
function hasOwnLink(el: Element): boolean {
  return Array.from(el.querySelectorAll('a')).some((a) => !a.matches(DROPPABLE_LINKS))
}

function onOrderPage(): boolean {
  return ORDER_PAGES.some((cls) => document.body.classList.contains(cls))
}

/**
 * May this notice become a toast? `injected` is true for a node added after
 * load that is itself a notice (or a notice group): WooCommerce's own AJAX
 * paths drop those in wherever their form is.
 */
function takeable(el: Element, injected: boolean): boolean {
  if (onOrderPage() || el.closest(KEEP_SELECTOR)) return false
  if (!injected && !el.closest(QUEUE_SELECTOR)) return false
  // Kept for its link only where it can be seen: the leftover-notices queue
  // and the checkout's form are hidden, and a toast without the link beats
  // a message nobody sees.
  return !(hasOwnLink(el) && el.getClientRects().length > 0)
}

function convert(el: Element, injected = false): void {
  const cls = Object.keys(VARIANT_BY_CLASS).find((c) => el.classList.contains(c))
  if (!cls || !takeable(el, injected)) return
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

function scan(root: ParentNode, injected = false): void {
  root.querySelectorAll(NOTICE_SELECTOR).forEach((el) => convert(el, injected))
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
          convert(node, true)
        } else {
          // A whole notice group injected (checkout errors) counts as fresh;
          // any other redrawn region only gives up what sits in a queue.
          scan(node, node.matches(QUEUE_SELECTOR))
        }
      })
    })
  })
  observer.observe(document.body, { childList: true, subtree: true })
}
