/**
 * The Galaxie Account Wishlist screen: switching lists, creating, renaming,
 * deleting, making one the default, sharing by link and accepting gifts, and
 * removing a product.
 *
 * Every change is posted to the Wishlist module's AJAX actions and the widget
 * is then drawn again from the server — the list shown travels in `?lista=`,
 * so the redraw, a reload and the back button all agree on it.
 */

import { ask } from '@/lib/dialog'

interface WishlistConfig {
  ajaxUrl: string
  nonce: string
}

interface Answer {
  success: boolean
  data?: { message?: string; login?: string; list?: { id: string } }
}

const GENERIC_ERROR = 'Algo deu errado. Tente de novo.'

async function post(config: WishlistConfig, action: string, fields: Record<string, string>): Promise<Answer> {
  const body = new URLSearchParams({ action: `galaxie_wishlist_${action}`, nonce: config.nonce, ...fields })
  const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body })

  return (await response.json()) as Answer
}

function message(root: HTMLElement, text: string, ok: boolean): void {
  const line = root.querySelector<HTMLElement>(':scope > .galaxie-account-message')
  if (!line) return

  line.className = `galaxie-account-message ${ok ? 'is-success' : 'is-error'} ${root.dataset.msgClass ?? ''}`.trim()
  line.textContent = text
  line.hidden = text === ''
}

interface ElementorEditor {
  getContainer?: (id: string) => { model?: { renderRemoteServer?: () => void } } | undefined
}

/** Inside Elementor's preview frame, where the page holds unsaved settings. */
function inEditor(): boolean {
  return document.body.classList.contains('elementor-editor-active')
}

/**
 * The widget as the server draws it for `url`, swapped in place of the old one.
 * In the editor the published page would bring back saved settings, so
 * Elementor draws the widget again from the ones being edited.
 */
async function redraw(root: HTMLElement, url = window.location.href): Promise<HTMLElement | null> {
  if (inEditor()) {
    const id = root.closest<HTMLElement>('.elementor-element[data-id]')?.dataset.id ?? ''
    const editor = (window.parent as Window & { elementor?: ElementorEditor }).elementor
    editor?.getContainer?.(id)?.model?.renderRemoteServer?.()
    return null
  }

  const all = [...document.querySelectorAll<HTMLElement>('.galaxie-account-wishlist')]
  const index = all.indexOf(root)
  const html = await (await fetch(url, { credentials: 'same-origin' })).text()
  const fresh = new DOMParser().parseFromString(html, 'text/html').querySelectorAll<HTMLElement>('.galaxie-account-wishlist')[index]

  if (!fresh) return null

  const node = document.importNode(fresh, true)
  root.replaceWith(node)
  return node
}

function showList(url: string): void {
  window.history.pushState({ galaxieWishlist: true }, '', url)
}

function withList(listId: string): string {
  const url = new URL(window.location.href)
  if (listId) url.searchParams.set('lista', listId)
  else url.searchParams.delete('lista')
  return url.toString()
}

async function act(root: HTMLElement, config: WishlistConfig, action: string, fields: Record<string, string>, after?: (answer: Answer) => string | void): Promise<void> {
  root.classList.add('is-busy')

  try {
    const answer = await post(config, action, fields)

    if (!answer.success) {
      if (answer.data?.login) {
        window.location.assign(answer.data.login)
        return
      }
      message(root, answer.data?.message ?? GENERIC_ERROR, false)
      return
    }

    const next = after?.(answer)
    if (typeof next === 'string') showList(next)

    await redraw(root)
  } catch {
    message(root, GENERIC_ERROR, false)
  } finally {
    root.classList.remove('is-busy')
  }
}

