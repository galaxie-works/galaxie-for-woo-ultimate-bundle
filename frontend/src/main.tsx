import '@/styles/index.css'

import { mountIslands, registerIsland } from '@/runtime'
import { Demo } from '@/islands/demo'

// Each module registers its island(s) here as they are ported.
registerIsland('demo', Demo)

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => mountIslands())
} else {
  mountIslands()
}
