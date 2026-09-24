import * as React from 'react'
import { Check } from 'lucide-react'

import { cn } from '@/lib/cn'

export type StepStatus = 'active' | 'done' | 'upcoming'

interface StepSectionProps {
  index: number
  title: string
  status: StepStatus
  /** What a finished step collapses to: the e-mail, the name, the address. */
  summary?: React.ReactNode
  /** Reopens a finished step. Absent: the step cannot be reopened (sign-in). */
  onEdit?: () => void
  editLabel: string
  /** Extra pixfort classes from the widget's "Step boxes" controls. */
  className?: string
  children: React.ReactNode
}

/**
 * One step of the checkout, as a box that opens and folds.
 *
 * Replaces the old pill row across the top, which said where the shopper was
 * but not what they had already told us. A finished step now folds into a
 * line of what it holds — "ana@… · Rua Harmonia, 123 · SEDEX" — with a
 * "Change" link, so the whole order can be checked at a glance before paying.
 *
 * The body stays mounted while folded (the `hidden` attribute, not a
 * conditional render): the address and payment steps hold the mounts that
 * WooCommerce's own shipping list and payment block are moved into, and
 * those nodes must survive every step change.
 */
function StepSection({ index, title, status, summary, onEdit, editLabel, className, children }: StepSectionProps) {
  const active = 'active' === status
  const done = 'done' === status

  return (
    <section
      data-status={status}
      aria-current={active ? 'step' : undefined}
      className={cn(
        'gx-co-step rounded-xl border border-border transition-colors',
        active ? 'bg-card p-5 @[480px]:p-6' : 'px-5 py-4 @[480px]:px-6',
        className
      )}
    >
      <header className="flex items-center gap-3">
        <span
          aria-hidden="true"
          className={cn(
            'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
            active && 'bg-primary text-primary-foreground',
            done && 'bg-primary/15 text-foreground',
            'upcoming' === status && 'border border-border text-muted-foreground'
          )}
        >
          {done ? <Check className="size-3.5" strokeWidth={3} /> : index}
        </span>
        <h2
          className={cn(
            'gx-co-step-title flex-1 text-base leading-tight font-semibold',
            'upcoming' === status ? 'text-muted-foreground' : 'text-foreground'
          )}
        >
          {title}
        </h2>
        {done && onEdit && (
          <button
            type="button"
            onClick={onEdit}
            className="text-sm font-medium text-foreground underline decoration-border underline-offset-4 hover:decoration-foreground"
          >
            {editLabel}
          </button>
        )}
      </header>

      {done && summary && <div className="mt-2 pl-10 text-sm text-muted-foreground">{summary}</div>}

      <div hidden={!active} className="mt-5">
        {children}
      </div>
    </section>
  )
}

export { StepSection }
