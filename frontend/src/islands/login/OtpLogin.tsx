import * as React from 'react'

import { cn } from '@/lib/cn'
import { post } from '@/lib/wp'
import { Input } from '@/ui/input'
import { OtpInput } from '@/ui/otp-input'
import { PhoneInput } from '@/ui/phone-input'
import { CoField, PixAlert, PixButton, PixLink, useFieldClass, useUi, type PixButtonData, type PixUi } from '@/lib/pix'
import type { AuthConfig } from '@/lib/wp'
import type { ProfileValues } from '@/islands/checkout/types'
import { BAD_PHONE } from '@/islands/checkout/validation'

/** Every string the login prints. The checkout's own text set extends this one. */
export interface LoginText {
  entryIntro: string
  tabLogin: string
  tabRegister: string
  email: string
  sendCode: string
  registerButton: string
  /** Contains `%s`, replaced by the e-mail address. */
  codeHint: string
  confirmCode: string
  resendCode: string
  changeEmail: string
  firstName: string
  lastName: string
  birthdate: string
  cpf: string
  phone: string
  marketing: string
  terms: string
  /** Sign-in with a password, when sign-in by code is off. */
  signIn: string
  createAccount: string
  forgotPassword: string
  password: string
  passwordHint: string
}

/**
 * The pixfort parts the login draws, which the host's `UiProvider` must
 * carry (PHP builds them; see `CheckoutWidget::ui()` for the checkout's).
 * Empty strings are fine: a control left at Default prints no class.
 */
export interface LoginUi extends PixUi {
  cls: Record<'body' | 'small' | 'label' | 'hint' | 'error' | 'tab' | 'tabText' | 'option' | 'field', string>
  buttons: Record<
    'sendCode' | 'registerButton' | 'confirmCode' | 'resendCode' | 'changeEmail' | 'signIn' | 'createAccount' | 'forgotPassword',
    PixButtonData
  >
}

interface OtpLoginProps {
  authCfg?: AuthConfig
  text: LoginText
  genericError: string
  onVerified: () => void
  /** Editor preview: the forms render but never submit. */
  preview?: boolean
  /** Which tab opens first: the checkout's editor preview opens "register" for a first purchase. */
  initialTab?: Tab
}

type Stage = 'request' | 'verify'
type Tab = 'otp' | 'register'

/**
 * Passwordless sign-in / registration, shared: the checkout's first step,
 * and any page that needs the same sign-in (a "Galaxie Login" widget for the
 * signed-out account screen, a /login page).
 *
 * It takes everything through props and the nearest `UiProvider` — no
 * checkout state — so a host needs only: a `UiProvider` whose value satisfies
 * `LoginUi`, a wrapper with the `gx-co` class (the layout rules in
 * index.css are scoped to it), `authCfg` from the PasswordlessAuth boot data,
 * and what to do once the code is verified (the checkout reloads).
 * Talks directly to the PasswordlessAuth
 * module's AJAX actions (galaxie_auth_send_otp / galaxie_auth_verify_otp).
 * On a verified code, reloads the page rather than managing a client-side
 * transition: the widget re-renders server-side signed in, with fresh
 * profile/address props, and the step machine's initial-step logic (see
 * index.tsx) lands on the right next step.
 *
 * With sign-in by code switched off (`authCfg.mode === 'password'`, set on
 * wp-admin → Galaxie → Login) the same two tabs ask for a password instead:
 * "Já sou cliente" signs in with e-mail and password and offers "Esqueci
 * minha senha" (WooCommerce's lost-password page); "Primeira compra" is the
 * same form plus a password, and creates the account at once. Both go
 * through the PasswordlessAuth module too, so a new customer is created —
 * and announced to FluentCRM — the same way either way.
 *
 * The two tabs are the Wishlist's list tabs (a pill with an `is-current`
 * state), the fields pixfort's `.form-control`, the opt-in the Communication
 * widget's switch — each styled from the widget's own Style tab.
 */
