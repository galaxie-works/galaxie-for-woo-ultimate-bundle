import * as React from 'react'
import { ChevronDown, ShoppingBag } from 'lucide-react'

import { cn } from '@/lib/cn'
import { onCheckoutUpdated } from './native-checkout'
import { useUi } from '@/lib/pix'
import type { CheckoutText, CheckoutUi, OrderSummaryData } from './types'

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
  /** Editor preview: the sample never changes, so nothing to listen for. */
  live: boolean
}

/**
 * The summary column: what is being bought and what it costs.
 *
 * Amounts are WooCommerce's own strings, re-read after each
 * `updated_checkout` — the moment a carrier, coupon or address reprices the
 * order — so this never shows a total the order is not going to charge.
 *
 * Styled like the rest of the plugin: the box is a pixfort surface, the photo
 * the shared thumbnail set, the quantity a badge pill, each line of text its
 * own "Order summary:" text set on the widget's Style tab.
 *
 * On a phone it sits above the steps as a bar carrying the total, folded
 * unless the widget says otherwise; from the two-column width up, the bar is
 * gone and the body is always shown (see `.gx-co` in index.css).
 */
function OrderSummary({ initial, text, openOnPhones, live }: OrderSummaryProps) {
  const { cls } = useUi<CheckoutUi>()
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
    <div className={cn('gx-co-summary-box', cls.summaryBox)}>
      <button type="button" aria-expanded={open} aria-controls={bodyId} onClick={() => setOpen((v) => !v)} className="gx-co-summary-bar">
        <ShoppingBag className="size-4 shrink-0" aria-hidden="true" />
        <span className={cn('gx-co-body flex-1', cls.body)}>{open ? text.summaryHide : text.summaryShow}</span>
        <ChevronDown className={cn('size-4 shrink-0 transition-transform', open && 'rotate-180')} aria-hidden="true" />
        <span className={cn('gx-co-total', cls.totalValue)}>{data.total}</span>
      </button>

      <div id={bodyId} hidden={!open} className="gx-co-summary-body">
        <div role="heading" aria-level={2} className={cn('gx-co-summary-title', cls.sumTitle)}>
          {text.summaryTitle}
        </div>

        <ul className="gx-co-items">
          {data.items.map((item) => (
            <li key={item.key} className="gx-co-item">
              <span className="gx-co-thumb-wrap">
                <img src={item.image} alt="" loading="lazy" className={cn('gx-co-thumb', cls.thumb)} />
                <span aria-label={text.quantity + ': ' + item.quantity} className={cn('gx-co-qty', cls.qty)}>
                  {item.quantity}
                </span>
              </span>
              <span className="gx-co-item-text">
                <span className={cn('gx-co-item-name', cls.itemName)}>{item.name}</span>
                {item.meta && <span className={cn('gx-co-item-meta', cls.itemMeta)}>{item.meta}</span>}
              </span>
              <span className={cn('gx-co-item-price', cls.itemPrice)}>{item.total}</span>
            </li>
          ))}
        </ul>

        <div className="gx-co-rows gx-co-divider">
          {data.rows.map((row) => (
            <div key={row.id} className="gx-co-row">
              <span className={cn('gx-co-row-label', cls.rowLabel)}>
                {row.label}
                {row.note && <span className={cn('gx-co-row-note gx-co-small', cls.small)}>{row.note}</span>}
              </span>
              <span className={cn('gx-co-row-value', cls.rowValue)}>{row.value}</span>
            </div>
          ))}
        </div>

        <div className="gx-co-row gx-co-row--total gx-co-divider">
          <span className={cn('gx-co-total-label', cls.totalLabel)}>{text.summaryTotal}</span>
          <span className={cn('gx-co-total', cls.totalValue)}>{data.total}</span>
        </div>
      </div>
    </div>
  )
}

export { OrderSummary }
