import * as React from 'react'
import { ChevronDown, ShoppingBag } from 'lucide-react'

import { cn } from '@/lib/cn'
import { onCheckoutUpdated } from './native-checkout'
import type { CheckoutText, OrderSummaryData } from './types'

/** The node PHP prints and WooCommerce's order-review fragments replace (see Checkout\Module::summary_fragment). */
const FRAGMENT_ID = 'galaxie-checkout-summary'

export function readSummaryFragment(): OrderSummaryData | null {
  const el = document.getElementById(FRAGMENT_ID)
  if (!el?.textContent) return null
  try {
    return JSON.parse(el.textContent) as OrderSummaryData
  } catch {
    return null
  }
}

interface OrderSummaryProps {
  initial: OrderSummaryData
  text: CheckoutText
  openOnPhones: boolean
  className?: string
  /** Editor preview: the sample never changes, so nothing to listen for. */
  live: boolean
}

/**
 * The summary column: what is being bought and what it costs.
 *
 * The old stepper had none, so a shopper paid without seeing the order.
 * Amounts are WooCommerce's own strings, re-read after each
 * `updated_checkout` — the moment a carrier, coupon or address reprices the
 * order — so this never shows a total the order is not going to charge.
 *
 * On a phone it sits above the steps as a bar carrying the total, folded
 * unless the widget says otherwise; from the two-column width up, the bar is
 * gone and the body is always shown (see `.gx-co` in index.css).
 */
function OrderSummary({ initial, text, openOnPhones, className, live }: OrderSummaryProps) {
  const [data, setData] = React.useState(initial)
  const [open, setOpen] = React.useState(openOnPhones)
  const bodyId = React.useId()

  React.useEffect(() => {
    if (!live) return
    return onCheckoutUpdated(() => {
      const next = readSummaryFragment()
      if (next) setData(next)
    })
  }, [live])

  return (
    <div className={cn('gx-co-summary-box rounded-xl border border-border bg-card', className)}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={bodyId}
        onClick={() => setOpen((v) => !v)}
        className="gx-co-summary-bar flex w-full items-center gap-3 px-5 py-4 text-left text-sm font-medium text-foreground"
      >
        <ShoppingBag className="size-4 shrink-0" aria-hidden="true" />
        <span className="flex-1">{open ? text.summaryHide : text.summaryShow}</span>
        <ChevronDown className={cn('size-4 shrink-0 transition-transform', open && 'rotate-180')} aria-hidden="true" />
        <span className="gx-co-total text-base font-semibold">{data.total}</span>
      </button>

      <div id={bodyId} hidden={!open} className="gx-co-summary-body p-5 @[480px]:p-6">
        <h2 className="gx-co-summary-title mb-4 text-base font-semibold text-foreground">{text.summaryTitle}</h2>

        <ul className="flex flex-col gap-4">
          {data.items.map((item) => (
            <li key={item.key} className="flex items-center gap-3">
              <span className="relative shrink-0">
                <img
                  src={item.image}
                  alt=""
                  loading="lazy"
                  className="size-14 rounded-md border border-border bg-muted object-cover"
                />
                <span
                  aria-label={`${text.quantity}: ${item.quantity}`}
                  className="absolute -top-2 -right-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-foreground px-1 text-[11px] leading-none font-semibold text-background"
                >
                  {item.quantity}
                </span>
              </span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm leading-snug font-medium text-foreground">{item.name}</span>
                {item.meta && <span className="block text-xs text-muted-foreground">{item.meta}</span>}
              </span>
              <span className="text-sm whitespace-nowrap text-foreground">{item.total}</span>
            </li>
          ))}
        </ul>

        <dl className="mt-5 flex flex-col gap-2 border-t border-border pt-4 text-sm">
          {data.rows.map((row) => (
            <div key={row.id} className="flex items-start justify-between gap-4">
              <dt className="text-muted-foreground">
                {row.label}
                {row.note && <span className="block text-xs">{row.note}</span>}
              </dt>
              <dd className="m-0 text-right whitespace-nowrap text-foreground">{row.value}</dd>
            </div>
          ))}
        </dl>

        <div className="mt-4 flex items-baseline justify-between gap-4 border-t border-border pt-4">
          <span className="text-base font-semibold text-foreground">{text.summaryTotal}</span>
          <span className="gx-co-total text-xl font-semibold text-foreground">{data.total}</span>
        </div>
      </div>
    </div>
  )
}

export { OrderSummary }
