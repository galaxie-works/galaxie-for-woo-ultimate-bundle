# Kit Builder — independent audit, 2026-09-17

Four reviewers read the kit on `feat/gift-kit` with no stake in it: reuse against
the rest of the plugin, the Elementor panel control by control, the shopper's
flow, and an adversarial pass on the packing engine. This is what they found,
merged and ordered. Nothing here is fixed unless it says so.

The packing geometry itself came out clean: rotation-symmetric, monotone, gap and
overflow on the right axes, PHP and TypeScript in lockstep, and right by hand on
every one of the store's own boxes and jars. What is wrong is the data that
reaches it, what it does when it runs out of time, and how honestly that is
reported and cached.

## Status, 2026-09-18

Everything below is done and on `feat/gift-kit` unless a line says otherwise.
Four items were deliberately left, and they are listed at the end.

## Fixed already

- **`store_sizes()` misread `fields => 'id=>parent'`.** WP_Query returns
  `[ variation id => parent id ]`, not rows; read as rows, every variation failed
  the "is its product published?" test, the size list came back empty for every
  store, and with no size the engine answers "holds nothing" for every box — a
  kit with a candle in it read "Caixa completa! 🎉" while the same candle added
  fine through the other path. The test stub had the same misreading, which is
  why 237 packing tests passed over a store answering nothing. Both fixed; the
  stub now returns WP's shape and fails against the old code. Deployed and
  confirmed on test: sizes 50g 5.3×5.3×6.7 and 190g 7.8×7.8×8.4, `big` takes
  3 × 190g or 4 × 50g or two mixes.
- **An empty size list is no longer cached**, read or written, and the catalog
  reports which attribute it used plus counts for each step when it finds none.
- **An empty kit is never "full"**: that state became `nofit` with a sentence of
  its own.

## P0 — the engine tells the truth

1. **A search that gives up says "no".** `place()` stops at `STEP_LIMIT` and
   `fits()` reports that as a definite refusal. It starts at **6 candles**, and
   in ~13 % of realistic three-size queries on the store's own boxes. A shopper
   is refused a kit that would have shipped. The docblock's "9+ candles, square
   footprints" measurement was taken on `upright`; the merchant runs `lying`,
   where a 5.3 × 5.3 × 6.7 jar is a 6.7 × 5.3 footprint. Counter-example:
   23.41 × 8.27 × 9.45 with 4 × (4.89 × 3.07 × 4.68) + 3 × (5.55 × 7.44 × 3.26)
   has an explicit valid layout and is refused.
   → An unproved "no" must be `unknown`, never a refusal, and the limit wants
   raising with the search ordered better.
2. **The 250 ms budget silently deletes mixed combinations** and `wording()`
   cannot tell "no mixes exist" from "not searched": on 24 × 16 × 8 production
   shows three singles, a generous budget adds `4 × 190g + 2 × 100g` and
   `4 × 190g + 3 × 50g`.
3. **Truncated answers are cached as truth** for 24 h for every shopper
   (`HOLDS_TRANSIENT`) and per session (`Store::remember`), with no marker.
   One unlucky slow request degrades the whole store's "Leva até …".
4. **No sizes still means "full"** inside `combos()`/`wording()` — the fixtures
   bless it. Any future data outage becomes "a caixa está completa" again
   instead of "não sei".
5. **`fill_percent` lies at both ends**: 100 % with room left when the smallest
   size fits nowhere, 0 % with candles inside when nothing settled, and jumps
   25 → 67 → 100 for one candle. It has **no TypeScript twin** at all, and it is
   the number driving the bar and the confetti.
6. **`cover()` advertises the widest variation, `add_candle` packs the exact
   one.** Give one 190g variation gift dimensions and another none, and the
   sentence drops 190g entirely while adding it still works — the same
   "two paths disagree" class as the bug above, from a second cause.
7. **`room_within()` throws away the `expired` flag** while `takes()` is
   unbudgeted, so the `+` cap and the refusal number can be lower than what the
   server will actually accept.
8. **The "Stacking allowed" switch in wp-admin does nothing** — both engines
   document it as accepted and ignored. Implement it or remove it.
9. **`combos()` caps at 12 and drops the `capped` flag** `summary()` carries:
   "Leva até 12 × 50g" on a box that takes 17.

## P0 — the two the merchant named

10. **Every confirmation is the unstyled built-in dialog.** `kit_discard`,
    `kit_restore` and `kit_edit` are asked for by the script and registered by
    nobody, so `dialog.ts` builds one with hardcoded "Cancelar" / "OK".
    `Support/Dialog.php` exists and eight other widgets use it. One hour.
    Watch the message: the dialog prefers its own configured text, so `{kit}`
    must be filled or the shopper sees the placeholder.
11. **No step transition.** `go()` swaps `hidden`, i.e. `display: none`, which
    kills any transition by construction. pixfort's own animation system is
    one-shot and has no reduced-motion handling — do not use it; `tw-animate-css`
    is already a dependency, with one trap: a bare `animate-in` is hidden by a
    global pixfort rule, so the class must be a `galaxie-kit-*` of our own.
    Animate `.galaxie-kit-content` only: the frame, the indicator and the buttons
    must not move. Ship it together with the height fix below or it looks worse.

## P1 — the frame and the phone

12. **The popup still resizes**: the new cap stops it growing, nothing stops it
    shrinking. Give the minimum height a default.
