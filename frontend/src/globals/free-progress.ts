/**
 * Behaviour for the "Galaxie Free Shipping Progress" widget: the confetti.
 *
 * The widget's numbers come from the server. Every refresh of the cart brings
 * back a fresh copy of it through cart-fragments.ts. What this file adds is
 * memory: whether each widget had reached free shipping before that refresh.
 * A flip from "not yet" to "reached" is the one moment worth celebrating.
 */

import { jq } from '@/globals/cart-fragments'
import { burst } from '@/globals/confetti'

const reached = new Map<string, boolean>()

function celebrate(el: HTMLElement): void {
  const colors = (el.dataset.confettiColors ?? '').split('|').filter(Boolean)
  // A widget set to hide once reached has no box left to burst from.
  const fromWidget = el.dataset.confettiOrigin !== 'screen' && !el.hidden

  burst({
    origin: fromWidget ? el.getBoundingClientRect() : null,
    count: Number(el.dataset.confettiAmount) || 150,
    colors,
  })
}

function check(onLoad: boolean): void {
  let fired = false

  document.querySelectorAll<HTMLElement>('.galaxie-free-progress[data-galaxie-fragment]').forEach((el) => {
    const key = el.dataset.galaxieFragment ?? ''
    const now = el.dataset.achieved === '1'
    const before = reached.get(key)

    reached.set(key, now)

    // One burst per change, even with the widget on the page twice.
    if (!now || fired || el.dataset.confetti !== '1') return

    if (onLoad) {
      if (el.dataset.confettiOnLoad !== '1') return

      // Once per visit: a reload is not a new achievement.
      try {
        const storageKey = `galaxie-confetti-${key}`
        if (window.sessionStorage.getItem(storageKey)) return
        window.sessionStorage.setItem(storageKey, '1')
      } catch {
        // Storage refused (private mode): celebrate anyway.
      }
    } else if (before !== false) {
      return
    }

    celebrate(el)
    fired = true
  })
}

export function bootFreeProgress(): void {
  check(true)
  jq()?.(document.body).on('galaxie_fragments_updated', () => check(false))
}
