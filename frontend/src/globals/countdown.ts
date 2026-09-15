/**
 * The cart urgency timer.
 *
 * The server hands this element the number of seconds left and this counts down
 * from it — it never decides the deadline itself. The anchor is a timestamp in
 * the WooCommerce session, so reloading the page does not buy the shopper five
 * more minutes, which is the difference between a deadline and a decoration.
 */

const SELECTOR = '[data-galaxie-countdown]'

function format(seconds: number): string {
  const safe = Math.max(0, seconds)
  const hours = Math.floor(safe / 3600)
  const minutes = Math.floor((safe % 3600) / 60)
  const rest = safe % 60
  const pad = (n: number): string => String(n).padStart(2, '0')

  return hours > 0 ? `${pad(hours)}:${pad(minutes)}:${pad(rest)}` : `${pad(minutes)}:${pad(rest)}`
}

function start(el: HTMLElement): void {
  const total = Number.parseInt(el.dataset.total ?? '0', 10)
  const loop = el.dataset.loop === '1'
  const clock = el.querySelector<HTMLElement>('.galaxie-countdown-time')
  const live = el.querySelector<HTMLElement>('.galaxie-countdown-live')
  const expired = el.querySelector<HTMLElement>('.galaxie-countdown-expired')

  let remaining = Number.parseInt(el.dataset.remaining ?? '0', 10)

  // Counted against a fixed end time rather than by subtracting one per tick:
  // a background tab throttles setInterval, and a timer that loses a minute
  // while the shopper reads another page is a timer nobody can trust.
  const endsAt = Date.now() + remaining * 1000

  const finish = (): void => {
    if (loop) {
      // The server restarts the clock too, on the next page it renders. Until
      // then this keeps the two agreeing about how long a round is.
      window.setTimeout(() => {
        el.dataset.remaining = String(total)
        start(el)
      }, 0)
      return
    }

    el.classList.remove('is-live')
    el.classList.add('is-expired')
    live?.setAttribute('hidden', '')
    expired?.removeAttribute('hidden')
  }

  if (remaining <= 0) {
    finish()
    return
  }

  if (clock) clock.textContent = format(remaining)

  const timer = window.setInterval(() => {
    remaining = Math.round((endsAt - Date.now()) / 1000)

    if (remaining <= 0) {
      window.clearInterval(timer)
      if (clock) clock.textContent = format(0)
      finish()
      return
    }

    if (clock) clock.textContent = format(remaining)
  }, 1000)
}

export function bootCartCountdown(): void {
  document.querySelectorAll<HTMLElement>(SELECTOR).forEach(start)
}
