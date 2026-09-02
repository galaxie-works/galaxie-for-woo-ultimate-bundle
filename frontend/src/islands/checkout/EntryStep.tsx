import * as React from 'react'

import { Button } from '@/ui/button'
import { Field } from '@/ui/field'
import { Input } from '@/ui/input'
import { OtpInput } from '@/ui/otp-input'
import { Switch } from '@/ui/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/ui/tabs'
import { post } from '@/lib/wp'
import type { ProfileValues } from './types'

interface AjaxEndpoint {
  ajaxUrl: string
  nonce: string
}

interface EntryStepProps {
  authCfg?: AjaxEndpoint
  genericError: string
  onVerified: () => void
}

type Stage = 'request' | 'verify'

/**
 * Passwordless sign-in / registration. Talks directly to the PasswordlessAuth
 * module's AJAX actions (galaxie_auth_send_otp / galaxie_auth_verify_otp).
 * On a verified code, reloads the page rather than managing a client-side
 * transition: the widget re-renders server-side with `loggedIn: true` and
 * fresh profile/address props, and the step machine's initial-step logic
 * (see index.tsx) naturally lands on the right next step. Simpler and more
 * robust than trying to hand-roll that transition — same approach v1 used.
 */
function EntryStep({ authCfg, genericError, onVerified }: EntryStepProps) {
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

  function switchTab(next: 'otp' | 'register') {
    setTab(next)
    setStage('request')
    setError(null)
    setCode('')
  }

  async function sendCode(e: React.FormEvent) {
    e.preventDefault()
    if (!authCfg) return
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
    setStage('verify')
  }

  async function verifyCode(e: React.FormEvent) {
    e.preventDefault()
    if (!authCfg) return
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

  return (
    <div className="mx-auto flex max-w-sm flex-col gap-4">
      <Tabs value={tab} onValueChange={(v) => switchTab(v as 'otp' | 'register')}>
        <TabsList className="w-full">
          <TabsTrigger value="otp" className="flex-1">
            Sign in
          </TabsTrigger>
          <TabsTrigger value="register" className="flex-1">
            Create account
          </TabsTrigger>
        </TabsList>

        {error && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}

        <TabsContent value="otp">
          {'request' === stage ? (
            <form onSubmit={sendCode} className="flex flex-col gap-4">
              <Field label="Email">
                <Input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
              </Field>
              <Button type="submit" disabled={busy}>
                Send me a code
              </Button>
            </form>
          ) : (
            <VerifyPanel code={code} setCode={setCode} busy={busy} onSubmit={verifyCode} onResend={sendCode} />
          )}
        </TabsContent>

        <TabsContent value="register">
          {'request' === stage ? (
            <form onSubmit={sendCode} className="flex flex-col gap-4">
              <Field label="Email">
                <Input type="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
              </Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label="First name">
                  <Input required value={reg.first_name} onChange={(e) => setReg({ ...reg, first_name: e.target.value })} />
                </Field>
                <Field label="Last name">
                  <Input required value={reg.last_name} onChange={(e) => setReg({ ...reg, last_name: e.target.value })} />
                </Field>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Date of birth">
                  <Input type="date" value={reg.birthdate} onChange={(e) => setReg({ ...reg, birthdate: e.target.value })} />
                </Field>
                <Field label="CPF">
                  <Input value={reg.cpf} onChange={(e) => setReg({ ...reg, cpf: e.target.value })} placeholder="000.000.000-00" />
                </Field>
              </div>
              <Field label="Phone">
                <Input type="tel" value={reg.phone} onChange={(e) => setReg({ ...reg, phone: e.target.value })} />
              </Field>

              <div className="flex items-center justify-between gap-3 rounded-md border border-border p-3">
                <span className="text-sm">Send me offers and news</span>
                <Switch checked={reg.marketing} onCheckedChange={(v) => setReg({ ...reg, marketing: v })} />
              </div>

              <label className="flex items-start gap-2 text-sm text-muted-foreground">
                <input
                  type="checkbox"
                  required
                  checked={reg.terms}
                  onChange={(e) => setReg({ ...reg, terms: e.target.checked })}
                  className="mt-0.5"
                />
                I agree to the terms and privacy policy.
              </label>

              <Button type="submit" disabled={busy}>
                Create account
              </Button>
            </form>
          ) : (
            <VerifyPanel code={code} setCode={setCode} busy={busy} onSubmit={verifyCode} onResend={sendCode} />
          )}
        </TabsContent>
      </Tabs>
    </div>
  )
}

function VerifyPanel({
  code,
  setCode,
  busy,
  onSubmit,
  onResend,
}: {
  code: string
  setCode: (v: string) => void
  busy: boolean
  onSubmit: (e: React.FormEvent) => void
  onResend: (e: React.FormEvent) => void
}) {
  return (
    <form onSubmit={onSubmit} className="flex flex-col items-start gap-4">
      <p className="text-sm text-muted-foreground">Enter the 6-digit code we emailed you.</p>
      <OtpInput value={code} onChange={setCode} autoFocus />
      <Button type="submit" disabled={busy || 6 !== code.length}>
        Confirm code
      </Button>
      <button type="button" onClick={onResend} className="text-sm text-muted-foreground underline underline-offset-2">
        Resend code
      </button>
    </form>
  )
}

export { EntryStep }
