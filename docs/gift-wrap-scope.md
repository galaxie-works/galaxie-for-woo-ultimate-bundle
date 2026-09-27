# Gift Wrap module — scope

Plugin: Galaxie Ultimate Bundle, repo `G:\galaxie_development\woocommerce` (GitHub `galaxie-works/galaxie-for-woo-ultimate-bundle`), base branch `main`.
Site for testing: `test.eirnaturals.shop` (pixfort theme + Elementor, WooCommerce block checkout, LiteSpeed).

## Goal
A shopper marks a candle as a gift on the product page and, in a pixfort popup, builds the gift: which candles go in which gift box, plus ribbons and cards with a message. Every accessory is optional; the shopper can skip them and the order is only tagged as a gift.

## Facts about the store
- Candles are variable products; the size is the global attribute `pa_peso` (terms `50g`, `190g`).
- Every candle variation has WooCommerce dimensions (L × W × H cm): 50g = 5 × 5 × 6.5, 190g = 8 × 8 × 8.5. Every candle already ships in its own small box; the gift box is an extra.
- Accessories (gift boxes, ribbons, cards) will be ordinary WooCommerce products in merchant-chosen categories. The merchant creates them; **agents never create products or change store configuration.**
- Gift boxes are variable products by box size; each variation holds internal dimensions and an optional max candle count.
- Wishlist "gift from a shared list" already exists (`src/Modules/Wishlist/Gifts.php`, order meta `_galaxie_gift_owner`, `_galaxie_gift_list`). It must get the same "Presente" tag.

## Module
`src/Modules/GiftWrap/Module.php`, registered in `src/Core/Plugin.php::register_modules()`, with its own on/off toggle and settings tab (see the Wishlist module's `settings_fields`, `admin.php?page=galaxie-woo&tab=...`). Every module needs its own admin toggle.

Settings (plugin settings page in wp-admin):
- Candle size attribute (default `pa_peso`).
- Packing gap in cm (default 0.5) — paper filling around candles.
- Stacking allowed (default off; candles stand upright in glass).
- Accessory categories: boxes, ribbons, cards (these can also be overridden in the builder widget).
- Card message max characters (default 200).

## Phase 1 — mark as gift (no products needed)
1. **Buy Box section "Presente"** in `src/Modules/VariationSwatches/Widget/BuyBoxWidget.php` (next to `register_gift_section`, which is the *wishlist* gift notice — keep them distinct):
   - Toggle in Content to enable it; checkbox "Estou comprando um presente para alguém"; button "Configurar presente" shown only when checked; pixfort styling controls for both (use `Support\PixfortControls`, match the widget's existing sections); popup selector (pixfort popup post id — pixfort marks popup buttons with class `pix-popup-link`; find in `C:\Users\consa\Downloads\Compare\pixfort` how a popup is opened, incl. programmatically).
   - Summary line under the checkbox after configuring.
   - With the box checked, "Adicionar ao carrinho" / "Comprar agora" open the builder popup first instead of adding; the popup's confirm or bypass then continues the original action (add to cart, or Buy Now redirect).
   - Editor preview switch to show the checked state.
2. **Builder widget skeleton** "Galaxie Gift Builder" (to be placed inside a pixfort popup): in phase 1 it shows the candle being gifted and two buttons: confirm and **"Seguir sem incrementar o presente"** (bypass). Both continue the pending action. pixfort style controls.
3. **Gift flag through cart and order**: cart item data `galaxie_gift_wrap` (flag + later the gift group), shown in cart/checkout item meta ("Presente"), copied to the order line item and order meta `_galaxie_is_gift = yes`.
4. **"Presente" tag in wp-admin**: badge in the order detail header and a column/badge in the orders list (HPOS and legacy). Also set it for wishlist gift orders (`Gifts.php::address_order`).
5. Store API / block checkout: the flag must survive the block cart and checkout.

## Phase 2 — boxes, fitting, accessories
1. **Box variation fields** (wp-admin product edit, variation panel): internal length/width/height (cm), optional max candles; a live read-only "Cabe:" preview listing representative fits (e.g. `4 × 50g · 1 × 190g · 2 × 50g + 1 × 190g`).
2. **Packing engine** `src/Support/GiftPacking.php` + a TypeScript twin `frontend/src/lib/gift-packing.ts` with identical results:
   - `fits(box, candles[]) : bool` — candles upright, footprint = L×W (+gap), height + gap ≤ box height (stacking off), 2D rectangle packing on the box floor; exact search for small counts (≤ 12 items), rotation allowed; respect max count.
   - `arrange(candles[], boxes[]) : Gift[]` — fewest boxes, then lowest total price; deterministic.
   - `room(box, candles[]) : sizes that still fit` — for "cabe mais 1 × 50g".
   - Unit tests (PHPUnit if present, else a plain PHP test script) and Vitest/tsx tests sharing the same JSON fixtures.
3. **Builder widget, full**: candles = this product's pending selection + gift candles already in the cart; "Organizar automaticamente"; one card per box with fill bar and "cabe mais…"; box choice limited to boxes that fit; ribbons and cards per box, more than one allowed (quantity), card message textarea with limit; running total; confirm / bypass.
4. **Cart grouping**: accessories added as real cart items linked to their gift group (`galaxie_gift_group` id); candles carry the same group id. Removing a candle re-validates the group (drop the box if it no longer fits → ask/auto-rearrange); removing all candles removes the group's accessories. Quantities of linked accessories are not editable on their own.
5. **Order and e-mails**: packing summary per box (candles, box, ribbons, cards + message) in the admin order and the admin/customer e-mails.
6. Accessories hidden from the catalogue: set/advise catalog visibility "hidden" (do not change products automatically without merchant action — provide a note in settings).

## Phase 3 — manual assembly
- "Mover para Caixa N" per candle in the builder.
- A cart-page widget to edit existing gifts.

## Rules for every agent
- Own git worktree under `G:\galaxie_development\wt-<name>` on its own branch from `origin/main`; commit with clear messages ending with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`; push and open a PR to `main` whose body ends with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`; comment `@codex review`.
- Frontend: pnpm only (`pnpm install --frozen-lockfile`, `pnpm run typecheck`, `pnpm run build`), `assets/dist` is committed; `php -l` every PHP file touched.
- Match surrounding code style and comment density; escape all output; nonces on AJAX; strings in Portuguese defaults, English labels with `galaxie-woo` text domain.
- Do NOT deploy, merge, create WooCommerce products, change store settings, place orders or log into wp-admin.
