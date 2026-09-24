import * as React from 'react'

import { Button } from '@/ui/button'
import { Field } from '@/ui/field'
import { Input } from '@/ui/input'
import { OtpInput } from '@/ui/otp-input'
import { PhoneInput } from '@/ui/phone-input'
import { Switch } from '@/ui/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/ui/tabs'
import { post } from '@/lib/wp'
import type { CheckoutText, ProfileValues } from './types'
import { BAD_PHONE } from './validation'

interface AjaxEndpoint {
  ajaxUrl: string
  nonce: string
}

interface EntryStepProps {
  authCfg?: AjaxEndpoint
  text: CheckoutText
  genericError: string
  onVerified: () => void
  /** Editor preview: the forms render but never submit. */
  preview: boolean
}

type Stage = 'request' | 'verify'

/**
 * Passwordless sign-in / registration. Talks directly to the PasswordlessAuth
 * module's AJAX actions (galaxie_auth_send_otp / galaxie_auth_verify_otp).
 * On a verified code, reloads the page rather than managing a client-side
 * transition: the widget re-renders server-side signed in, with fresh
 * profile/address props, and the step machine's initial-step logic (see
 * index.tsx) lands on the right next step.
 */
function EntryStep({ authCfg, text, genericError, onVerified, preview }: EntryStepProps) {
  const [tab, setTab] = React.useState<'otp' | 'register'>('otp')
  const [stage, setStage] = React.useState<Stage>('request')
  const [email, setEmail] = React.useState('')
  const [code, setCode] = React.useState('')
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

  function switchTab(next: 'otp' | 'register') {
    setTab(next)
    setStage('request')
    setError(null)
    setCode('')
  }

  async function sendCode(e: React.FormEvent) {
    e.preventDefault()
    if (preview || !authCfg) return
    if ('register' === tab && '' !== reg.phone && false === phoneValid) {
      setError(BAD_PHONE)
      return
    }
    setBusy(true)
    setError(null)

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
      <form onSubmit={verifyCode} className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">
          {text.codeHint.split('%s').map((part, i, all) => (
            <React.Fragment key={i}>
              {part}
              {i < all.length - 1 && <strong className="font-semibold text-foreground">{email}</strong>}
            </React.Fragment>
          ))}
        </p>
        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        <OtpInput value={code} onChange={setCode} autoFocus />
        <Button type="submit" size="lg" disabled={busy || 6 !== code.length}>
          {text.confirmCode}
        </Button>
        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
          <button type="button" onClick={sendCode} disabled={busy} className="text-muted-foreground underline underline-offset-4 hover:text-foreground">
            {text.resendCode}
          </button>
          <button
            type="button"
            onClick={() => {
              setStage('request')
              setError(null)
            }}
            className="text-muted-foreground underline underline-offset-4 hover:text-foreground"
          >
            {text.changeEmail}
          </button>
        </div>
      </form>
    )
  }

  const emailField = (
    <Field label={text.email}>
      <Input type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
    </Field>
  )

  return (
    <div className="flex flex-col gap-5">
      {text.entryIntro && <p className="text-sm text-muted-foreground">{text.entryIntro}</p>}

      <Tabs value={tab} onValueChange={(v) => switchTab(v as 'otp' | 'register')} className="gap-5">
        <TabsList className="h-11 w-full">
          <TabsTrigger value="otp" className="h-full whitespace-normal leading-tight">
            {text.tabLogin}
          </TabsTrigger>
          <TabsTrigger value="register" className="h-full whitespace-normal leading-tight">
            {text.tabRegister}
          </TabsTrigger>
        </TabsList>

        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}

        <TabsContent value="otp">
          <form onSubmit={sendCode} className="flex flex-col gap-4">
            {emailField}
            <Button type="submit" size="lg" disabled={busy}>
              {text.sendCode}
            </Button>
          </form>
        </TabsContent>

        <TabsContent value="register">
          <form onSubmit={sendCode} className="flex flex-col gap-4">
            {emailField}
            <div className="grid grid-cols-1 gap-4 @[420px]:grid-cols-2">
              <Field label={text.firstName}>
                <Input required autoComplete="given-name" value={reg.first_name} onChange={(e) => setReg({ ...reg, first_name: e.target.value })} />
              </Field>
              <Field label={text.lastName}>
                <Input required autoComplete="family-name" value={reg.last_name} onChange={(e) => setReg({ ...reg, last_name: e.target.value })} />
              </Field>
              <Field label={text.birthdate}>
                <Input type="date" value={reg.birthdate} onChange={(e) => setReg({ ...reg, birthdate: e.target.value })} />
              </Field>
              <Field label={text.cpf}>
                <Input inputMode="numeric" value={reg.cpf} onChange={(e) => setReg({ ...reg, cpf: e.target.value })} placeholder="000.000.000-00" />
              </Field>
            </div>
            <Field label={text.phone}>
              <PhoneInput
                value={reg.phone}
                onChange={(phone, valid) => {
                  setReg((prev) => ({ ...prev, phone }))
                  setPhoneValid(valid)
                }}
              />
            </Field>

            <label className="flex items-center justify-between gap-3 rounded-md border border-border p-3 text-sm text-foreground">
              <span>{text.marketing}</span>
              <Switch checked={reg.marketing} onCheckedChange={(v) => setReg({ ...reg, marketing: v })} />
            </label>

            <label className="flex items-start gap-2.5 text-sm text-muted-foreground">
              <input
                type="checkbox"
                required
                checked={reg.terms}
                onChange={(e) => setReg({ ...reg, terms: e.target.checked })}
                className="mt-0.5 size-4 shrink-0 accent-[var(--primary)]"
              />
              <span>{text.terms}</span>
            </label>

            <Button type="submit" size="lg" disabled={busy}>
              {text.registerButton}
            </Button>
          </form>
        </TabsContent>
      </Tabs>
    </div>
  )
}

export { EntryStep }
