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
