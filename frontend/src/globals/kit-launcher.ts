/**
 * The kit popup's pixfort launcher: our gift icon and a candle count
 * (PHP Kit\Launcher prints the icon template and the badge colours).
 *
 * pixfort prints `<a id="pix_launcher_{id}" class="pix-popup-launcher …">`
 * with `<span class="pix-launcher-main">{svg}</span>` in the footer, hidden
 * until its dialog script styles it. It may be there before this runs, or
 * arrive later (a popup fetched over AJAX), so the launcher is looked for now
 * and whenever the page changes, and every launcher found is enhanced once:
 * - the icon inside `.pix-launcher-main` is replaced with the template's;
 * - `data-count` holds the candles in the kit being built, and goes away
 *   without a kit (or with none in it). The badge is CSS (`::after`), so the
 *   popup's own Custom CSS can restyle it.
 */

import { kitConfig, onKit, currentKit, kitLoaded } from '@/globals/kit-store'

function enhance(launcher: HTMLElement, icon: boolean): void {
  if (launcher.dataset.galaxieKit) return
  launcher.dataset.galaxieKit = '1'
  launcher.classList.add('galaxie-kit-launcher')

  if (!icon) return

  const template = document.getElementById('galaxie-kit-launcher-icon') as HTMLTemplateElement | null
  const main = launcher.querySelector<HTMLElement>('.pix-launcher-main')

  if (template && main && !main.querySelector('img')) {
    main.replaceChildren(template.content.cloneNode(true))
  }
}

function count(launcher: HTMLElement, badge: boolean): void {
  const kit = currentKit()
  const n = kit?.count ?? 0

  if (badge && kitLoaded() && kit && n > 0) {
    if (launcher.dataset.count !== String(n)) launcher.dataset.count = String(n)
  } else if (launcher.hasAttribute('data-count')) {
    launcher.removeAttribute('data-count')
  }

  launcher.classList.toggle('has-kit', !!kit)
}

export function bootKitLauncher(): void {
  const config = kitConfig()
  if (!config) return

  const id = `pix_launcher_${config.popup}`
  let queued = false

  const apply = (): void => {
    queued = false
    const launcher = document.getElementById(id)
    if (!launcher) return

    enhance(launcher, config.launcherIcon)
    count(launcher, config.badge)
  }

  new MutationObserver(() => {
    if (queued) return
    queued = true
    window.requestAnimationFrame(apply)
  }).observe(document.body, { childList: true, subtree: true })

  onKit(apply)
  apply()
}
