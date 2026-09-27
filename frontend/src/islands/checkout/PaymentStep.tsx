import * as React from 'react'
import { createPortal } from 'react-dom'

import { cn } from '@/lib/cn'
import { useFieldClass, useUi } from '@/lib/pix'
import type { StripeConfig } from '@/lib/stripe-cards'
import { AddCard } from './AddCard'
import { decoratePayment, type NativeDecor } from './native-checkout'
import type { CheckoutText, CheckoutUi } from './types'

interface PaymentStepProps {
  text: CheckoutText
  paymentMountRef: React.RefObject<HTMLDivElement | null>
  /** Read by the parent to give Stripe's card form the checkout fields' look. */
  stripeProbeRef: React.RefObject<HTMLDivElement | null>
  decor: NativeDecor
  /** Editor preview: WooCommerce's payment block as a sample, in its own markup. */
  sample: string | null
  /**
   * The plugin's card form replaces the gateway's own "new card" (the
   * `gx-co--own-cards` mode): set when cards can be saved here, and always in
   * the editor so the form can be styled.
   */
  ownCards: boolean
  stripeCards: StripeConfig | null
  /** A card was saved: refresh WooCommerce's block and select it. */
  onCardSaved: (token: string) => Promise<void>
  /** "Finalizar" pressed with the card method chosen and no card: explain. */
  onNeedCard: () => void
}

/** The card method's box in WooCommerce's block — where our form goes. */
const cardBox = (root: HTMLElement) => root.querySelector<HTMLElement>('li.payment_method_stripe .payment_box')

const hasTokens = (root: HTMLElement | null) => !!root?.querySelector('.wc-saved-payment-methods .woocommerce-SavedPaymentMethods-token')

/**
 * The native #payment block (payment methods, saved cards, the gateway's
 * fields, place-order button) is moved into `paymentMountRef` by the parent
 * (see native-checkout.ts relocatePayment and decoratePayment). This
 * component provides the mount point, the probe Stripe's appearance is read
 * from, and — in the card method's box — the plugin's own "Adicionar cartão".
 *
 * WooCommerce replaces the whole block on every `updated_checkout`, so the
 * form is portalled into a container this component owns for its whole life,
 * and that container is moved into the card box again after each redraw (an
 * observer on the mount). Portalling into a node found inside WooCommerce's
 * markup instead left the form in a box WooCommerce had already thrown away:
 * mounted, rendered, and nowhere on the page.
 *
 * In the editor the mount holds PHP's sample of the same block, decorated by
 * the same code, so every control of the payment sections has something real
 * to act on; a few lines of script stand in for WooCommerce's own (switching
 * methods).
 */