function OtpLogin({ authCfg, text, genericError, onVerified, preview = false, initialTab = 'otp' }: OtpLoginProps) {
  const { cls, buttons } = useUi<LoginUi>()
  const field = useFieldClass()
  const [tab, setTab] = React.useState<Tab>(initialTab)
  const [stage, setStage] = React.useState<Stage>('request')
  const [email, setEmail] = React.useState('')
  const [code, setCode] = React.useState('')
  const [password, setPassword] = React.useState('')
  const passwordMode = 'password' === authCfg?.mode
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)

  const [reg, setReg] = React.useState<ProfileValues & { terms: boolean; marketing: boolean }>({
    first_name: '',
    last_name: '',
    phone: '',
    birthdate: '',
    cpf: '',
    terms: false,
    marketing: true,
  })
  // The flag field's verdict on the (optional) phone; null until it can tell.
  const [phoneValid, setPhoneValid] = React.useState<boolean | null>(null)
  const id = React.useId()

  function switchTab(next: Tab) {
    setTab(next)
    setStage('request')
    setError(null)
    setCode('')
  }

  async function sendCode(e: React.FormEvent | React.MouseEvent) {
    e.preventDefault()
    if (preview || !authCfg) return
    if ('register' === tab && '' !== reg.phone && false === phoneValid) {
      setError(BAD_PHONE)
      return
    }
    setBusy(true)
    setError(null)

    if (passwordMode) {
      const action = 'register' === tab ? 'galaxie_auth_password_register' : 'galaxie_auth_password_login'
      const fields: Record<string, string> =
        'register' === tab
          ? {
              email,
              password,
              first_name: reg.first_name,
              last_name: reg.last_name,
              phone: reg.phone,
              birthdate: reg.birthdate,
              cpf: reg.cpf,
              terms: reg.terms ? '1' : '',
              marketing: reg.marketing ? '1' : '',
            }
          : { email, password }
      const res = await post(authCfg.ajaxUrl, action, authCfg.nonce, fields)
      if (!res.success) {
        setBusy(false)
        setError(res.data?.message ?? genericError)
        return
      }
      onVerified()
      return
    }

    const data: Record<string, string> =
      'register' === tab
        ? {
            email,
            context: 'register',
            first_name: reg.first_name,
            last_name: reg.last_name,
            phone: reg.phone,
            birthdate: reg.birthdate,
            cpf: reg.cpf,
            terms: reg.terms ? '1' : '',
            marketing: reg.marketing ? '1' : '',
          }
        : { email, context: 'login' }

    const res = await post(authCfg.ajaxUrl, 'galaxie_auth_send_otp', authCfg.nonce, data)
    setBusy(false)
    if (!res.success) {
      setError(res.data?.message ?? genericError)
      return
    }
    setCode('')
    setStage('verify')
  }

  async function verifyCode(e: React.FormEvent) {
    e.preventDefault()
    if (preview || !authCfg) return
    setBusy(true)
    setError(null)
    const res = await post(authCfg.ajaxUrl, 'galaxie_auth_verify_otp', authCfg.nonce, { email, code })
    if (!res.success) {
      setBusy(false)
      setError(res.data?.message ?? genericError)
      return
    }
    onVerified()
  }

  if ('verify' === stage) {
    return (
      <form onSubmit={verifyCode} className="gx-co-form">
        <p className={cn('gx-co-body', cls.body)}>
          {text.codeHint.split('%s').map((part, i, all) => (
            <React.Fragment key={i}>
              {part}
              {i < all.length - 1 && <strong>{email}</strong>}
            </React.Fragment>
          ))}
        </p>
        {error && <PixAlert message={error} />}
        <OtpInput value={code} onChange={setCode} autoFocus cellClassName={cn(field, 'gx-co-otp')} />
        <PixButton button={buttons.confirmCode} type="submit" disabled={busy || 6 !== code.length} />
        <div className="gx-co-links">
          <PixButton button={buttons.resendCode} onClick={sendCode} disabled={busy} />
          <PixButton
            button={buttons.changeEmail}
            onClick={() => {
              setStage('request')
              setError(null)
            }}
          />
        </div>
      </form>
    )
  }

  const emailField = (
    <CoField label={text.email} htmlFor={`${id}-email`}>
      <Input
        unstyled
        id={`${id}-email`}
        type="email"
        required
        autoComplete="email"
        className={field}
        value={email}
        onChange={(e) => setEmail(e.target.value)}
      />
    </CoField>
  )

  const passwordField = (register: boolean) => (
    <CoField label={text.password} htmlFor={`${id}-pw`} hint={register ? text.passwordHint : undefined}>
      <Input
        unstyled
        id={`${id}-pw`}
        type="password"
        required
        minLength={register ? 8 : undefined}
        autoComplete={register ? 'new-password' : 'current-password'}
        className={field}
        value={password}
        onChange={(e) => setPassword(e.target.value)}
      />
    </CoField>
  )

  return (
    <div className="gx-co-form">
      {text.entryIntro && <p className={cn('gx-co-body', cls.body)}>{text.entryIntro}</p>}

      <div role="tablist" className="gx-co-tabs">
        {(
          [
            ['otp', text.tabLogin],
            ['register', text.tabRegister],
          ] as const
        ).map(([value, label]) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={tab === value}
            onClick={() => switchTab(value)}
            className={cn('gx-co-tab', tab === value && 'is-current', cls.tabText, cls.tab)}
          >
            {label}
          </button>
        ))}
      </div>

      {error && <PixAlert message={error} />}

      {'otp' === tab ? (
        <form role="tabpanel" onSubmit={sendCode} className="gx-co-form">
          {emailField}
          {passwordMode && passwordField(false)}
          <PixButton button={passwordMode ? buttons.signIn : buttons.sendCode} type="submit" disabled={busy} />
          {passwordMode && authCfg?.lostPasswordUrl && (
            <div className="gx-co-links">
              <PixLink button={buttons.forgotPassword} href={authCfg.lostPasswordUrl} />
            </div>
          )}
        </form>
      ) : (
        <form role="tabpanel" onSubmit={sendCode} className="gx-co-form">
          {emailField}
          <div className="gx-co-grid-2">
            <CoField label={text.firstName} htmlFor={`${id}-fn`}>
              <Input unstyled id={`${id}-fn`} required autoComplete="given-name" className={field} value={reg.first_name} onChange={(e) => setReg({ ...reg, first_name: e.target.value })} />
            </CoField>
            <CoField label={text.lastName} htmlFor={`${id}-ln`}>
              <Input unstyled id={`${id}-ln`} required autoComplete="family-name" className={field} value={reg.last_name} onChange={(e) => setReg({ ...reg, last_name: e.target.value })} />
            </CoField>
            <CoField label={text.birthdate} htmlFor={`${id}-bd`}>
              <Input unstyled id={`${id}-bd`} type="date" className={field} value={reg.birthdate} onChange={(e) => setReg({ ...reg, birthdate: e.target.value })} />
            </CoField>
            <CoField label={text.cpf} htmlFor={`${id}-cpf`}>
              <Input unstyled id={`${id}-cpf`} inputMode="numeric" className={field} value={reg.cpf} onChange={(e) => setReg({ ...reg, cpf: e.target.value })} placeholder="000.000.000-00" />
            </CoField>
          </div>
          <CoField label={text.phone} htmlFor={`${id}-ph`}>
            <PhoneInput
              unstyled
              id={`${id}-ph`}
              className={field}
              value={reg.phone}
              onChange={(phone, valid) => {
                setReg((prev) => ({ ...prev, phone }))
                setPhoneValid(valid)
              }}
            />
          </CoField>

          {passwordMode && passwordField(true)}

          <label className={cn('gx-co-option', cls.option)}>
            <span className={cn('gx-co-body', cls.body)}>{text.marketing}</span>
            <span className="galaxie-switch">
              <input type="checkbox" checked={reg.marketing} onChange={(e) => setReg({ ...reg, marketing: e.target.checked })} />
              <span className="galaxie-switch-track" aria-hidden="true" />
            </span>
          </label>

          <label className="gx-co-check">
            <input type="checkbox" required checked={reg.terms} onChange={(e) => setReg({ ...reg, terms: e.target.checked })} />
            <span className={cn('gx-co-small', cls.small)}>{text.terms}</span>
          </label>

          <PixButton button={passwordMode ? buttons.createAccount : buttons.registerButton} type="submit" disabled={busy} />
        </form>
      )}
    </div>
  )
}

export { OtpLogin }
