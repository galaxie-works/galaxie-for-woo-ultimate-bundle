import '@/styles/index.css'

import { mountIslands, registerIsland } from '@/runtime'
import { Demo } from '@/islands/demo'
import { Checkout } from '@/islands/checkout'
import { bootToastNotices } from '@/globals/toast-notices'

// Each module registers its island(s) here as they are ported.
registerIsland('demo', Demo)
registerIsland('checkout', Checkout)

interface GalaxieConfig {
  toastNotices?: boolean
}

function boot(): void {
  mountIslands()

  const config: GalaxieConfig =
    (window as unknown as { __GALAXIE_WOO__?: GalaxieConfig }).__GALAXIE_WOO__ ?? {}

  if (config.toastNotices) {
    bootToastNotices()
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot)
} else {
  boot()
}
