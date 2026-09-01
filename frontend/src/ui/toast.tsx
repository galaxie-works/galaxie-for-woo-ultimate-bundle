import * as React from 'react'
import { createPortal } from 'react-dom'
import { CircleCheck, CircleX, Info, X } from 'lucide-react'

import { cn } from '@/lib/cn'

/**
 * The single Toast. A tiny pub/sub store drives one <Toaster>; call `toast()`
 * from anywhere (module code, the WC-notice interceptor) and it renders with
 * the shared tokens — semantic color carried only by the icon, sonner-style.
 */

export type ToastVariant = 'success' | 'error' | 'info'

export interface ToastItem {
  id: number
  message: string
  variant: ToastVariant
  duration: number
}

type Listener = (items: ToastItem[]) => void

let items: ToastItem[] = []
let seq = 1
const listeners = new Set<Listener>()

function emit(): void {
  const snapshot = items.slice()
  listeners.forEach((l) => l(snapshot))
}

export function toast(message: string, opts?: { variant?: ToastVariant; duration?: number }): number {
  const item: ToastItem = {
    id: seq++,
    message,
    variant: opts?.variant ?? 'info',
    duration: opts?.duration ?? 4500,
  }
  items = [...items, item]
  emit()
  if (item.duration > 0) {
    window.setTimeout(() => dismissToast(item.id), item.duration)
  }
  return item.id
}

export function dismissToast(id: number): void {
  items = items.filter((i) => i.id !== id)
  emit()
}

const ICON = { success: CircleCheck, error: CircleX, info: Info } as const
const ICON_COLOR = {
  success: 'text-emerald-600',
  error: 'text-destructive',
  info: 'text-violet-600',
} as const

export function Toaster() {
  const [list, setList] = React.useState<ToastItem[]>(() => items.slice())

  React.useEffect(() => {
    listeners.add(setList)
    return () => {
      listeners.delete(setList)
    }
  }, [])

  return createPortal(
    <div className="galaxie-ui fixed top-4 right-4 z-[100000] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2 max-[480px]:top-auto max-[480px]:right-0 max-[480px]:bottom-0 max-[480px]:max-w-none">
      {list.map((t) => {
        const Icon = ICON[t.variant]
        return (
          <div
            key={t.id}
            role="status"
            className="flex items-start gap-3 rounded-lg border border-border bg-popover p-4 text-sm text-popover-foreground shadow-lg animate-in fade-in slide-in-from-top-2"
          >
            <Icon className={cn('mt-0.5 size-4 shrink-0', ICON_COLOR[t.variant])} />
            <span className="flex-1">{t.message}</span>
            <button
              type="button"
              onClick={() => dismissToast(t.id)}
              className="text-muted-foreground transition-colors hover:text-foreground"
              aria-label="Dismiss"
            >
              <X className="size-4" />
            </button>
          </div>
        )
      })}
    </div>,
    document.body
  )
}
