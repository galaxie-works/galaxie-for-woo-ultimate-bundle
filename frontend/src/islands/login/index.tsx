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
  /**
   * The visitor is already signed in. Its own flag, and not the message being
   * non-empty: a merchant who clears the message wants nothing drawn at all,
   * and that is a different answer from "not signed in", which one string
   * cannot carry.
   */
  signedIn: boolean
  /** Shown instead of the form to someone already signed in; '' draws nothing. */
  signedInMessage: string
  /** In the editor: the form is drawn but never talks to the server. */
  preview: boolean
}

export function Login(props: LoginProps): React.ReactElement | null {
  const cfg = getGalaxieConfig()

  // Signed in, there is nothing to sign in to. Drawing the form anyway would
  // offer a signed-in visitor to replace their session with another account.
  if (props.signedIn && !props.preview) {
    if (!props.signedInMessage) return null

    // PixAlert reads the pixfort parts from the provider.
    return (
      <div className="gx-co">
        <UiProvider value={props.ui}>
          <PixAlert message={props.signedInMessage} />
        </UiProvider>
      </div>
    )
  }

  return (
    <div className="gx-co">
      <UiProvider value={props.ui}>
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
      </UiProvider>
    </div>
  )
}
