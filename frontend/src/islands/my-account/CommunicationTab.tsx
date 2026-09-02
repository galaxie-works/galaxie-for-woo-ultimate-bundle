import * as React from 'react'

import { Switch } from '@/ui/switch'
import { post } from '@/lib/wp'

interface AjaxEndpoint {
  ajaxUrl: string
  nonce: string
}

interface CommunicationTabProps {
  initialOptedIn: boolean
  cfg?: AjaxEndpoint
  genericError: string
}

function CommunicationTab({ initialOptedIn, cfg, genericError }: CommunicationTabProps) {
  const [optedIn, setOptedIn] = React.useState(initialOptedIn)
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)

  async function handleChange(next: boolean) {
    if (!cfg || busy) return
    setOptedIn(next)
    setBusy(true)
    setError(null)
    const res = await post(cfg.ajaxUrl, 'galaxie_myaccount_save_communication', cfg.nonce, { opt_in: next ? '1' : '' })
    setBusy(false)
    if (!res.success) {
      setOptedIn(!next)
      setError(res.data?.message ?? genericError)
    }
  }

  return (
    <div className="flex max-w-lg flex-col gap-4">
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      <div className="flex items-center justify-between gap-6 rounded-md border border-border p-4">
        <div>
          <p className="text-sm font-medium">Offers and news</p>
          <p className="text-sm text-muted-foreground">Occasional emails about new products and promotions.</p>
        </div>
        <Switch checked={optedIn} disabled={busy} onCheckedChange={handleChange} />
      </div>
    </div>
  )
}

export { CommunicationTab }
