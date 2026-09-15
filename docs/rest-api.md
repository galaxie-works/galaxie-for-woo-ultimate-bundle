# REST API and WP-CLI

The Galaxie settings page (wp-admin → Galaxie) and the gift packing sizes on
product variations, without wp-admin. Everything here saves through the same
code as the settings page and the variation panel, so values are cleaned the
same way and the same caches are cleared.

## Authentication

Standard WordPress REST authentication:

- **Application Password**: create one for the user in wp-admin → Users →
  Profile → Application Passwords, and send it as HTTP Basic auth. The examples
  below use the placeholder `USER:APP_PASSWORD`. Never commit or paste a real one.
- **Cookie**: from a logged-in browser, with the `X-WP-Nonce` header set to a
  `wp_rest` nonce.

```sh
SITE=https://your-store.example
AUTH='USER:APP_PASSWORD'
```

| Routes | Capability |
| --- | --- |
| `galaxie-woo/v1/*` | `manage_woocommerce` (same as the settings page) |
| product and variation meta | `edit_product` on that product or variation |

## Settings routes: `galaxie-woo/v1`

| Method | Route | Body | Returns |
| --- | --- | --- | --- |
| `GET` | `/modules` | none | `[{ id, label, description, enabled, configurable }]` |
| `PUT` / `PATCH` | `/modules/{id}` | `{ "enabled": true }` | that module's row |
| `GET` | `/settings/{module}` | none | `{ module, label, enabled, values, schema }` |
| `PUT` / `PATCH` | `/settings/{module}` | `{ "key": value, ... }` | same as `GET`, after saving |

- A module toggle takes effect from the next request, as on the settings page.
- `PUT` and `PATCH` both change **only the keys sent**. Everything else keeps its
  saved value (or its default, if it was never saved).
- A row setting (`cartons`, `intervals`, `steps`) is a JSON array of objects,
  and sending it replaces the whole list.
- Values are cleaned exactly as the settings page cleans them. An
  out-of-range number is clamped and an unknown choice falls back to the
  default; the request is not refused. Check `values` in the response to see
  what was kept.
- `schema` describes each key: `type` (`text`, `password`, `number`, `toggle`,
  `select`, `multiselect` or `rows`), `json_type`, `default`, `min` / `max` /
  `step`, `options` (`[{ value, label }]`) and, for rows, `items.properties`.
- Passwords are write-only: `values` shows them as `""`, and `schema.<key>.has_value`
  says whether one is saved. Sending `""` keeps the saved one.

### Errors

Nothing is saved when a request is refused.

| Status | Code | When |
| --- | --- | --- |
| 400 | `galaxie_unknown_settings` | a key is not a setting of that module; `data.unknown_keys` lists them, `data.allowed_keys` lists the valid ones |
| 400 | `galaxie_invalid_settings` | a value has the wrong shape (a list for a number, text for a toggle, rows that are not objects); `data.invalid_keys` |
| 400 | `galaxie_empty_settings` | no settings in the body |
| 404 | `galaxie_unknown_module` | no such module, or it has no settings tab |
| 409 | `galaxie_module_disabled` | the module is off; enable it first |
| 409 | `galaxie_settings_form_only` | saving would change something only the wp-admin tab can edit; `data.form_only_keys` |
| 401 / 403 | `rest_forbidden` | not logged in, or missing the capability |

Settings only a module's own wp-admin markup can edit are not reachable over
the API: the My Account screens table, FluentCRM's tag/list mapping and
interests, and Free Shipping's "switch off WooCommerce Free shipping" action.
Only a module's declared fields, plus the row settings of Shipping Cartons and
Quantity Discounts, can be written.

### Enable Shipping Cartons

```sh
curl -u "$AUTH" -X PUT "$SITE/wp-json/galaxie-woo/v1/modules/shipping-cartons" \
  -H 'Content-Type: application/json' \
  -d '{ "enabled": true }'
```

### Set the carton table

Inside measures in cm. An outside measure left out is taken as the inside
measure + 0.6 cm. Weights are in g; `max_load` left out is 30000.

