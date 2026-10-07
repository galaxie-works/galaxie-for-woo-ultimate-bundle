import type { ComponentType } from 'react'

import { readProps } from '@/lib/wp'

/**
 * Island runtime. Modules register a loader for their React component under a
 * name; PHP widgets render `<div data-galaxie-island="name" ...>` mounts; this
 * walks the DOM and hydrates each once. One mount convention, one component
 * library — the backbone that keeps every module's UI consistent.
 *
 * Nothing here imports React. An island's code, and React with it, is fetched
 * only when its mount is actually on the page (see island-root.tsx), so the
 * pages with no island — most of the store — load none of it.
 */

// eslint-disable-next-line @typescript-eslint/no-explicit-any -- registry boundary: each island's real prop
// type is only known at its own definition, not here; PHP emits untyped JSON regardless.
type IslandComponent = ComponentType<any>
export type IslandLoader = () => Promise<IslandComponent>

const registry = new Map<string, IslandLoader>()
/** Mounts already claimed (loading or rendered): each is hydrated once. */
const claimed = new WeakSet<Element>()

export function registerIsland(name: string, loader: IslandLoader): void {
  registry.set(name, loader)
}

export function mountIslands(scope: ParentNode = document): void {
  scope.querySelectorAll<HTMLElement>('[data-galaxie-island]').forEach((el) => {
    if (claimed.has(el)) {
      return
    }
    const name = el.dataset.galaxieIsland
    if (!name) {
      return
    }
    const load = registry.get(name)
    if (!load) {
      // Widget on the page but its module's bundle isn't registered — skip
      // quietly rather than throw and take down other islands.
      return
    }
    claimed.add(el)
    el.classList.add('galaxie-ui')
    const props = readProps(el)

    Promise.all([import('@/island-root'), load()])
      .then(([{ renderIsland }, Component]) => renderIsland(el, Component, props))
      .catch((error: unknown) => {
        // A chunk that failed to download (a deploy mid-visit, a flaky
        // network): let a later pass try again instead of leaving it claimed.
        claimed.delete(el)
        console.error(`[galaxie] island "${name}" failed to load`, error)
      })
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
 * subtree is idempotent (see `claimed`), so it is safe on the live site too.
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
