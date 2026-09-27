import * as React from 'react'

import type { PlacedAddress } from '@/globals/address-autocomplete'
import { cn } from '@/lib/cn'
import { CoField, PixButton, useFieldClass, useUi } from '@/lib/pix'
import { post } from '@/lib/wp'
import { Input } from '@/ui/input'
import { PlacesSearch } from './PlacesSearch'
import { ShippingChoice } from './ShippingChoice'
import type { AddressBookData, AddressBookEntry, AddressValues, CheckoutText, CheckoutUi, ProfileValues } from './types'

interface AddressBookStepProps {
  book: AddressBookData
  quoted: { postcode: string; city: string; state: string } | null
  /** Names and phone for a new entry: the book requires a recipient. */
  profile: Partial<ProfileValues>
  text: CheckoutText
  busy: boolean
  genericError: string
  shippingMountRef: React.RefObject<HTMLDivElement | null>
  /** Makes `values` the order's address (native fields, rates). */
  onUse: (values: AddressValues) => Promise<void> | void
  onContinue: () => void
  onNotice: (message: string | null) => void
  preview: boolean
}

type Draft = Pick<AddressValues, 'postcode' | 'address_1' | 'address_2' | 'city' | 'state'> & { label: string }

const EMPTY: Draft = { postcode: '', address_1: '', address_2: '', city: '', state: '', label: '' }

const digits = (value: string | undefined) => (value ?? '').replace(/\D/g, '')

function toValues(entry: AddressBookEntry): AddressValues {
  return {
    address_1: entry.values.address_1 ?? '',
    address_2: entry.values.address_2 ?? '',
    city: entry.values.city ?? '',
    state: entry.values.state ?? '',
    postcode: entry.values.postcode ?? '',
    country: entry.values.country || 'BR',
  }
}

/**
 * The delivery step on top of My Account's Address Book.
 *
 * The saved addresses are the book's own entries, chosen like the shipping
 * options; "Adicionar endereço" saves through the book's endpoint
 * (`galaxie_address_book_save`), so a checkout address follows the same rules
 * as one added in My Account — required fields, a real CEP and UF, the
 * 20-address limit, and an address already in the book updated rather than
 * doubled — and is there, in My Account, afterwards.
 *
 * Where it starts, for the three ways a shopper arrives:
 * - a saved address matching the CEP they quoted on the cart is preselected;
 * - otherwise the book's default, with a line offering the quoted CEP as a
 *   new address when it is none of theirs;
 * - no saved address at all: the form, already holding the quoted CEP.
 */
