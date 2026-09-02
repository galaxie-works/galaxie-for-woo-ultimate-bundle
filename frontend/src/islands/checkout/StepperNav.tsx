import { Check } from 'lucide-react'

import { cn } from '@/lib/cn'
import type { StepId } from './types'

const STEPS: { id: StepId; label: string }[] = [
  { id: 'entry', label: 'Sign in' },
  { id: 'profile', label: 'Your details' },
  { id: 'address', label: 'Delivery' },
  { id: 'payment', label: 'Payment' },
]

/** Purely presentational — no click-to-jump; each step has its own "Back" affordance instead. */
function StepperNav({ step }: { step: StepId }) {
  const activeIndex = STEPS.findIndex((s) => s.id === step)

  return (
    <ol className="flex items-center gap-2 text-sm">
      {STEPS.map((s, i) => {
        const isActive = i === activeIndex
        const isDone = i < activeIndex
        return (
          <li key={s.id} className="flex items-center gap-2">
            {i > 0 && <span className="h-px w-6 bg-border" aria-hidden="true" />}
            <span
              className={cn(
                'flex items-center gap-1.5 rounded-full px-3 py-1 font-medium transition-colors',
                isActive && 'bg-primary text-primary-foreground',
                isDone && 'text-muted-foreground',
                !isActive && !isDone && 'text-muted-foreground/60'
              )}
            >
              {isDone ? <Check className="size-3.5" /> : <span>{i + 1}</span>}
              {s.label}
            </span>
          </li>
        )
      })}
    </ol>
  )
}

export { StepperNav }