13. **`vh` on a phone**: the cap does not shrink when the keyboard opens, and the
    button row lives outside the scroll box — it can end up unreachable. Use
    `dvh`, or no cap below the tablet breakpoint.
14. **The fill bar and the total scroll away** with the candle list, because they
    sit inside the scrollable panel. They are what the summary exists to show.
15. **Five buttons in the summary row** wrap into four or five ragged lines at
    360 px, with no way to stack them.

## P1 — the panel

Measured: ~780 controls registered, ~630 visible. The eleven button sections and
the six per-screen sections are 73 % of that.

16. **~30 dead controls.** "Hover lift" on all 14 kit buttons writes a custom
    property only the Buy Box consumes. `steps_grow` and `steps_justify` fight a
    stylesheet rule that already fixes `flex: 1 1 0`. `content_grow`,
    `kit_distribute` and `kit_min_height` are dead at the default because the
    maximum height already writes their declaration. Margins on screens that do
    not print the element; icon sizes on screens with no icon.
17. **"Align the text" cannot move a title**, because the title's helper
    registers a position default of `text-left` that wins as a class. Seven
    controls in that fight. This is the duplicate a merchant can feel.
18. **35 margin controls for 5 properties** (one global plus six per screen),
    across four sections, and the per-screen one — the hardest to find — wins.
19. **"Style this screen apart" only redirects the title and the hint**: labels,
    counters, warnings and the room line stay global, so on the summary the
    switch looks broken.
20. **"Box cards" secretly styles four things** (boxes, cards, summary rows,
    candle lines) and its "Picture size" ties on specificity with a stylesheet
    rule — the winner depends on load order.
21. **The summary has no section of its own**, though it is the screen most
    shoppers see and the editor's default.
22. **Two scroll mechanisms are active at once** (the list cap and the frame
    cap): a scroll box inside a scroll box.
23. Proposal: gate the six screen sections and the eleven button sections behind
    two multi-selects, the way Kit Progress already gates sections; move the
    welcome image and confetti into Content; add a Celebration section matching
    Kit Progress. ~630 visible → **~245 on open**, without losing anything that
    works.

## P2 — reuse what the plugin already has

24. `PixfortControls::quantity()` for the − / + stepper (its pixfort classes are
    typed into a `sprintf` today, and this is the shop's third spinner).
25. `thumb()` + `thumb_classes()` for the thumbnails, `alert()` for the error and
    warning lines, `surface()` for the fields (with focus and error states, as
    Payment Methods has), the Wishlist's pill pattern for the hint badge and the
    step dots.
26. One `progress()` helper: the same bar is written three times with three
    vocabularies, and only the one inside the popup cannot be rounded.
27. Row icons (trocar, Remover, − / +) have no colour or size control; the class
    is already on the SVG.
28. Six shopper-facing strings are hardcoded in TypeScript, untranslatable.

## P2 — the flow's edges

29. **`card_missing` is computed and never shown**: the summary says "Sem cartão"
    and hides the written message, then the cart refuses saying the card is
    wrong. Reachable whenever the merchant edits a card product.
30. **Two tabs deadlock the stepper**: the second tab's "Criar kit" is refused
    forever with no way out but closing the popup.
31. **A sold-out box or candle at "Criar kit" discards the whole kit** — name,
    box, card and message.
32. **"−" on a candle that went away** says "Este produto não pode entrar num
    kit"; "Remover" works; the line is not marked.
33. **Closing the popup mid-step loses everything, silently.**
34. A kit with zero candles is nearly invisible: no badge, launcher unchanged.

## P2 — copy that does not match the screen

35. Welcome: "Escolha a caixa, um cartão com mensagem **e as velas**" — candles
    cannot be chosen in the builder.
36. Step 4 is labelled **"Velas"** but is the confirmation screen.
37. **"Continuar escolhendo" is two different buttons**: one leaves for the shop,
    one only closes.
38. The summary tells the shopper to "escolha velas na loja" and offers no way to
    get there.
39. `box_none` prints a broken sentence when the shopper arrived with no candle,
    and `room_nofit` reads wrong on a box card.

## Not reproduced / not a problem

- Rotation asymmetry, monotonicity, gap off-by-one, overflow axis, rounding:
  1 500 randomized cases, zero violations.
- PHP ↔ TypeScript parity: 150 randomized cases across fits / room / combos /
  wording / cover, zero differences.
- The popup is printed in the page (not fetched over AJAX), so the stylesheet is
  there the first time it opens.
- Toast handling follows the house pattern.

## Untested branches worth fixtures

- The `STEP_LIMIT` give-up: not one `expect: false` fixture exercises it, and
  only one exercises `bound()`.
- `combos()` producing `complete: false` — the wording fixtures hand-craft it.
- `fill_percent()` — PHP only, no twin.
- `arrange()` is covered at `upright` only, while the store runs `lying`.

## Left on purpose

- **Closing the popup mid-step still loses the step.** Nothing is saved before
  "Criar kit", by the scope's own decision, so there is nothing to restore; what
  is missing is a warning, not a fix. Worth a line on the stepper.
- **The login-merge notice is still a toast.** It says a kit was put in the
  shopper's cart, which deserves to sit on the next screen rather than fade.
- **`need_candle` stays** as the guard behind a disabled button: unreachable by
  pointer, and cheap insurance for every other way in.
- **Disabled box cards keep their place in the tab order**, with `aria-disabled`
  rather than `disabled`, so the reason beneath them can still be read.
