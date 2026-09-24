import * as React from 'react'
import { Check } from 'lucide-react'

import { cn } from '@/lib/cn'
import { PixButton, useUi } from './pix'

export type StepStatus = 'active' | 'done' | 'upcoming'

interface StepSectionProps {
  index: number
  title: string
  status: StepStatus
  /** What a finished step collapses to: the e-mail, the name, the address. */
  summary?: React.ReactNode
  /** Reopens a finished step. Absent: the step cannot be reopened (sign-in). */
  onEdit?: () => void
  children: React.ReactNode
}

/**
 * One step of the checkout, as a box that opens and folds.
 *
 * A finished step folds into a line of what it holds — "ana@… · Rua
 * Harmonia, 123 · SEDEX" — with a "Change" link, so the whole order can be
 * checked at a glance before paying.
 *
 * Its parts are the Kit Builder's: the dot is the "Step indicator" pill
 * (`is-current` / `is-done` states on the box, as there), the box is the
 * "Step box" surface, the title the "Texts: step titles" set — all from the
 * widget's Style tab. The title is a `role="heading"` div rather than an
 * <h2>: pixfort sizes every h2 at 48px, and that would beat a size left at
 * Default in the panel.
 *
 * The body stays mounted while folded (the `hidden` attribute, not a
 * conditional render): the address and payment steps hold the mounts that
 * WooCommerce's own shipping list and payment block are moved into, and
 * those nodes must survive every step change.
 */
function StepSection({ index, title, status, summary, onEdit, children }: StepSectionProps) {
  const { cls, buttons } = useUi()
  const active = 'active' === status
  const done = 'done' === status

  return (
    <section
      aria-current={active ? 'step' : undefined}
      className={cn('gx-co-step', active && 'is-current', done && 'is-done', 'upcoming' === status && 'is-upcoming', cls.stepBox)}
    >
      <header className="gx-co-step-head">
        <span aria-hidden="true" className={cn('gx-co-step-dot', cls.stepDot)}>
          {done ? <Check className="size-3.5" strokeWidth={3} /> : index}
        </span>
        <div role="heading" aria-level={2} className={cn('gx-co-step-title', cls.stepTitle)}>
          {title}
        </div>
        {done && onEdit && <PixButton button={buttons.edit} onClick={onEdit} />}
      </header>

      {done && summary && <div className={cn('gx-co-step-summary gx-co-small', cls.small)}>{summary}</div>}

      <div hidden={!active} className="gx-co-step-body">
        {children}
      </div>
    </section>
  )
}

export { StepSection }