function AddressBookStep({
  book,
  quoted,
  profile,
  text,
  busy,
  genericError,
  shippingMountRef,
  onUse,
  onContinue,
  onNotice,
  preview,
}: AddressBookStepProps) {
  const { cls, buttons } = useUi<CheckoutUi>()
  const field = useFieldClass()
  const id = React.useId()

  const [entries, setEntries] = React.useState(book.entries)
  const quotedEntry = quoted ? entries.find((e) => digits(e.values.postcode) === digits(quoted.postcode)) : undefined
  const [selected, setSelected] = React.useState<string>(
    () => (quotedEntry ?? entries.find((e) => e.shipping) ?? entries[0])?.id ?? ''
  )
  const [formOpen, setFormOpen] = React.useState(0 === entries.length)
  const [draft, setDraft] = React.useState<Draft>(() =>
    0 === entries.length && quoted ? { ...EMPTY, postcode: quoted.postcode, city: quoted.city, state: quoted.state } : EMPTY
  )
  const [saving, setSaving] = React.useState(false)

  // The preselected address becomes the order's on arrival, so the carriers
  // on screen are for it — it may not be the one WooCommerce last priced.
  React.useEffect(() => {
    const entry = entries.find((e) => e.id === selected)
    if (entry && !preview) void onUse(toValues(entry))
    // Only on mount: later choices go through choose().
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function choose(entry: AddressBookEntry) {
    setSelected(entry.id)
    setFormOpen(false)
    onNotice(null)
    if (!preview) void onUse(toValues(entry))
  }

  function openForm(prefill?: Partial<Draft>) {
    setDraft({ ...EMPTY, ...prefill })
    setFormOpen(true)
    onNotice(null)
  }

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (preview) return

    setSaving(true)
    onNotice(null)
    const res = await post<{ entries: AddressBookEntry[] }>(book.ajaxUrl, 'galaxie_address_book_save', book.nonce, {
      id: '',
      galaxie_ab_label: draft.label,
      shipping_first_name: profile.first_name ?? '',
      shipping_last_name: profile.last_name ?? '',
      shipping_phone: profile.phone ?? '',
      shipping_country: 'BR',
      shipping_postcode: draft.postcode,
      shipping_address_1: draft.address_1,
      shipping_address_2: draft.address_2,
      shipping_city: draft.city,
      shipping_state: draft.state.toUpperCase(),
    })
    setSaving(false)

    if (!res.success || !res.data?.entries) {
      onNotice(res.data?.message ?? genericError)
      return
    }

    // The book answers with the whole list; the new entry is the one at this
    // CEP and street (the book may have updated an existing one instead).
    const next = res.data.entries
    const saved =
      next.find((e) => digits(e.values.postcode) === digits(draft.postcode) && (e.values.address_1 ?? '').trim().toLowerCase() === draft.address_1.trim().toLowerCase()) ??
      next.find((e) => !entries.some((old) => old.id === e.id))
    setEntries(next)
    if (saved) choose(saved)
    else setFormOpen(false)
  }

  const set = (key: keyof Draft, value: string) => setDraft((prev) => ({ ...prev, [key]: value }))

  // A picked place fills the form; the neighbourhood goes where the cart's
  // search puts it (the complement), unless something is typed there already.
  // Without a CEP (a street picked without its number) the CEP field is next.
  function fromPlace(place: PlacedAddress) {
    setDraft((prev) => ({
      ...prev,
      address_1: place.address_1 || prev.address_1,
      address_2: prev.address_2 || place.neighbourhood,
      city: place.city || prev.city,
      state: place.state || prev.state,
      postcode: place.postcode || prev.postcode,
    }))
    if (!place.postcode) document.getElementById(`${id}-cep`)?.focus()
  }

  const showQuoted = quoted && '' !== digits(quoted.postcode) && !quotedEntry && entries.length > 0 && !formOpen
  const addressChosen = !formOpen && '' !== selected

  return (
    <div className="gx-co-form">
      {entries.length > 0 && (
        <ul className="gx-co-addresses" role="radiogroup">
          {entries.map((entry) => (
            <li key={entry.id} className={cn('gx-co-address', cls.address)}>
              <input
                type="radio"
                id={`${id}-${entry.id}`}
                name={`${id}-address`}
                checked={!formOpen && selected === entry.id}
                onChange={() => choose(entry)}
              />
              <label htmlFor={`${id}-${entry.id}`}>
                <span className="gx-co-address-body">
                  {entry.label && <span className={cn('gx-co-address-name', cls.addrName)}>{entry.label}</span>}
                  <span className={cn('gx-co-address-text', cls.addrText)} dangerouslySetInnerHTML={{ __html: entry.formatted }} />
                </span>
                {entry.shipping && <span className={cn('gx-co-card-badge is-default', cls.tokenBadge)}>{text.defaultBadge}</span>}
              </label>
            </li>
          ))}
        </ul>
      )}

      {showQuoted && (
        <div className={cn('gx-co-recap', cls.option)}>
          <p className={cn('gx-co-body', cls.body)}>{text.quotedNotice.replace('%s', quoted.postcode)}</p>
          <PixButton button={buttons.useQuoted} onClick={() => openForm({ postcode: quoted.postcode, city: quoted.city, state: quoted.state })} />
        </div>
      )}

      {formOpen ? (
        <form noValidate onSubmit={save} className="gx-co-form">
          <PlacesSearch label={text.addressSearch} onPlace={fromPlace} />
          <div className="gx-co-grid-2">
            <CoField label={text.postcode} htmlFor={`${id}-cep`}>
              <Input unstyled id={`${id}-cep`} required inputMode="numeric" autoComplete="postal-code" placeholder="00000-000" className={field} value={draft.postcode} onChange={(e) => set('postcode', e.target.value)} />
            </CoField>
            <CoField label={text.addressNickname} htmlFor={`${id}-nick`} hint={text.addressNicknameHint}>
              <Input unstyled id={`${id}-nick`} maxLength={40} className={field} value={draft.label} onChange={(e) => set('label', e.target.value)} />
            </CoField>
          </div>
          <CoField label={text.address1} htmlFor={`${id}-a1`}>
            <Input unstyled id={`${id}-a1`} required autoComplete="address-line1" className={field} value={draft.address_1} onChange={(e) => set('address_1', e.target.value)} />
          </CoField>
          <CoField label={text.address2} htmlFor={`${id}-a2`} hint={text.address2Hint}>
            <Input unstyled id={`${id}-a2`} autoComplete="address-line2" className={field} value={draft.address_2} onChange={(e) => set('address_2', e.target.value)} />
          </CoField>
          <div className="gx-co-grid-city">
            <CoField label={text.city} htmlFor={`${id}-city`}>
              <Input unstyled id={`${id}-city`} required autoComplete="address-level2" className={field} value={draft.city} onChange={(e) => set('city', e.target.value)} />
            </CoField>
            <CoField label={text.state} htmlFor={`${id}-uf`}>
              <Input unstyled id={`${id}-uf`} required maxLength={2} autoComplete="address-level1" className={field} value={draft.state} onChange={(e) => set('state', e.target.value.toUpperCase())} />
            </CoField>
          </div>
          <div className="gx-co-links">
            <PixButton button={buttons.addressButton} type="submit" disabled={saving || busy} />
            {entries.length > 0 && <PixButton button={buttons.cancel} onClick={() => setFormOpen(false)} />}
          </div>
        </form>
      ) : (
        <div>
          <PixButton button={buttons.addAddress} onClick={() => openForm()} />
        </div>
      )}

      <ShippingChoice
        text={text}
        shippingMountRef={shippingMountRef}
        busy={busy || saving}
        shown={addressChosen}
        onContinue={onContinue}
        preview={preview}
      />
    </div>
  )
}

export { AddressBookStep }
