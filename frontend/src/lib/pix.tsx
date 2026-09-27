import * as React from 'react'

import { cn } from '@/lib/cn'
/** A button as pixfort's own Button element drew it, label included. */
export interface PixButtonData {
  html: string
  /** The panel's "Full width button": the wrapping <button> must stretch too. */
  full: boolean
}

/**
 * pixfort's parts as a widget's Style tab configured them, built in PHP:
 * class strings (text_classes / surface_classes) and finished button / alert
 * markup. Each island narrows this with its own keys (see checkout's
 * `CheckoutUi`, login's `LoginUi`).
 */
export interface PixUi {
  cls: Record<string, string>
  buttons: Record<string, PixButtonData>
  /** pixfort's Alert with `marker` where the message goes. */
  alert: string
  /** Stands in for a label pixfort drew before the island knew it. */
  marker: string
}

/**
 * pixfort's parts inside a React island (the checkout, the login).
 *
 * Every other widget of the plugin is styled through pixfort's own control
 * sets, and those controls are classes and markup pixfort prints. The island
 * cannot ask pixfort for them, so PHP does (`CheckoutWidget::ui()`) and they
 * arrive here: class strings to put on our elements, and buttons and the
 * alert as pixfort's finished markup to put inside them.
 */

const UiContext = React.createContext<PixUi | null>(null)

export const UiProvider = UiContext.Provider

/** The nearest island's parts, typed as that island declares them. */
export function useUi<T extends PixUi = PixUi>(): T {
  const ui = React.useContext(UiContext)
  if (!ui) throw new Error('pixfort parts used outside a UiProvider')
  return ui as T
}

export function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c)
}

/**
 * pixfort's button inside our own <button>, the plugin's usual pairing
 * (`.galaxie-account-submit`): the element is the hit area and the form
 * control, pixfort's `.btn` inside draws everything.
 */
export function PixButton({
  button,
  className,
  ...props
}: { button: PixButtonData } & Omit<React.ComponentProps<'button'>, 'children' | 'dangerouslySetInnerHTML'>) {
  return (
    <button
      type="button"
      {...props}
      className={cn('galaxie-account-submit gx-co-btn', button.full && 'is-full', className)}
      dangerouslySetInnerHTML={{ __html: button.html }}
    />
  )
}

/** The same, as a link (sign out). */
export function PixLink({ button, href, className }: { button: PixButtonData; href: string; className?: string }) {
  return (
    <a
      href={href}
      className={cn('galaxie-account-button gx-co-btn', button.full && 'is-full', className)}
      dangerouslySetInnerHTML={{ __html: button.html }}
    />
  )
}

/** pixfort's Alert, with the message written where PHP left the marker. */
export function PixAlert({ message }: { message: string }) {
  const ui = useUi()
  return <div dangerouslySetInnerHTML={{ __html: ui.alert.split(ui.marker).join(escapeHtml(message)) }} />
}

/**
 * Label, control, then a hint or an error: the account forms' field, with
 * the panel's "Texts: field labels / hints / error under a field" classes.
 */
export function CoField({
  label,
  htmlFor,
  hint,
  error,
  className,
  children,
}: {
  label: React.ReactNode
  htmlFor?: string
  hint?: React.ReactNode
  error?: React.ReactNode
  className?: string
  children: React.ReactNode
}) {
  const { cls } = useUi()
  return (
    <div className={cn('gx-co-field', className)}>
      <label htmlFor={htmlFor} className={cn('gx-co-label', cls.label)}>
        {label}
      </label>
      {children}
      {hint && !error ? <span className={cn('gx-co-hint', cls.hint)}>{hint}</span> : null}
      {error ? <span className={cn('gx-co-error', cls.error)}>{error}</span> : null}
    </div>
  )
}

/** The class a text input carries: pixfort's `.form-control` plus the "Fields" box classes (`cls.field`). */
export function useFieldClass(): string {
  const { cls } = useUi()
  return cn('form-control gx-co-input', cls.field)
}
