/**
 * The "Galaxie Account Kit" card (Widget/AccountKitWidget.php).
 *
 * Printed hidden with its texts only (the page is cached), drawn here from the
 * kit being built and redrawn on every change anywhere on the page:
 * - a kit: the line, what is in it, the fill bar, the box, the total and the
 *   buttons that take the shopper back into it;
 * - a kit kept at login: the offer to take it back;
 * - neither: hidden, or the invitation, which opens the kit popup.
 *
 * Kit Progress says the first of those in one line; this is the dashboard's
 * card, so it also owns discarding and recovering, both behind a dialog.
 */

import { ask } from '@/lib/dialog'
import { fillText, setRich } from '@/lib/gift-kit'
import { openKit } from '@/globals/kit-open'
import { currentKit, kitCall, kitConfig, kitPrevious, kitValues, onKit, showKitToast } from '@/globals/kit-store'
import type { KitView } from '@/globals/kit-store'

interface Texts {
  message?: string
  more?: string
  box?: string
  total?: string
  previous?: string
}

function texts(root: HTMLElement): Texts {
  try {
    return JSON.parse(root.dataset.texts ?? '{}') as Texts
  } catch {
    return {}
  }
}

function slot<T extends Element = HTMLElement>(root: ParentNode, name: string): T | null {
  return root.querySelector<T>(`[data-slot="${name}"]`)
}

function setText(el: Element | null, value: string): void {
  if (el) el.textContent = value
}

function items(root: HTMLElement, kit: KitView): void {
  const list = root.querySelector<HTMLElement>('[data-kit-items]')
  const template = root.querySelector<HTMLTemplateElement>('[data-kit-tpl="item"]')

  if (!list || !template) return

  const max = Math.max(1, Number(root.dataset.max) || 4)
  const shown = kit.candles.slice(0, max)
  const rest = kit.candles.slice(max).reduce((sum, line) => sum + line.qty, 0)
  const nodes: HTMLElement[] = []
  const clone = (): HTMLElement | null => (template.content.firstElementChild?.cloneNode(true) as HTMLElement | null) ?? null

  for (const line of shown) {
    const node = clone()
    if (!node) continue

    setText(slot(node, 'name'), line.name)
    setText(slot(node, 'qty'), `× ${line.qty}`)

    const image = slot<HTMLImageElement>(node, 'image')
    if (image) {
      image.hidden = !line.image
      if (line.image) image.src = line.image
    }

    // One the shop no longer sells: shown, because it is why the kit cannot
    // go to the cart, and the popup is where it gets removed.
    node.classList.toggle('is-gone', line.missing)
    nodes.push(node)
  }

  const more = (texts(root).more ?? '').trim()

  if (rest > 0 && more) {
    const node = clone()

    if (node) {
      node.classList.add('is-more')
      slot<HTMLImageElement>(node, 'image')?.remove()
      setText(slot(node, 'name'), fillText(more, { n: String(rest) }))
      setText(slot(node, 'qty'), '')
      nodes.push(node)
    }
  }

  list.replaceChildren(...nodes)
}

function paint(root: HTMLElement, kit: KitView | null): void {
  const t = texts(root)
  const state = root.querySelector<HTMLElement>('[data-kit-state]')
  const invite = root.querySelector<HTMLElement>('[data-kit-invite]')
  const kept = root.querySelector<HTMLElement>('[data-kit-previous]')
  const previous = kitPrevious()
  const offer = !!previous && !!(t.previous ?? '').trim()

  root.hidden = !kit && !offer && root.dataset.invite !== '1'
  if (state) state.hidden = !kit
  if (invite) invite.hidden = !!kit || root.dataset.invite !== '1'
  if (kept) kept.hidden = !offer

  if (kept && offer && previous) {
    setText(slot(kept, 'previous'), fillText(t.previous ?? '', { kit: previous.name, n: String(previous.count) }))
  }

  root.classList.toggle('is-full', !!kit?.full)

  if (!kit || !state) return

  setRich(slot(state, 'line'), fillText(t.message ?? '', kitValues(kit)))

  if (root.dataset.items === '1') items(root, kit)

  const bar = slot<HTMLElement>(state, 'bar')
  const known = typeof kit.fill === 'number'
  root.classList.toggle('is-unknown', !known)
  if (bar) bar.style.width = known ? `${Math.max(0, Math.min(100, kit.fill as number))}%` : '100%'

  const box = state.querySelector<HTMLElement>('[data-kit-box]')
  if (box) {
    const line = (t.box ?? '').trim()
    box.hidden = !line || !kit.box
    if (line && kit.box) box.textContent = fillText(line, { box: kit.box.title || kit.box.name })
  }

  // The label is what the merchant typed; the server sent the money.
  const total = root.querySelector<HTMLElement>('[data-kit-total]')
  if (total) {
    const label = (t.total ?? '').trim()
    total.hidden = label === ''
    if (label !== '') total.textContent = `${label}: ${kit.totalText}`
  }

  state.querySelectorAll<HTMLElement>('[data-kit-when="full"]').forEach((part) => {
    part.hidden = !kit.full
  })
}

async function act(trigger: HTMLElement, action: string): Promise<void> {
  const kit = currentKit()

  switch (action) {
    case 'open':
      openKit({ screen: 'welcome' })
      return

    case 'continue':
      openKit({ screen: kit ? 'summary' : 'welcome' })
      return

    case 'view':
      openKit({ screen: 'summary' })
      return

    case 'to-cart': {
      if (trigger.dataset.busy) return
      trigger.dataset.busy = '1'

      const result = await kitCall('to_cart')
      delete trigger.dataset.busy

      if (!result.ok) showKitToast(result.data?.message ?? '', 'error')
      return
    }

    case 'discard': {
      // The card is the one place a kit is thrown away without opening it, so
      // the question carries its name. The dialog's own sentence wins.
      if (!kit) return

      const yes = await ask(trigger, 'account_kit_discard', {
        fallback: `Descartar o kit ${kit.name}? Isso não pode ser desfeito.`,
        fill: { kit: kit.name },
      })

      if (!yes) return

      const result = await kitCall('discard')
      if (!result.ok) showKitToast(result.data?.message ?? '', 'error')
      return
    }

    case 'restore': {
      // The server decides whether taking the kept kit back throws away an
      // open one; only then is there anything to confirm. Same as the popup.
      let result = await kitCall('restore_previous')

      if (!result.ok && result.data?.reason === 'needs_confirm') {
        const yes = await ask(trigger, 'account_kit_restore', {
          text: result.data.message ?? '',
          fallback: result.data.message ?? '',
        })

        if (!yes) return

        result = await kitCall('restore_previous', { confirm: 1 })
      }

      if (!result.ok) showKitToast(result.data?.message ?? '', 'error')
      return
    }
  }
}

export function bootAccountKit(): void {
  if (!kitConfig()) return

  onKit((kit) => {
    document.querySelectorAll<HTMLElement>('[data-galaxie-account-kit]').forEach((root) => {
      if (root.dataset.sample) return
      paint(root, kit)
    })
  })

  document.addEventListener('click', (event) => {
    const trigger = (event.target as Element | null)?.closest<HTMLElement>('[data-galaxie-account-kit] [data-kit-action]')
    const root = trigger?.closest<HTMLElement>('[data-galaxie-account-kit]')

    if (!trigger || !root || root.dataset.sample) return

    event.preventDefault()
    void act(trigger, trigger.dataset.kitAction ?? '')
  })
}
