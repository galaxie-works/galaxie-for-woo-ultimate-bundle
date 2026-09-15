/**
 * A burst of confetti on a canvas laid over the page.
 *
 * Written here rather than pulled in as a library: it is one short animation,
 * and the bundle already loads on every page of the store.
 */

interface Particle {
  x: number
  y: number
  vx: number
  vy: number
  size: number
  color: string
  rotation: number
  spin: number
  round: boolean
  wobble: number
}

export interface BurstOptions {
  /** Where the burst starts. Null rains it from the top of the screen instead. */
  origin: DOMRect | null
  count: number
  /** Any CSS colour, `var(--pix-primary)` included. */
  colors: string[]
}

/** Classic party confetti, used whenever no colours are chosen. */
const FALLBACK = ['#ff4d6d', '#ffb703', '#ffd60a', '#06d6a0', '#118ab2', '#4cc9f0', '#9b5de5', '#f15bb5', '#fb8500']
const DURATION = 2600

/**
 * Turn `var(--pix-primary)` into the rgb the theme currently gives it, so the
 * confetti follows pixfort's palette, dark mode included.
 */
function resolve(colors: string[]): string[] {
  const probe = document.createElement('span')
  probe.style.display = 'none'
  document.body.appendChild(probe)

  const resolved = colors
    .map((value) => {
      probe.style.color = ''
      probe.style.color = value
      const computed = getComputedStyle(probe).color
      return computed && computed !== 'rgba(0, 0, 0, 0)' ? computed : ''
    })
    .filter(Boolean)

  probe.remove()

  return resolved.length ? resolved : FALLBACK
}

export function burst({ origin, count, colors }: BurstOptions): void {
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return

  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  if (!ctx) return

  const width = window.innerWidth
  const height = window.innerHeight
  const ratio = Math.min(window.devicePixelRatio || 1, 2)

  canvas.width = width * ratio
  canvas.height = height * ratio
  canvas.setAttribute('aria-hidden', 'true')
  Object.assign(canvas.style, {
    position: 'fixed',
    top: '0',
    left: '0',
    width: `${width}px`,
    height: `${height}px`,
    pointerEvents: 'none',
    zIndex: '2147483000',
  })
  document.body.appendChild(canvas)
  ctx.scale(ratio, ratio)

  const palette = resolve(colors)
  const particles: Particle[] = []
  const x0 = origin ? origin.left + origin.width / 2 : width / 2
  const y0 = origin ? origin.top + origin.height / 2 : -20

  for (let i = 0; i < count; i++) {
    const color = palette[i % palette.length] ?? FALLBACK[0]
    const size = 6 + Math.random() * 6

    if (origin) {
      // Thrown upwards in a wide fan, then pulled down by gravity.
      const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 0.95
      const speed = 7 + Math.random() * 9
      particles.push({ x: x0, y: y0, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, size, color, rotation: Math.random() * 360, spin: (Math.random() - 0.5) * 20, round: Math.random() < 0.3, wobble: Math.random() * 10 })
    } else {
      particles.push({ x: Math.random() * width, y: y0 - Math.random() * height * 0.4, vx: (Math.random() - 0.5) * 3, vy: 2 + Math.random() * 3, size, color, rotation: Math.random() * 360, spin: (Math.random() - 0.5) * 20, round: Math.random() < 0.3, wobble: Math.random() * 10 })
    }
  }

  const start = performance.now()

  const frame = (now: number): void => {
    const elapsed = now - start
    ctx.clearRect(0, 0, width, height)

    // Everything fades over the last third, so nothing just vanishes.
    ctx.globalAlpha = Math.max(0, Math.min(1, (DURATION - elapsed) / (DURATION / 3)))

    for (const p of particles) {
      p.vy += 0.28
      p.vx *= 0.985
      p.vy *= 0.985
      p.wobble += 0.12
      p.x += p.vx + Math.sin(p.wobble) * 0.6
      p.y += p.vy
      p.rotation += p.spin

      ctx.save()
      ctx.translate(p.x, p.y)
      ctx.rotate((p.rotation * Math.PI) / 180)
      ctx.fillStyle = p.color

      if (p.round) {
        ctx.beginPath()
        ctx.arc(0, 0, p.size / 2.4, 0, Math.PI * 2)
        ctx.fill()
      } else {
        // Flat paper turning over: the height breathes with the wobble.
        ctx.fillRect(-p.size / 2, (-p.size / 4) * Math.abs(Math.cos(p.wobble)), p.size, (p.size / 2) * Math.abs(Math.cos(p.wobble)) + 1)
      }

      ctx.restore()
    }

    if (elapsed < DURATION) {
      requestAnimationFrame(frame)
    } else {
      canvas.remove()
    }
  }

  requestAnimationFrame(frame)
}
