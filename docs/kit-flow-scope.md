# Gift Kit flow — scope (PR #21)

Replaces the Phase 1/2 "this is a gift" checkbox + one-shot builder with a **kit** flow. Decided with the merchant on 2026-09-17 (grill session). Built on top of the stack: `feat/settings-rest-api` (#20), which contains #15 → #17 → #18 → #19. Earlier decisions: `G:\galaxie_development\gift-wrap-scope.md`.

## Kept from the stack
- Packing engine (#17): candles lying, gap 0, box overflow, gift dims per size term (pa_peso 104 "50g" 5.3×5.3×6.7, 105 "190g" 7.8×7.8×8.4).
- Cart gift groups, quantity locks, order line meta, "Montagem dos presentes" in order/e-mails, "Presente" badge (#18, #15).
- Role separation: box = product with `_galaxie_box_*`; card = other product in card categories; ribbons optional (category empty on test).
- Shipping Cartons (#19): a kit's box is one rigid item, candles/cards add weight only.
- REST API + boot smoke test (#20). Run `php tests/boot/run.php` before every push.

## Decisions
1. **Draft kit outside the cart.** The kit being built (name, box, card + message, candles with quantities) lives in the WooCommerce session and, for logged-in users, in user meta. Nothing enters the cart until "Adicionar kit ao carrinho". The draft is then converted into a normal cart gift group, using the existing #18 add path with server-side validation of fit, stock and card match.
2. **A box is mandatory.** No "Sem caixa" in the kit flow.
3. **Plain "this is a gift" moves to checkout** as "Este pedido é um presente" (order-level badge only). That is a separate, later PR, together with the consent checkbox; not part of #21. The Buy Box checkbox and "Seguir sem incrementar o presente" are removed.
4. **The selected candle starts the kit.** "Montar um kit ou presente" on a product page, with a valid variation and quantity, opens the stepper. Behaviour:
   - It skips the welcome screen, with "Começando com: 2 × Nordic Moss 190g" at the top.
   - The box step only enables boxes that hold those candles; others are disabled with "Não comporta …".
   - If the quantity fits no box, say so and suggest lowering it.
   - After the card step the candles are in the draft.
5. **One draft at a time.**
   - Kits in the cart are closed, with an "Editar kit" link in cart and checkout.
   - "Editar kit" moves that kit back into the draft: its lines leave the cart, and name, box, card and message are kept. If another draft is open, first ask "Adicionar o kit atual ao carrinho e editar este?".
   - Removing the last candle of a cart kit removes its box and card (existing behaviour).
6. **Adding to the cart needs ≥ 1 candle.**
   - Not full: "Ainda cabem …", with "Adicionar kit ao carrinho" as a secondary button.
   - Full: confetti, "Caixa completa! 🎉", and the add button becomes primary.
7. **One Buy Box button that changes its label.**
   - No draft: "Montar um kit ou presente". With a draft: "Adicionar ao kit {kit}". Both texts are configurable, and the button can be enabled or disabled in the widget.
   - No valid variation: the same Buy Box alert as Add to Cart (`missingChoice`/`blockedByChoice` from buy-box-alert.ts).
   - Valid variation and room left: add to the draft, update the badge and widget, show a short toast ("Adicionada ao kit. Ainda cabem …").
   - Candle doesn't fit: the button is disabled with "Não cabe na caixa deste kit"; the quantity is capped to what fits.
   - Products without the size attribute: no button.
   - Product page only for now (no loop cards).
8. **Launcher, badge and popup contents.**
   - The badge counts candles in the draft; no badge without a draft. Kits in the cart don't count.
   - Opening with a draft shows the kit summary:
     - editable name; box and card with "trocar" (box change only offers boxes that still hold the candles; the card follows the box size);
     - the message;
     - candle list with −/+ and remove;
     - fill bar with "Ainda cabem …" and the total;
     - actions: Adicionar kit ao carrinho, Continuar escolhendo (closes the popup), Adicionar ao carrinho e começar um novo, Descartar kit (with confirmation).
   - Opening without a draft shows the welcome screen with "Montar um kit".
9. **Combination wording** (engine-computed, current draft contents subtracted):
   - Single-size maxima first, e.g. "Ainda cabem 2 × 190g ou 4 × 50g".
   - Then at most 2 mixed combinations, the fullest first: "ou 1 × 190g + 2 × 50g".
   - Only one fits: "Ainda cabe 1 × 50g". Full: "Caixa completa! 🎉".
   - The box description uses the same format for an empty box: "Leva até …".
   - Texts are configurable with `{combos}` / `{kit}` / `{preço}` placeholders.
10. **Launcher handled by the plugin.**
    - Galaxie → Gift Wrap gets "Popup do kit" (pixfort link `#pix_popup_4549`, bare id or URL). The Buy Box no longer has its own popup field; read the old Buy Box value as a fallback once.
    - Toggles: "Ícone de presente no launcher" and "Badge com a quantidade", plus badge colours and size.
    - The plugin sets a gift icon on `#pix_launcher_{id}`, keeps `data-count` updated and ships the badge CSS; the popup's own Custom CSS can still override.
    - The launcher stays available on every page.
11. **New Elementor widget "Galaxie Kit Progress"**, modelled on `Cart/Widget/FreeShippingProgressWidget.php`: bar, text with placeholders, confetti when full, pixfort style controls.
    - Placeable anywhere; updates live without reloading.
    - Hidden without a draft by default; an option shows an invitation ("Monte um kit de presente") with a button that opens the popup.
    - With a draft: "{kit} · Ainda cabem …" plus a "Ver kit" link.
    - Full: confetti and a primary "Adicionar kit ao carrinho".
12. **Cards:** 0 or 1 per kit. The card step appears for every new kit:
    - "Adicionar cartão (R$ 2,00)" with a message field (limit from settings, with a counter), or "Seguir sem cartão".
    - An empty message is allowed; the summary warns "Cartão sem mensagem".
    - The card size follows the box by attribute value, and the message is kept when the box changes.
13. **Kit name:** optional to type, always set.
    - Placeholder "Ex.: Presente para Stella"; max 40 chars; empty → "Kit 1", "Kit 2"… by order.
    - Shown in the badge/widget, the Buy Box label, the cart and checkout group title (replacing "Presente N"), order, e-mails, "Montagem dos presentes" and the order's "Caixas de envio" box.
    - Editable in the summary and via "Editar kit".
14. **Delivery:** PR #21 on top of `feat/settings-rest-api`. It removes the Buy Box checkbox, the old builder screen and the "Configurar presente" handler. The stack then merges in order #15 → #17 → #18 → #19 → #20 → #21 after merchant testing.
15. **Guest draft at login:**
    - The current (guest) draft wins.
    - The account's older draft goes to the cart as a closed kit if it has ≥ 1 candle, with a notice: "Encontramos o kit {kit} que você começou antes e colocamos no carrinho. Você pode editar ou remover." Empty old drafts are discarded silently.
    - Guest draft lifetime = WooCommerce session. The account draft is kept until added or discarded.
16. **Box step preview:**
    - Image from the variation (fallback: product).
    - The merchant's variation description, then the automatic combination line.
    - With candles in the draft, show the remaining combinations. Boxes that can't hold the current candles are disabled with a reason.
17. **One popup widget "Galaxie Kit Builder"** (replaces Galaxie Gift Builder, same registration slot):
    - Content has a section per screen (welcome, name, box, card, continue, summary) with texts and icons, and "Tela mostrada no editor" at the top. The editor draws the chosen screen from real store products.
    - Style has shared sections:
      - step indicator (1•2•3•4, can be switched off);
      - titles and text;
      - box cards and the selected state;
      - name and message fields;
      - fill bar;
      - Primary / Secondary / Danger buttons, each with full pixfort button controls;
      - welcome image control.
    - One control per property; no duplicates (texts in Content, looks in Style).

## Stepper
Welcome → 1 Nome → 2 Caixa → 3 Cartão → 4 "Continue escolhendo". Screen 4 shows "Você ainda pode adicionar {combos}. Continue pesquisando nossos produtos e adicionando a este kit." with "Continuar escolhendo", which closes the popup and goes to the shop page (setting: URL, default the WooCommerce shop page). Back and Next buttons; Next is disabled until the step is valid (a box is chosen).

## Server
- Nonce-protected AJAX (fresh nonce from the uncached endpoint, as in #18) for draft CRUD:
  - get, start, rename, set_box, set_card, set_message;
  - add_candle, update_candle, remove_candle;
  - discard, to_cart, to_cart_and_new, edit_from_cart.
- Every mutation is validated with the engine, stock and caps (no unbounded input).
- Draft stored in `WC()->session` key `galaxie_kit_draft`, mirrored to user meta `_galaxie_kit_draft` for logged-in users, and merged at login per decision 15.
- LiteSpeed: keep draft endpoints uncached, and never print draft state into cacheable HTML. The badge and the Buy Box label are filled by JS from the draft endpoint.

## Tests
- Boot smoke test extended with the kit module/widgets.
- Draft transitions: start, add until full, cap, swap box (only boxes that fit), card size follow, discard, to_cart, to_cart_and_new, edit_from_cart with and without an open draft, login merge.
- Combination wording (single sizes, ≤ 2 mixes, full, one-only) in PHP and TS with shared fixtures.
- Name defaulting ("Kit N") and the 40-char cap.
