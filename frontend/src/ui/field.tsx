import * as React from 'react'
import { Label as LabelPrimitive } from 'radix-ui'

import { cn } from '@/lib/cn'

/**
 * Label + control + (optional) error message, laid out consistently. Every
 * form field in every module uses this wrapper so spacing/label/error styling
 * never diverges between the checkout and the account forms.
 */

function Label({ className, ...props }: React.ComponentProps<typeof LabelPrimitive.Root>) {
  return (
    <LabelPrimitive.Root
      data-slot="label"
      className={cn(
        'flex items-center gap-2 text-sm leading-none font-medium select-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
        className
      )}
      {...props}
    />
  )
}

interface FieldProps extends React.ComponentProps<'div'> {
  label?: React.ReactNode
  htmlFor?: string
  error?: React.ReactNode
  hint?: React.ReactNode
}

function Field({ label, htmlFor, error, hint, className, children, ...props }: FieldProps) {
  return (
    <div data-slot="field" className={cn('grid gap-2', className)} {...props}>
      {label ? <Label htmlFor={htmlFor}>{label}</Label> : null}
      {children}
      {hint && !error ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  )
}

export { Field, Label }
export type { FieldProps }
