import * as React from 'react'

import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/ui/tabs'
import { getGalaxieConfig, post } from '@/lib/wp'
import { AccountTab } from './AccountTab'
import { CommunicationTab } from './CommunicationTab'
import { DetailsTab } from './DetailsTab'
import { InterestsTab } from './InterestsTab'
import type { DetailsValues, MyAccountProps } from './types'

/** The "Account details" endpoint's custom tabs — Dados pessoais / Interesses / Comunicação / Conta. */
function MyAccount(props: MyAccountProps) {
  const cfg = getGalaxieConfig()

  const [values, setValues] = React.useState<DetailsValues>({
    first_name: props.values.first_name,
    last_name: props.values.last_name,
    social_name: props.values.social_name,
    birthdate: props.values.birthdate,
    cpf: props.values.cpf,
    gender: props.values.gender,
  })
  const [busy, setBusy] = React.useState(false)
  const [notice, setNotice] = React.useState<string | null>(null)

  async function handleSaveDetails(next: DetailsValues) {
    if (!cfg.myAccount) return
    setBusy(true)
    setNotice(null)
    const res = await post(cfg.myAccount.ajaxUrl, 'galaxie_myaccount_save_details', cfg.myAccount.nonce, next)
    setBusy(false)
    if (!res.success) {
      setNotice(res.data?.message ?? props.i18n.genericError)
      return
    }
    setValues(next)
    setNotice('Saved.')
  }

  return (
    <Tabs defaultValue="dados">
      <TabsList>
        <TabsTrigger value="dados">Dados pessoais</TabsTrigger>
        {props.interests.enabled && <TabsTrigger value="interesses">Interesses</TabsTrigger>}
        <TabsTrigger value="comunicacao">Comunicação</TabsTrigger>
        {props.accountDeletionEnabled && <TabsTrigger value="conta">Conta</TabsTrigger>}
      </TabsList>

      {notice && <p className="mt-3 text-sm text-muted-foreground">{notice}</p>}

      <TabsContent value="dados" className="pt-4">
        <DetailsTab initial={values} email={props.values.email} genderOptions={props.genderOptions} busy={busy} onSave={handleSaveDetails} />
      </TabsContent>

      {props.interests.enabled && (
        <TabsContent value="interesses" className="pt-4">
          <InterestsTab
            options={props.interests.options}
            initialSelected={props.interests.selected}
            cfg={cfg.myAccount}
            genericError={props.i18n.genericError}
          />
        </TabsContent>
      )}

      <TabsContent value="comunicacao" className="pt-4">
        <CommunicationTab initialOptedIn={props.communication.optedIn} cfg={cfg.myAccount} genericError={props.i18n.genericError} />
      </TabsContent>

      {props.accountDeletionEnabled && (
        <TabsContent value="conta" className="pt-4">
          <AccountTab cfg={cfg.accountDeletion} i18n={props.i18n} />
        </TabsContent>
      )}
    </Tabs>
  )
}

export { MyAccount }