```sh
curl -u "$AUTH" -X PATCH "$SITE/wp-json/galaxie-woo/v1/settings/shipping-cartons" \
  -H 'Content-Type: application/json' \
  -d '{
    "cartons": [
      { "code": "N12", "name": "Caixa 12", "length": 12, "width": 12, "height": 12, "empty_weight": 90, "active": true },
      { "code": "N20", "name": "Caixa 20", "length": 20, "width": 20, "height": 15, "outer_length": 20.8, "outer_width": 20.8, "outer_height": 15.8, "empty_weight": 180, "max_load": 10000, "active": true }
    ],
    "margin": 1.5,
    "fallback": "split"
  }'
```

Saving clears WooCommerce's cached shipping rates, as the settings page does.
The Melhor Envio plugin's own per-shopper session quotes still expire on their
own (up to 15 minutes).

Read it back, with the schema:

```sh
curl -u "$AUTH" "$SITE/wp-json/galaxie-woo/v1/settings/shipping-cartons"
```

## Gift packing sizes on products

These need the Gift Wrap module enabled, as the variation panel fields do.

| Meta key | Type | Meaning |
| --- | --- | --- |
| `_galaxie_box_length`, `_galaxie_box_width`, `_galaxie_box_height` | number ≥ 0 | gift box **internal** size, cm |
| `_galaxie_box_max` | integer ≥ 0 | most candles in the box; 0 = no limit |
| `_galaxie_box_overflow` | number 0–2 | extra height, cm, the lid still closes over |
| `_galaxie_gift_length`, `_galaxie_gift_width`, `_galaxie_gift_height` | number ≥ 0 | candle jar alone, lid on, cm; used for gift packing when all three are set |

Values are cleaned by the panel's own rules: sizes become decimal strings, 0 or
anything non-numeric means "not set", `max` is a whole number, and `overflow` is
clamped to 0–2. Any change clears the cached candle sizes the gift builder and
the "Cabe:" preview use.

On variations, use WooCommerce's REST API. A request that sets these keys is
refused (`galaxie_meta_forbidden`) unless the user can `edit_product` that
variation. The keys are also registered with a REST schema for `product` and
`product_variation`, so `wp/v2` shows them on products to users who can edit
them.

### Set a gift box's internal size and overflow

```sh
curl -u "$AUTH" -X PUT "$SITE/wp-json/wc/v3/products/123/variations/456" \
  -H 'Content-Type: application/json' \
  -d '{
    "meta_data": [
      { "key": "_galaxie_box_length",   "value": "13.6" },
      { "key": "_galaxie_box_width",    "value": "13.6" },
      { "key": "_galaxie_box_height",   "value": "4.2" },
      { "key": "_galaxie_box_max",      "value": 4 },
      { "key": "_galaxie_box_overflow", "value": "1.5" }
    ]
  }'
```

### Set a candle's gift dimensions

```sh
curl -u "$AUTH" -X PUT "$SITE/wp-json/wc/v3/products/789/variations/790" \
  -H 'Content-Type: application/json' \
  -d '{
    "meta_data": [
      { "key": "_galaxie_gift_length", "value": "5.3" },
      { "key": "_galaxie_gift_width",  "value": "5.3" },
      { "key": "_galaxie_gift_height", "value": "6.7" }
    ]
  }'
```

To unset a size, send `""` (stored empty, which reads as "not set").

## WP-CLI

The same service as the REST routes:

```sh
wp galaxie module list [--format=json]
wp galaxie module enable shipping-cartons
wp galaxie module disable shipping-cartons

wp galaxie settings get shipping-cartons            # all values, JSON
wp galaxie settings get shipping-cartons margin
wp galaxie settings get shipping-cartons --schema
wp galaxie settings set shipping-cartons margin 1.5
wp galaxie settings set shipping-cartons cartons '[{"code":"N12","length":12,"width":12,"height":12,"active":true}]'
wp galaxie settings set gift-wrap size_attribute pa_peso --string
```

`set` reads the value as JSON when it is valid JSON (`true`, `1.5`, `[...]`),
and as text otherwise; `--string` always reads it as text.
