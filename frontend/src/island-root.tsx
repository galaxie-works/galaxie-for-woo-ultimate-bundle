import { createRoot } from 'react-dom/client'
import type { ComponentType } from 'react'

/**
 * The only place React and ReactDOM enter the page. The island runtime
 * (runtime.ts) imports this lazily, the first time an island is found, so a
 * page without one — a blog post, a legal page, a 404 — never downloads React.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- see runtime.ts: PHP emits untyped JSON props.
export function renderIsland(el: HTMLElement, Component: ComponentType<any>, props: Record<string, unknown>): void {
  createRoot(el).render(<Component {...props} />)
}
