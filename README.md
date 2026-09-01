# Galaxie for WooCommerce — Ultimate Bundle

Single WordPress plugin (a monorepo of internal modules) that replaces WooCommerce's
native storefront UX with a polished, **theme-independent** experience. Each capability
is a **module with its own on/off toggle**, and the storefront pieces are shipped as
**Elementor widgets** (plus importable Elementor templates) rather than depending on any
theme's markup.

This is the v2 successor to `eir-my-account-ux`. The goal of the rewrite is to move the
UX off the XStore theme: the *logic* (auth, FluentCRM, Google) was always theme-free; the
*mounting points* (checkout DOM, wishlist, styling) are being re-owned here so the site can
one day drop XStore without redoing the engine.

## Architecture

```
galaxie-bundle.php            Plugin bootstrap: constants, PSR-4 autoloader, boot Core\Plugin
src/
  Core/                       Module contract, registry, settings/toggles, admin page
  Elementor/                  Widget category + registration (collected from enabled modules)
  Integrations/               Shared services used by modules
    FluentCRM/                Contact sync, tags/lists, interests, order tags
    Google/                   OAuth sign-in + Maps/Places
  Support/                    Cross-cutting helpers (CPF, profile fields, intl-tel-input)
  Modules/                    One folder per toggleable module
    PasswordlessAuth/         Email-OTP login/register
    Checkout/                 "Galaxie Checkout" Elementor widget (the stepper)
    MyAccount/                "Galaxie My Account" Elementor widget (tabs, addresses)
    Cart/                     "Galaxie Cart" Elementor widget (shipping relocation)
    Wishlist/                 Own wishlist (replaces XStore's add-on)
    AccountDeletion/          Soft-delete + purge cron
    ToastNotices/             WooCommerce notices → toasts
templates/                    Importable Elementor templates (checkout, my-account, cart)
theme-adapters/xstore/        Quarantine: XStore-only quirk CSS, shrinks as pages get widgetized
```

## Modules

Every module implements `Galaxie\Woo\Core\Module` and is registered in
`Core\Plugin::register_modules()`. Enabled state lives in the single option
`galaxie_woo_modules`; toggle them under **wp-admin → Galaxie → Modules**. Modules that
provide Elementor widgets also implement `Core\ProvidesElementorWidgets`.

## Requirements

- WordPress ≥ 6.4, PHP ≥ 8.0, WooCommerce.
- Elementor (Pro) for the storefront widgets.

## Deploy

Deployed as a plain folder via the Hostinger MCP `hosting_deployWordpressPlugin`
(domain `eirnaturals.shop`, slug `galaxie-for-woo-ultimate-bundle`). The runtime uses a
hand-rolled autoloader, so **no `composer install` is required on the server**; `composer.json`
exists for local dev only.

## Secrets

No credentials in source. Integration secrets (Google OAuth client secret, Maps API key,
etc.) are read from `wp-config.php` constants / environment — never hardcoded, never committed.
