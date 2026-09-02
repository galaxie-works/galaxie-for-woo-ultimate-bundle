import * as React from 'react'

import { Button } from '@/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/ui/dialog'
import { post } from '@/lib/wp'
import type { MyAccountProps } from './types'

interface AjaxEndpoint {
  ajaxUrl: string
  nonce: string
}

interface AccountTabProps {
  cfg?: AjaxEndpoint
  i18n: MyAccountProps['i18n']
}

function AccountTab({ cfg, i18n }: AccountTabProps) {
  const [open, setOpen] = React.useState(false)
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)

  async function confirmDelete() {
    if (!cfg) return
    setBusy(true)
    setError(null)
    const res = await post(cfg.ajaxUrl, 'galaxie_request_account_deletion', cfg.nonce, {})
    if (!res.success) {
      setBusy(false)
      setError(res.data?.message ?? i18n.genericError)
      return
    }
    window.location.href = (res.data as { redirect?: string })?.redirect ?? '/'
  }

  return (
    <div className="flex max-w-lg flex-col gap-4">
      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4">
        <p className="text-sm font-medium text-destructive">Delete account</p>
        <p className="mt-1 text-sm text-muted-foreground">{i18n.deleteModalBody}</p>
        <Button variant="destructive" size="sm" className="mt-3" onClick={() => setOpen(true)}>
          Delete my account
        </Button>
      </div>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{i18n.deleteModalTitle}</DialogTitle>
            <DialogDescription>{i18n.deleteModalBody}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={busy}>
              {i18n.deleteModalCancel}
            </Button>
            <Button variant="destructive" onClick={confirmDelete} disabled={busy}>
              {i18n.deleteModalConfirm}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export { AccountTab }
