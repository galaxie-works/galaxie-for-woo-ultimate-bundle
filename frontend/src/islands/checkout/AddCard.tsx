import * as React from 'react'

import { cn } from '@/lib/cn'
import { PixButton, useFieldClass, useUi } from '@/lib/pix'
import { destroyCardFields, GENERIC_ERROR, mountCardFields, saveCardToken, type CardBoxes, type CardFields, type StripeConfig } from '@/lib/stripe-cards'
import type { CheckoutText, CheckoutUi } from './types'

interface AddCardProps {
  /** Null in the editor: the boxes show, nothing is mounted in them. */
  config: StripeConfig | null
  text: CheckoutText
  /** The customer already has saved cards: the form starts closed and can be cancelled. */
  hasSaved: boolean
  /** The card is saved; the checkout refreshes and selects it. */
  onSaved: (token: string) => Promise<void>
}

/**
 * "Adicionar cartão", the checkout's side of My Account's Payment Methods:
 * the same Stripe fields and the same save (lib/stripe-cards.ts), so a card
 * added here is a saved card like any other and is then paid with. The boxes
 * are the checkout's own fields — pixfort's `.form-control` with the "Fields"
 * classes — and Stripe's iframes take their text styles from them.
 */
function AddCard({ config, text, hasSaved, onSaved }: AddCardProps) {
  const { cls, buttons } = useUi<CheckoutUi>()
  const field = useFieldClass()
  const [open, setOpen] = React.useState(!hasSaved)
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState('')
  const numberRef = React.useRef<HTMLDivElement>(null)
  const expiryRef = React.useRef<HTMLDivElement>(null)
  const cvcRef = React.useRef<HTMLDivElement>(null)
  const probeRef = React.useRef<HTMLSpanElement>(null)
  const fieldsRef = React.useRef<CardFields | null>(null)

  // With no saved card the form is the only way to pay, so it opens by itself.
  React.useEffect(() => {
    if (!hasSaved) setOpen(true)
  }, [hasSaved])

  React.useEffect(() => {
    if (!open || !config) return
    const boxes: CardBoxes | null =
      numberRef.current && expiryRef.current && cvcRef.current
        ? { number: numberRef.current, expiry: expiryRef.current, cvc: cvcRef.current }
        : null
    if (!boxes) return

    let cancelled = false
    setBusy(true)
    mountCardFields(config, boxes, probeRef.current, setError)
      .then((fields) => {
        if (cancelled) destroyCardFields(fields, boxes)
        else fieldsRef.current = fields
      })
      .catch(() => setError(GENERIC_ERROR))
      .finally(() => setBusy(false))

    return () => {
      cancelled = true
      if (fieldsRef.current) destroyCardFields(fieldsRef.current, boxes)
      fieldsRef.current = null
    }
  }, [open, config])

  async function save() {
    if (!config || !fieldsRef.current) return
    setBusy(true)
    setError('')
    let token = ''
    try {
      token = await saveCardToken(config, fieldsRef.current)
    } catch (e) {
      setBusy(false)
      setError(e instanceof Error && e.message ? e.message : GENERIC_ERROR)
      return
    }
    // Saved. Nothing after this may read as a failure, or the customer saves
    // the same card again.
    setOpen(false)
    setBusy(false)
    await onSaved(token)
  }

  if (!open) {
    return <PixButton button={buttons.addCard} onClick={() => setOpen(true)} />
  }

  const box = (label: string, key: keyof CardBoxes, ref: React.RefObject<HTMLDivElement | null>, sample: string) => (
    <div className={cn('gx-co-field', `is-${key}`)}>
      <span className={cn('gx-co-label', cls.label)}>{label}</span>
      {/* The iframe is only as tall as its text line; the whole box focuses it. */}
      <div ref={ref} className={cn(field, 'gx-co-card-box')} onClick={() => fieldsRef.current?.[key].focus()}>
        {!config && <span className="gx-co-card-sample">{sample}</span>}
      </div>
    </div>
  )

  return (
    <div className={cn('gx-co-add-card-form', busy && 'is-busy')}>
      <div className="gx-co-card-fields">
        {box(text.cardNumber, 'number', numberRef, '1234 1234 1234 1234')}
        {box(text.cardExpiry, 'expiry', expiryRef, 'MM / AA')}
        {box(text.cardCvc, 'cvc', cvcRef, 'CVC')}
      </div>
      <span ref={probeRef} className={cn(field, 'gx-co-card-placeholder')} hidden />
      {error && <span className={cn('gx-co-error', cls.error)}>{error}</span>}
      <div className="gx-co-links">
        <PixButton button={buttons.saveCard} onClick={() => void save()} disabled={busy || !config} />
        {hasSaved && <PixButton button={buttons.cancel} onClick={() => setOpen(false)} disabled={busy} />}
      </div>
    </div>
  )
}

export { AddCard }
