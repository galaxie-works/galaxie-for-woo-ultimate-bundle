/**
 * The account menu: switching screens without reloading the page.
 *
 * Every item is a real link to a real address, and stays one — this only gets
 * in front of the click. Anything that is not a plain left click, any screen
 * whose content region is not on this page, any error at all, and the browser
 * does what it was always going to do. A page that loads fine with the script
 * broken is the point.
 *
 * Only the screen's own box is replaced. The wrapper around it carries the
 * merchant's pixfort classes and the CSS Elementor wrote for that widget, so it
 * has to survive the swap.
 */

import { getGalaxieConfig, post } from '@/lib/wp'

interface ScreenResponse {
  screen?: string
  title?: string
  html?: string
}

const MENU = '.galaxie-account-menu'
const REGION = '.galaxie-account-content[data-account-screen]'
const BODY = '.galaxie-account-screen-body'

function region(): HTMLElement | null {
  return document.querySelector<HTMLElement>(REGION)
}

function setActive(key: string): void {
  document.querySelectorAll<HTMLAnchorElement>(`${MENU} a.nav-link`).forEach((link) => {
    const on = link.dataset.screen === key

    link.classList.toggle('active', on)

    if (on) {
      link.setAttribute('aria-current', 'page')
    } else {
      link.removeAttribute('aria-current')
    }
  })

  document.querySelectorAll<HTMLSelectElement>('.galaxie-account-menu-select').forEach((select) => {
    const option = [...select.options].find((item) => item.dataset.screen === key)
    if (option) select.value = option.value
  })
}

/**
 * Elementor binds widget behaviour when an element enters the page, once. A
 * screen drawn from a template brings its widgets in long after that, so they
 * are announced again here — the same thing Elementor does after an editor
 * re-render. Wrapped, because this is someone else's API and a missing screen
 * is not worth an exception.
 */
function announce(root: Element): void {
  const frontend = (window as unknown as { elementorFrontend?: { elementsHandler?: { runReadyTrigger?: (el: Element) => void } } })
    .elementorFrontend

  if (!frontend?.elementsHandler?.runReadyTrigger) return

  root.querySelectorAll('.elementor-element').forEach((element) => {
    try {
      frontend.elementsHandler?.runReadyTrigger?.(element)
    } catch {
      /* a handler of theirs threw; the screen is already on the page */
    }
  })
}

async function load(key: string, template: string, url: string, push: boolean): Promise<void> {
  const host = region()
  const config = getGalaxieConfig()

  if (!host || !config.myAccount) {
    window.location.assign(url)
    return
  }

  host.classList.add('is-loading')

  const res = await post<ScreenResponse>(config.myAccount.ajaxUrl, 'galaxie_myaccount_screen', config.myAccount.nonce, {
    screen: key,
    template,
  })

  host.classList.remove('is-loading')

  if (!res.success || typeof res.data?.html !== 'string') {
    window.location.assign(url)
    return
  }

  const body = host.querySelector<HTMLElement>(BODY) ?? host
  body.innerHTML = res.data.html
  host.dataset.accountScreen = res.data.screen ?? key
  // For behaviour a screen's fields need set up once they exist (the phone flag field).
  document.dispatchEvent(new CustomEvent('galaxie:account-screen', { detail: body }))

  if (host.dataset.accountTitle === 'yes' && res.data.title) {
    const title = host.querySelector('.galaxie-account-content-title')
    const target = title?.lastElementChild ?? title

    if (target) target.textContent = res.data.title
  }

  setActive(key)

  if (push) {
    window.history.pushState({ galaxieScreen: key, galaxieTemplate: template }, '', url)
  }

  announce(body)
}

export function bootAccountMenu(): void {
  // So the first Back press has somewhere to return to.
  const first = region()

  if (first && !window.history.state) {
    window.history.replaceState({ galaxieScreen: first.dataset.accountScreen ?? '' }, '', window.location.href)
  }

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return
    }

    const link = (event.target as Element | null)?.closest?.<HTMLAnchorElement>(`${MENU} a.nav-link[data-screen]`)
    const key = link?.dataset.screen

    // Logging out ends the session this page is drawn from: let it navigate.
    if (!link || !key || key === 'customer-logout' || link.target || !region()) return

    event.preventDefault()
    void load(key, link.dataset.template ?? '', link.href, true)
  })

  document.addEventListener('change', (event) => {
    const select = (event.target as Element | null)?.closest?.<HTMLSelectElement>('.galaxie-account-menu-select')
    if (!select?.value) return

    const option = select.selectedOptions[0]
    const key = option?.dataset.screen

    if (!key || key === 'customer-logout' || !region()) {
      window.location.assign(select.value)
      return
    }

    void load(key, option?.dataset.template ?? '', select.value, true)
  })

  window.addEventListener('popstate', (event) => {
    const state = event.state as { galaxieScreen?: string; galaxieTemplate?: string } | null

    if (!state?.galaxieScreen || !region()) return

    void load(state.galaxieScreen, state.galaxieTemplate ?? '', window.location.href, false)
  })
}
