# AI Chat for WordPress

An AI chatbot and semantic search for WordPress, trained on your own content.
Bring your own API key from OpenAI, Google Gemini, Mistral or OpenRouter. There is
no license server, no account and no paid tier: every feature is included.

## Features

- **Chat anywhere**: a floating chat widget, or embed it with the `[ai_chat]` shortcode.
- **Trained on your site**: posts, pages, WooCommerce products and any custom post type.
  You can also add documents (PDF, TXT, MD, XML, CSV) and external web pages. Content is
  split into chunks, embedded and retrieved for each answer (RAG).
- **WooCommerce**: product search with product cards, add to cart from the chat, cart
  popup and order status lookup.
- **Semantic search field**: `[ai_search_field]` and `[aicwp_search]` return AI-ranked results.
- **Conversation tools**:
  - chat history and search analytics with CSV export;
  - "Chat Insights", an AI review of past conversations;
  - a contact form, plus a tool that lets the AI send you a message;
  - quick action buttons and pre-chat required fields.
- **Integrations**: webhooks (n8n, Make, Zapier and similar), WhatsApp (via Twilio) and Telegram.
- **Input options**: speech-to-text (OpenAI or Mistral) and image input.
- **Controls**:
  - rate limiting and IP/CIDR blocking;
  - a custom system prompt;
  - forced response language;
  - dark mode, header styles and white label.
- **Translations**: Brazilian Portuguese (pt_BR) is included, and a `.pot` template is
  provided for other languages.

## Requirements

- WordPress 5.0+
- PHP 7.4+ and MySQL/MariaDB
- An API key from at least one provider: OpenAI, Google Gemini, Mistral or OpenRouter
- WooCommerce (optional, for the product features)

## Installation

1. Download a release `.zip`, or clone this repository into `wp-content/plugins/ai-chat-wp`.
2. Activate **AI Chat for WordPress** under *Plugins*.
3. Open **AI Chat → Settings**, choose a provider and paste your API key.
4. Open **AI Chat → Data Training**, choose which content types to train, and run the training.
5. Turn on the floating widget in the settings, or add `[ai_chat]` to a page.

## Shortcodes

| Shortcode | Attributes |
|---|---|
| `[ai_chat]` | `height` (default `600px`), `style` (`1` or `2`), `pictures` (`enabled`/`disabled`), `show_popular_searches` (`yes`/`no`), `popular_searches_limit`, `popular_searches_title` |
| `[ai_search_field]` | `placeholder`, `post_types` (e.g. `post,page,product`), `limit` |
| `[aicwp_search]` | `placeholder`, `button_text`, `post_types` (`all` or a comma-separated list), `limit`, `show_toggle`, `button_action` (`quick_picks`, `popular_searches`, `disable`) |

`[aicwp_chat]` is an alias of `[ai_chat]`.

## Upgrading from the previous release

This plugin used to be distributed under another name. The first time it is activated it:

- deactivates the previous plugin, if it is still installed;
- copies its settings, trained embeddings, chat history, contact messages and statistics
  to the new names;
- removes the previous plugin's scheduled tasks.

The copy is non-destructive: the old data stays untouched, so you can roll back. Licensing
and trial data are not carried over. Shortcodes from the previous release need to be
updated to the names above.

## Privacy and external services

The plugin only contacts the services you configure, plus the ones listed here:

- **Your AI provider** (OpenAI, Google, Mistral or OpenRouter) receives chat messages and the
  content being trained.
- **Twilio, Telegram and your webhook URLs** are contacted only if you enable those integrations.
- **freeipapi.com** receives visitor IP addresses to show an approximate location in the chat
  history.
- **Admin screens only**: Google Fonts, Chart.js from cdn.jsdelivr.net and country flags from
  flagcdn.com. These are never loaded for site visitors.

## Development

- Translations: the text domain is `ai-chat-wp` and the files are in `languages/`. After changing
  strings, regenerate the template with `wp i18n make-pot . languages/ai-chat-wp.pot`, then update
  and compile the `.po`/`.mo` files, for example with `msgmerge` and `msgfmt`.
- Code prefixes: `AICWP_` for classes and constants, `aicwp_` for functions, options and hooks,
  `aicwp-` for CSS and handles, and `aicwp/v1` for the REST API.

## License

GPL-2.0. See [LICENSE](LICENSE).

The bundled [smalot/pdfparser](https://github.com/smalot/pdfparser) library in `vendor/` is
licensed under LGPL-3.0.