function PaymentStep({ text, paymentMountRef, stripeProbeRef, decor, sample, ownCards, stripeCards, onCardSaved, onNeedCard }: PaymentStepProps) {
  const { cls } = useUi<CheckoutUi>()
  const field = useFieldClass()
  const sampleRef = React.useRef<HTMLDivElement>(null)
  const [container] = React.useState(() => {
    const div = document.createElement('div')
    div.className = 'gx-co-add-card'
    return div
  })
  const [inBox, setInBox] = React.useState(false)
  const [saved, setSaved] = React.useState(false)

  React.useEffect(() => {
    const el = null !== sample ? sampleRef.current : paymentMountRef.current
    if (!el) return

    // The editor's sample is written here, once per sample, not through
    // `dangerouslySetInnerHTML`: React wrote it again after the decorator had
    // run, and the panel's classes (and the card form) were gone from it.
    if (null !== sample) {
      el.innerHTML = sample
      decoratePayment(el, decor)
    }
    const off = null !== sample ? previewBehaviour(el) : () => {}

    if (!ownCards) return off

    const find = () => {
      // The sample has no WooCommerce redrawing it, but a redraw is a redraw:
      // decorating is idempotent, so the panel's classes simply stay on.
      if (null !== sample) decoratePayment(el, decor)
      const box = cardBox(el)
      if (box && container.parentElement !== box) box.appendChild(container)
      setInBox(!!box)
      setSaved(hasTokens(el))
    }
    find()
    const observer = new MutationObserver(find)
    observer.observe(el, { childList: true, subtree: true })

    // With the gateway's own "new card" gone, a chosen card method and no
    // chosen card would send nothing to pay with: stop there and say so.
    const guard = (event: Event) => {
      const target = event.target as Element | null
      if (!target?.closest('#place_order')) return
      const card = el.querySelector<HTMLInputElement>('li.payment_method_stripe > input:checked')
      const token = el.querySelector<HTMLInputElement>('.wc-saved-payment-methods input:checked:not([value="new"])')
      if (card && !token) {
        event.preventDefault()
        event.stopImmediatePropagation()
        onNeedCard()
      }
    }
    el.addEventListener('click', guard, true)

    return () => {
      off()
      observer.disconnect()
      el.removeEventListener('click', guard, true)
    }
  }, [sample, decor, ownCards, paymentMountRef, onNeedCard, container])

  // The saved card the shopper picked, put back after every redraw.
  // WooCommerce replaces the whole payment block on each `updated_checkout`
  // and the new copy arrives with its own preselection (the account's
  // default), which would silently move the order to another card.
  React.useEffect(() => {
    const el = paymentMountRef.current
    if (null !== sample || !el) return

    const TOKEN = 'input[name^="wc-"][name$="-payment-token"]'
    let chosen: { name: string; value: string } | null = null

    const remember = (event: Event) => {
      const input = event.target as HTMLInputElement | null
      if (input?.matches?.(TOKEN) && input.checked) chosen = { name: input.name, value: input.value }
    }
    const restore = () => {
      if (!chosen) return
      const input = el.querySelector<HTMLInputElement>(`${TOKEN}[name="${CSS.escape(chosen.name)}"][value="${CSS.escape(chosen.value)}"]`)
      if (input && !input.checked && !input.disabled) {
        input.checked = true
        input.dispatchEvent(new Event('change', { bubbles: true }))
      }
    }

    el.addEventListener('change', remember)
    const observer = new MutationObserver(restore)
    observer.observe(el, { childList: true, subtree: true })
    return () => {
      el.removeEventListener('change', remember)
      observer.disconnect()
    }
  }, [sample, paymentMountRef])

  return (
    <div className="gx-co-form">
      {null !== sample ? (
        <div ref={sampleRef} className="galaxie-payment-mount" />
      ) : (
        <div ref={paymentMountRef} className="galaxie-payment-mount" />
      )}

      {ownCards &&
        inBox &&
        createPortal(
          <AddCard
            config={null !== sample ? null : stripeCards}
            text={text}
            hasSaved={saved}
            onSaved={async (token) => {
              await onCardSaved(token)
              setSaved(hasTokens(null !== sample ? sampleRef.current : paymentMountRef.current))
            }}
          />,
          container
        )}

      <div ref={stripeProbeRef} className="gx-co-stripe-probe" hidden aria-hidden="true">
        <span className={cn('gx-co-label', cls.label)}>x</span>
        <input className={field} tabIndex={-1} readOnly />
        <span className="gx-co-stripe-accent" />
      </div>
    </div>
  )
}

/** What WooCommerce's checkout.js does to the live block, for the editor's sample: one method's box open at a time. */
function previewBehaviour(root: HTMLElement): () => void {
  function sync() {
    root.querySelectorAll<HTMLElement>('li.wc_payment_method').forEach((li) => {
      const box = li.querySelector<HTMLElement>('.payment_box')
      const on = !!li.querySelector<HTMLInputElement>(':scope > input:checked')
      if (box) box.style.display = on ? '' : 'none'
    })
  }
  sync()
  root.addEventListener('change', sync)
  return () => root.removeEventListener('change', sync)
}

export { PaymentStep }