export function bootWishlistAccount(config?: WishlistConfig): void {
  if (!config) return

  document.addEventListener('click', (event) => {
    const target = event.target as Element | null
    const root = target?.closest?.<HTMLElement>('.galaxie-account-wishlist')
    if (!target || !root) return

    const listId = root.dataset.listId ?? ''

    const tab = target.closest<HTMLAnchorElement>('a.galaxie-wishlist-tab')
    if (tab) {
      event.preventDefault()
      // The editor's "Shown in the editor" control picks what is drawn there.
      if (inEditor()) return
      showList(tab.href)
      void redraw(root)
      return
    }

    const form = root.querySelector<HTMLFormElement>('.galaxie-wishlist-name-form')

    if (target.closest('.galaxie-wishlist-list-new, .galaxie-wishlist-list-rename') && form) {
      const renaming = !!target.closest('.galaxie-wishlist-list-rename')
      const field = form.querySelector<HTMLInputElement>('input[name="name"]')
      form.dataset.mode = renaming ? 'rename' : 'create'
      if (field) field.value = renaming ? (root.querySelector<HTMLElement>('.galaxie-wishlist-list-name')?.dataset.name ?? '') : ''
      form.hidden = false
      field?.focus()
      return
    }

    if (target.closest('.galaxie-wishlist-name-cancel') && form) {
      form.hidden = true
      return
    }

    if (target.closest('.galaxie-wishlist-list-default')) {
      void act(root, config, 'default', { list_id: listId })
      return
    }

    if (target.closest('.galaxie-wishlist-list-delete')) {
      void ask(root, 'wl_delete_confirm', 'Excluir esta lista?').then((yes) => {
        if (yes) void act(root, config, 'delete', { list_id: listId }, () => withList(''))
      })
      return
    }

    if (target.closest('.galaxie-wishlist-share-renew')) {
      void act(root, config, 'share', { list_id: listId, shared: '1', renew: '1' })
      return
    }

    const copy = target.closest<HTMLElement>('.galaxie-wishlist-share-copy')
    if (copy) {
      const url = root.querySelector<HTMLInputElement>('.galaxie-wishlist-share-url')?.value ?? ''
      void navigator.clipboard?.writeText(url).then(
        () => message(root, root.dataset.copied ?? '', true),
        () => root.querySelector<HTMLInputElement>('.galaxie-wishlist-share-url')?.select()
      )
      return
    }

    const remove = target.closest<HTMLButtonElement>('.galaxie-wishlist-remove')
    const item = remove?.closest<HTMLElement>('.galaxie-wishlist-item')
    if (remove && item) {
      event.preventDefault()
      void ask(root, 'wl_remove_confirm', 'Remover este produto da lista?').then((yes) => {
        if (!yes) return
        item.classList.add('is-removing')
        void act(root, config, 'set', { list_id: item.dataset.listId ?? listId, product_id: remove.dataset.productId ?? '', on: '' })
      })
    }
  })

  document.addEventListener('submit', (event) => {
    const form = (event.target as Element | null)?.closest?.<HTMLFormElement>('.galaxie-wishlist-name-form')
    const root = form?.closest<HTMLElement>('.galaxie-account-wishlist')
    if (!form || !root) return

    event.preventDefault()

    const name = form.querySelector<HTMLInputElement>('input[name="name"]')?.value.trim() ?? ''
    if (!name) return

    if (form.dataset.mode === 'rename') {
      void act(root, config, 'rename', { list_id: root.dataset.listId ?? '', name })
    } else {
      void act(root, config, 'create', { name }, (answer) => (answer.data?.list?.id ? withList(answer.data.list.id) : undefined))
    }
  })

  document.addEventListener('change', (event) => {
    const input = event.target as HTMLInputElement | null
    const root = input?.closest?.<HTMLElement>('.galaxie-account-wishlist')
    if (!input || !root) return

    if (input.matches('.galaxie-wishlist-share-toggle')) {
      void act(root, config, 'share', { list_id: root.dataset.listId ?? '', shared: input.checked ? '1' : '' })
    } else if (input.matches('.galaxie-wishlist-gifts-toggle')) {
      void act(root, config, 'gifts', { list_id: root.dataset.listId ?? '', gifts: input.checked ? '1' : '' })
    }
  })

  window.addEventListener('popstate', () => {
    const root = document.querySelector<HTMLElement>('.galaxie-account-wishlist')
    if (root) void redraw(root)
  })
}
