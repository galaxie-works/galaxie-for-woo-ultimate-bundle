# Backlog

What is decided but not built, and what was left undone on purpose. Written
2026-09-24, at the end of the My Account dashboard work.

This file is development material: `.gitattributes` marks `docs/` export-ignore,
so it never reaches a built plugin.

---

## My Account · Painel

The dashboard is composed of widgets. Five are in place and default into the
screen (`AccountEndpoints::DEFAULT_WIDGETS['dashboard']`): the greeting, the kit
card, the orders waiting for payment, the recent orders and the shipping
address. Three more have a short mode a merchant can drop in: the wishlist, the
default saved card and the interests line.

These four are what the list still owes.

### 1. Buy it again, from the last order

Rebuild a cart from an order the customer already received: one button, one
confirmation, the cart filled.

Not a widget on its own — the work is in the middle:

- a product may be gone, unpublished or out of stock;
- a variation may no longer exist, or its attributes may have changed;
- the price today is not the price paid, and the card must not pretend it is;
- a kit line is a group, not a product (see `Groups`), so repeating an order
  that carried one means rebuilding the kit, box and card included, or saying
  plainly that it cannot be repeated.

Cheapest honest first cut: repeat only the lines that are still purchasable at
today's price, say how many were skipped and why, and refuse an order that
carried a kit until the kit path is written.

Where it goes: a button on `galaxie-account-order` (the order screen) and on the
order cards of `galaxie-account-orders`, plus an AJAX action beside the kit's in
`Kit\Ajax`-style — nonce, capability, `wc_add_to_cart_validation` per line.

### 2. Shipping tracking

We have nothing of our own. `galaxie-account-order` respects
`woocommerce_order_details_after_order_table`, which is where other plugins
print a tracking code, and that is all the customer sees today.

With Melhor Envio a tracking code exists only after a label is bought, and on
the test store buying labels is forbidden (the token is production's). So this
waits either for a real shipping flow in production, or for a decision to store
a tracking code by hand on the order and show it.

If it is picked up: an order meta key of our own, a field on the admin order
screen, and a line on the order screen and its card — not a carrier API.

### 3. Free shipping progress / quantity tiers on the dashboard

Both read the cart. On a dashboard the cart is usually empty, so the card would
be blank most of the time — a widget that says nothing is worse than no widget.
Still advised against; if it is wanted, it should say something useful with an
empty cart ("frete grátis acima de R$ X") rather than a progress bar at zero.

### 4. Reviews the customer has not written

Products bought and not yet reviewed, with a link to write one. Needs: the
customer's delivered order items, minus the products they have already
commented on (`get_comments` with `comment_type => review` and the customer's
id), an opinion on how far back to look, and a decision on whether reviews are
WooCommerce's own or something the bundle owns (Phase 2 of the plugin scope
lists reviews as its own module).

---

## Elsewhere

- **The brand document is still in the git history.** `docs/eirnaturals-scent-profiles.md`
  was removed from the working tree (and from the deployed plugin) on
  2026-09-21, but every version of it is still reachable in the history of
  `feat/gift-kit`. It only matters if the repository leaves private hands —
  then the history has to be rewritten. The merchant's call.
- **The non-candle path has never run in a real store.** The eligibility gate,
  the "faces" orientation and optional stacking pass their fixtures and the boot
  suite, but the test store sells only candles: no product without the size
  attribute has ever been put in a kit there. Before this is sold as a feature,
  build one simple product with gift measures on a store and take it through a
  kit end to end.
- **The kit audit's "left on purpose" list** (`docs/kit-audit.md`) still stands:
  closing the popup mid-step loses the step without warning, the login-merge
  notice is still a toast, `need_candle` stays as a guard, and disabled box
  cards keep their place in the tab order.
- **Candle names inside the code** (`CandleFields`, `$candle`, the TS types,
  `data-candles`, the fixtures) are internal only; the merchant-visible wording
  is settings-driven since 2026-09-21. Renaming them is cosmetic and touches
  stored keys in places, so it waits for a reason better than tidiness.
