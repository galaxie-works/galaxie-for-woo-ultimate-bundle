import * as React from 'react'

import type { PlacedAddress } from '@/globals/address-autocomplete'
import { cn } from '@/lib/cn'
import { CoField, PixButton, useFieldClass, useUi } from '@/lib/pix'
import { post } from '@/lib/wp'
import { Input } from '@/ui/input'
import { PlacesSearch } from './PlacesSearch'
import { ShippingChoice } from './ShippingChoice'
import { StreetFields } from './StreetFields'
import type { AddressBookData, AddressBookEntry, AddressValues, CheckoutText, CheckoutUi, ProfileValues } from './types'
import { addressIncomplete, splitStreet, validateAddressStep, type AddressErrors } from './validation'

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
  /** Every carrier refused the chosen address (see ShippingChoice). */
  noRates?: string | null
  /** Asked of a saved address that predates the Número and Bairro fields. */
  completeNotice: string
}

type Draft = Pick<AddressValues, 'postcode' | 'address_1' | 'number' | 'address_2' | 'neighborhood' | 'city' | 'state'> & { label: string }

const EMPTY: Draft = { postcode: '', address_1: '', number: '', address_2: '', neighborhood: '', city: '', state: '', label: '' }

const digits = (value: string | undefined) => (value ?? '').replace(/\D/g, '')

/**
 * An entry as the order's address. One saved before Número had its own field
 * kept the number at the end of the street; it is split back out here.
 */
function toValues(entry: AddressBookEntry): AddressValues {
  const legacy = entry.values.number ? null : splitStreet(entry.values.address_1 ?? '')
  return {
    address_1: legacy ? legacy.street : (entry.values.address_1 ?? ''),
    number: legacy ? legacy.number : (entry.values.number ?? ''),
    address_2: entry.values.address_2 ?? '',
    neighborhood: entry.values.neighborhood ?? '',
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
  noRates = null,
  completeNotice,
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
  /** The entry the form is completing; '' for a new address. */
  const [editId, setEditId] = React.useState('')
  const [errors, setErrors] = React.useState<AddressErrors>({})

  // The preselected address becomes the order's on arrival, so the carriers
  // on screen are for it — it may not be the one WooCommerce last priced.
  React.useEffect(() => {
    const entry = entries.find((e) => e.id === selected)
    if (entry && !preview) choose(entry)
    // Only on mount: later choices go through choose().
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function choose(entry: AddressBookEntry) {
    setSelected(entry.id)
    const values = toValues(entry)

    // Without a number and a bairro the order is refused (Link Nacional can
    // require both) or labelled "N/I": an entry saved before those fields
    // existed is completed first, in the same form, and only then used.
    if (addressIncomplete(values)) {
      setDraft({ ...EMPTY, ...values, label: entry.label })
      setEditId(entry.id)
      setErrors({})
      setFormOpen(true)
      onNotice(completeNotice)
      return
    }

    setFormOpen(false)
    onNotice(null)
    if (!preview) void onUse(values)
  }

  function openForm(prefill?: Partial<Draft>) {
    setDraft({ ...EMPTY, ...prefill })
    setEditId('')
    setErrors({})
    setFormOpen(true)
    onNotice(null)
  }

  async function save(e: React.FormEvent) {
    e.preventDefault()
    if (preview) return

    // The single-address form's rules: every field the label needs, and a
    // CEP with the shape of one.
    const check = validateAddressStep({ ...draft, country: 'BR' }, [])
    setErrors(check.errors)
    if (!check.ok) return

    setSaving(true)
    onNotice(null)
    const res = await post<{ entries: AddressBookEntry[] }>(book.ajaxUrl, 'galaxie_address_book_save', book.nonce, {
      id: editId,
      galaxie_ab_label: draft.label,
      shipping_first_name: profile.first_name ?? '',
      shipping_last_name: profile.last_name ?? '',
      shipping_phone: profile.phone ?? '',
      shipping_country: 'BR',
      shipping_postcode: draft.postcode,
      shipping_address_1: draft.address_1,
      shipping_number: draft.number,
      shipping_address_2: draft.address_2,
      shipping_neighborhood: draft.neighborhood,
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
    const same = (a: string | undefined, b: string) => (a ?? '').trim().toLowerCase() === b.trim().toLowerCase()
    const saved =
      (editId ? next.find((e) => e.id === editId) : undefined) ??
      next.find((e) => digits(e.values.postcode) === digits(draft.postcode) && same(e.values.address_1, draft.address_1) && same(e.values.number, draft.number)) ??
      next.find((e) => !entries.some((old) => old.id === e.id))
    setEntries(next)
    if (saved) choose(saved)
    else setFormOpen(false)
  }

  const set = (key: keyof Draft, value: string) => setDraft((prev) => ({ ...prev, [key]: value }))

  // A picked place fills the form: street and number apart, the bairro in
  // Bairro (never in Complemento). Without a CEP (a street picked without its
  // number) the CEP field is next.
  function fromPlace(place: PlacedAddress) {
    const street = splitStreet(place.address_1)
    setDraft((prev) => ({
      ...prev,
      address_1: street.street || prev.address_1,
      number: street.number || prev.number,
      neighborhood: place.neighbourhood || prev.neighborhood,
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
            <CoField label={text.postcode} htmlFor={`${id}-cep`} error={errors.postcode}>
              <Input unstyled id={`${id}-cep`} required aria-invalid={!!errors.postcode} inputMode="numeric" autoComplete="postal-code" placeholder="00000-000" className={field} value={draft.postcode} onChange={(e) => set('postcode', e.target.value)} />
            </CoField>
            <CoField label={text.addressNickname} htmlFor={`${id}-nick`} hint={text.addressNicknameHint}>
              <Input unstyled id={`${id}-nick`} maxLength={40} className={field} value={draft.label} onChange={(e) => set('label', e.target.value)} />
            </CoField>
          </div>
          <StreetFields id={id} values={draft} errors={errors} text={text} onChange={set}>
            <CoField label={text.address2} htmlFor={`${id}-a2`} hint={text.address2Hint}>
              <Input unstyled id={`${id}-a2`} autoComplete="address-line2" className={field} value={draft.address_2} onChange={(e) => set('address_2', e.target.value)} />
            </CoField>
          </StreetFields>
          <div className="gx-co-grid-city">
            <CoField label={text.city} htmlFor={`${id}-city`} error={errors.city}>
              <Input unstyled id={`${id}-city`} required aria-invalid={!!errors.city} autoComplete="address-level2" className={field} value={draft.city} onChange={(e) => set('city', e.target.value)} />
            </CoField>
            <CoField label={text.state} htmlFor={`${id}-uf`} error={errors.state}>
              <Input unstyled id={`${id}-uf`} required aria-invalid={!!errors.state} maxLength={2} autoComplete="address-level1" className={field} value={draft.state} onChange={(e) => set('state', e.target.value.toUpperCase())} />
            </CoField>
          </div>
          <div className="gx-co-links">
            <PixButton button={buttons.addressButton} type="submit" disabled={saving || busy} />
            {entries.length > 0 && <PixButton button={buttons.cancel} onClick={() => {
              setFormOpen(false)
              // An entry left incomplete is not the order's address: nothing stays chosen.
              if (editId) setSelected('')
            }} />}
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
        noRates={noRates}
      />
    </div>
  )
}

export { AddressBookStep }
