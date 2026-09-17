/**
 * The "Galaxie Kit Progress" widget (Widget/KitProgressWidget.php).
 *
 * Printed hidden, with its texts only (the page is cached); drawn here from the
 * kit being built and redrawn on every change anywhere on the page:
 * - no kit: hidden, or the invitation, which opens the kit popup;
 * - a kit: "{kit} · {room}", the fill bar and "Ver kit";
 * - a full box: confetti (once per filling) and "Adicionar kit ao carrinho".
 */

import { fillText } from '@/lib/gift-kit'
import { celebrate, openKit } from '@/globals/kit-builder'
import { kitCall, kitConfig, kitValues, onKit } from '@/globals/kit-store'
import type { KitView } from '@/globals/kit-store'

function textTarget(el: Element | null): Element | null {
  let node = el
  while (node && node.children.length === 1) node = node.children[0]
  return node
}

function paint(root: HTMLElement, kit: KitView | null, previous: KitView | null, first: boolean): void {
  const invite = root.querySelector<HTMLElement>('[data-kit-invite]')
  const state = root.querySelector<HTMLElement>('[data-kit-state]')
  const showInvite = !kit && root.dataset.invite === '1'

  root.hidden = !kit && !showInvite
  if (invite) invite.hidden = !showInvite
  if (state) state.hidden = !kit
  root.classList.toggle('is-full', !!kit?.full)

  if (!kit || !state) return

  const line = state.querySelector('[data-slot="line"]')
  const text = fillText(root.dataset.message ?? '', kitValues(kit))
  if (line && line.textContent !== text) line.textContent = text

  const bar = state.querySelector<HTMLElement>('[data-slot="bar"]')
  if (bar) bar.style.width = `${Math.max(0, Math.min(100, kit.fill))}%`

  state.querySelectorAll<HTMLElement>('[data-kit-when="full"]').forEach((part) => {
    part.hidden = !kit.full
  })

  if (!kit.full || root.dataset.confetti !== '1') return

  const colors = (root.dataset.confettiColors ?? '').split('|').filter(Boolean)
  const count = Number(root.dataset.confettiAmount) || 150
  const origin = root.dataset.confettiOrigin === 'screen' ? null : root

  if (first) {
    if (root.dataset.confettiOnLoad !== '1') return

    // Once per visit and kit: a reload is not a new filling.
    try {
      const key = `galaxie-kit-confetti-${kit.id}`
      if (window.sessionStorage.getItem(key)) return
      window.sessionStorage.setItem(key, '1')
    } catch {
      // Storage refused (private mode): celebrate anyway.
    }

    celebrate(origin, colors, count)
    return
  }

  if (previous && !previous.full && previous.id === kit.id) celebrate(origin, colors, count)
}

export function bootKitProgress(): void {
  if (!kitConfig()) return

  const seen = new WeakSet<HTMLElement>()

  onKit((kit, previous) => {
    document.querySelectorAll<HTMLElement>('[data-galaxie-kit-progress]').forEach((root) => {
      if (root.dataset.sample) return
      const first = !seen.has(root)
      seen.add(root)
      paint(root, kit, first ? null : previous, first)
    })
  })

  document.addEventListener('click', (event) => {
    const trigger = (event.target as Element | null)?.closest<HTMLElement>('[data-galaxie-kit-progress] [data-kit-action]')
    const root = trigger?.closest<HTMLElement>('[data-galaxie-kit-progress]')
    if (!trigger || !root || root.dataset.sample) return

    event.preventDefault()

    switch (trigger.dataset.kitAction) {
      case 'open':
        openKit({ screen: 'welcome' })
        break
      case 'view':
        openKit({ screen: 'summary' })
        break
      case 'to-cart':
        if (trigger.dataset.busy) return
        trigger.dataset.busy = '1'
        void kitCall('to_cart').then((result) => {
          delete trigger.dataset.busy
          const target = textTarget(root.querySelector('[data-slot="line"]'))
          if (!result.ok && target) target.textContent = result.data?.message ?? ''
        })
        break
    }
  })
}
