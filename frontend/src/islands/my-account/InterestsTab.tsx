import * as React from 'react'

import { post } from '@/lib/wp'
import { cn } from '@/lib/cn'
import type { InterestOption } from './types'

interface AjaxEndpoint {
  ajaxUrl: string
  nonce: string
}

interface InterestsTabProps {
  options: InterestOption[]
  initialSelected: number[]
  cfg?: AjaxEndpoint
  genericError: string
}

const PREFERS_REDUCED_MOTION =
  typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

function spawnParticles(pill: HTMLElement) {
  if (PREFERS_REDUCED_MOTION) return
  const rect = pill.getBoundingClientRect()
  for (let i = 0; i < 7; i++) {
    const particle = document.createElement('span')
    particle.className = 'galaxie-particle'
    particle.textContent = '✦'
    const angle = Math.random() * Math.PI * 2
    const distance = 18 + Math.random() * 22
    particle.style.setProperty('--gx-dx', `${Math.cos(angle) * distance}px`)
    particle.style.setProperty('--gx-dy', `${Math.sin(angle) * distance - 10}px`)
    particle.style.setProperty('--gx-duration', `${500 + Math.random() * 300}ms`)
    particle.style.left = `${rect.width / 2}px`
    particle.style.top = `${rect.height / 2}px`
    particle.style.fontSize = '10px'
    pill.appendChild(particle)
    particle.addEventListener('animationend', () => particle.remove())
  }
}

function InterestsTab({ options, initialSelected, cfg, genericError }: InterestsTabProps) {
  const [selected, setSelected] = React.useState<Set<number>>(() => new Set(initialSelected))
  const [pending, setPending] = React.useState<Set<number>>(new Set())
  const [error, setError] = React.useState<string | null>(null)

  const sorted = React.useMemo(() => [...options].sort((a, b) => a.label.localeCompare(b.label)), [options])

  async function toggle(tagId: number, el: HTMLElement) {
    if (!cfg || pending.has(tagId)) return

    const willSelect = !selected.has(tagId)
    setSelected((prev) => {
      const next = new Set(prev)
      willSelect ? next.add(tagId) : next.delete(tagId)
      return next
    })
    if (willSelect) spawnParticles(el)

    setPending((prev) => new Set(prev).add(tagId))
    setError(null)

    const res = await post(cfg.ajaxUrl, 'galaxie_myaccount_toggle_interest', cfg.nonce, {
      tag_id: tagId,
      selected: willSelect ? '1' : '',
    })

    setPending((prev) => {
      const next = new Set(prev)
      next.delete(tagId)
      return next
    })

    if (!res.success) {
      // Revert the optimistic update.
      setSelected((prev) => {
        const next = new Set(prev)
        willSelect ? next.delete(tagId) : next.add(tagId)
        return next
      })
      setError(res.data?.message ?? genericError)
    }
  }

  return (
    <div className="flex max-w-lg flex-col gap-4">
      <p className="text-sm text-muted-foreground">Pick what you're into — we'll use it to send you relevant offers.</p>

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        {sorted.map((option) => {
          const isSelected = selected.has(option.tagId)
          return (
            <button
              key={option.tagId}
              type="button"
              disabled={pending.has(option.tagId)}
              onClick={(e) => toggle(option.tagId, e.currentTarget)}
              className={cn(
                'relative inline-flex items-center gap-1.5 overflow-visible rounded-full border px-3 py-1.5 text-sm transition-colors disabled:opacity-60',
                isSelected
                  ? 'border-primary bg-primary text-primary-foreground'
                  : 'border-border bg-background text-foreground hover:bg-accent'
              )}
            >
              {option.iconUrl ? (
                <img src={option.iconUrl} alt="" className="size-4 rounded-full object-cover" />
              ) : option.icon ? (
                <span aria-hidden="true">{option.icon}</span>
              ) : null}
              {option.label}
            </button>
          )
        })}
      </div>
    </div>
  )
}

export { InterestsTab }
