import '@/styles/index.css'

import { mountIslands, registerIsland } from '@/runtime'
import { Demo } from '@/islands/demo'
import { Checkout } from '@/islands/checkout'
import { MyAccount } from '@/islands/my-account'
import { bootToastNotices } from '@/globals/toast-notices'
import { bootVariationSwatches } from '@/globals/variation-swatches'
import { bootVariationSpotlight } from '@/globals/variation-spotlight'

// Each module registers its island(s) here as they are ported.
registerIsland('demo', Demo)
registerIsland('checkout', Checkout)
registerIsland('my-account', MyAccount)

interface GalaxieConfig {
  toastNotices?: boolean
  variationSwatches?: { attributes?: string[] }
  variationSpotlight?: { ajaxUrl: string; nonce: string }
}

function boot(): void {
  mountIslands()

  const config: GalaxieConfig =
    (window as unknown as { __GALAXIE_WOO__?: GalaxieConfig }).__GALAXIE_WOO__ ?? {}

  if (config.toastNotices) {
    bootToastNotices()
  }

  if (config.variationSwatches) {
    bootVariationSwatches(config.variationSwatches)
  }

  if (config.variationSpotlight) {
    bootVariationSpotlight(config.variationSpotlight)
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot)
} else {
  boot()
}
