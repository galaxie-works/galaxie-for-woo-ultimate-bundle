import * as React from 'react'

import { getGalaxieConfig } from '@/lib/wp'
import { UiProvider, PixAlert, type PixUi } from '@/lib/pix'
import { OtpLogin, type LoginText } from './OtpLogin'

/**
 * The "Galaxie Login" widget's island: the same sign-in the checkout's first
 * step draws, on its own. The widget hands over the texts, the pixfort parts
 * and where to go once the code is right; everything else is OtpLogin's.
 */
interface LoginProps {
  text: LoginText
  ui: PixUi
  genericError: string
  /** Where a verified code lands: '' reloads the page. */
  redirect: string
  /** Shown instead of the form when the visitor is already signed in; '' draws nothing. */
  signedIn: string
  /** In the editor: the form is drawn but never talks to the server. */
  preview: boolean
}

export function Login(props: LoginProps): React.ReactElement | null {
  const cfg = getGalaxieConfig()

  // PixAlert reads the pixfort parts from the provider, so both branches sit
  // inside it.
  return (
    <div className="gx-co">
      <UiProvider value={props.ui}>
        {props.signedIn && !props.preview ? (
          <PixAlert message={props.signedIn} />
        ) : (
        <OtpLogin
          authCfg={cfg.auth}
          text={props.text}
          genericError={props.genericError}
          onVerified={() => {
            // A reload is what the checkout does, and it is what keeps the
            // rest of the page (the account screen, the menu, the cart count)
            // honest: everything server-rendered is signed out until it runs.
            if (props.redirect) window.location.assign(props.redirect)
            else window.location.reload()
          }}
          preview={props.preview}
        />
        )}
      </UiProvider>
    </div>
  )
}
