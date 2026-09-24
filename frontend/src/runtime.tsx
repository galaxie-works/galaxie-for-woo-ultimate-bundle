import { createRoot, type Root } from 'react-dom/client'
import type { ComponentType } from 'react'

import { readProps } from '@/lib/wp'

/**
 * Island runtime. Modules register their React component under a name; PHP
 * widgets render `<div data-galaxie-island="name" ...>` mounts; this walks the
 * DOM and hydrates each once. One shared bundle, one mount convention — the
 * backbone that keeps every module's UI on the same component library.
 */

// eslint-disable-next-line @typescript-eslint/no-explicit-any -- registry boundary: each island's real prop
// type is only known at its own definition, not here; PHP emits untyped JSON regardless.
type IslandComponent = ComponentType<any>

const registry = new Map<string, IslandComponent>()
const mounted = new WeakMap<Element, Root>()

export function registerIsland(name: string, component: IslandComponent): void {
  registry.set(name, component)
}

export function mountIslands(scope: ParentNode = document): void {
  scope.querySelectorAll<HTMLElement>('[data-galaxie-island]').forEach((el) => {
    if (mounted.has(el)) {
      return
    }
    const name = el.dataset.galaxieIsland
    if (!name) {
      return
    }
    const Component = registry.get(name)
    if (!Component) {
      // Widget on the page but its module's bundle isn't registered — skip
      // quietly rather than throw and take down other islands.
      return
    }
    el.classList.add('galaxie-ui')
    const root = createRoot(el)
    root.render(<Component {...readProps(el)} />)
    mounted.set(el, root)
  })
}

interface ElementorFrontend {
  hooks?: { addAction: (hook: string, callback: (scope: ArrayLike<HTMLElement>) => void) => void }
}

/**
 * Mounts islands in whatever Elementor renders after the page has loaded.
 *
 * In the editor, every widget dropped in or re-rendered by a control change
 * arrives as fresh markup over AJAX, long after `mountIslands()` walked the
 * page — the checkout used to sit there as an empty box. Elementor announces
 * each such element through `frontend/element_ready/global`; mounting its
 * subtree is idempotent (see `mounted`), so it is safe on the live site too.
 */
export function bootElementorIslands(): void {
  const w = window as unknown as {
    elementorFrontend?: ElementorFrontend
    jQuery?: (target: Window) => { on: (event: string, handler: () => void) => void }
  }

  let hooked = false
  const hook = () => {
    if (hooked || !w.elementorFrontend?.hooks) return
    hooked = true
    w.elementorFrontend.hooks.addAction('frontend/element_ready/global', (scope) => {
      if (scope[0]) mountIslands(scope[0])
    })
  }

  // Our bundle is a deferred module and may run before or after Elementor's
  // frontend has initialised; cover both orders.
  hook()
  w.jQuery?.(window).on('elementor/frontend/init', hook)
}
