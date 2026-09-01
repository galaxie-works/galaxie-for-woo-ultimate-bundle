# PLAN — Galaxie for WooCommerce Ultimate Bundle · Fase 1

> **Objetivo da Fase 1:** portar tudo que o `eir-my-account-ux` faz hoje para o bundle,
> agora **theme-independent** (via widgets Elementor + biblioteca de componentes React),
> atingir **paridade** e sair do XStore. O catálogo maior (swatches, quantity discounts,
> reviews, etc.) é Fase 2+, fora deste plano.

## Decisões travadas (grill, 2026-09-01)

1. **Escopo:** só o porte do `eir-my-account-ux` (paridade), não o catálogo XStore.
2. **Teste:** em **staging espelho da produção** (Woo + Elementor Pro + FluentCRM + dados reais). Nunca testar em prod.
3. **Checkout widget:** **autocontido** — renderiza o próprio form nativo do WC (escondido) + o stepper, e espelha entre os dois (mesmo mecanismo provado do v1, hospedado por nós).
4. **UI:** **biblioteca de componentes React única** (Radix + shadcn/ReUI + design tokens), montada como **ilhas** dentro dos widgets. Motivo real: acabar com a gambiarra/inconsistência (tabs/botões diferentes por seção). Um `<Tabs>`, um `<Button>`, usados por todos os módulos.
5. **Segredos Google:** **rotacionar** OAuth client secret + Maps key (ação do Wagner no Google Cloud) e o bundle passa a lê-los de constantes `wp-config` (`GALAXIE_GOOGLE_*`).
6. **Wishlist:** reconstruir **só p/ logado**, storage em user-meta, botão via hooks do WooCommerce (loop + single, theme-independent), visão no My Account.

### Defaults assumidos (confirmar se discordar)
- **Settings por módulo:** opção `galaxie_woo_settings`, seções simples na página admin (limite de frete grátis, IDs FluentCRM, params de OTP, nomes das constantes Google). Nada de admin React.
- **Dependências entre módulos:** declaradas; Checkout exige um provedor de auth (PasswordlessAuth e/ou Google); MyAccount usa ProfileFields + integração FluentCRM; degradar com aviso no admin se faltar.
- **IDs FluentCRM:** config com os defaults atuais (tags 1,2,3 / 4–35 / 36–39; listas 1,2); **verificar contra o FluentCRM ao vivo** quando o MCP/app-password dele voltar (hoje caído).
- **i18n:** text domain `galaxie-woo`; portar strings pt_BR/en_US do v1.
- **Cutover:** nunca rodar v1 e v2 vivos juntos (os dois enganchariam o checkout). Testar v2 no staging com v1 desligado; cortar por paridade; aposentar o v1 no fim.

## Revisão de arquitetura (por causa da decisão nº 4)

O esqueleto commitado (`3e6c5a6`) assumia widget = PHP + template. Com **ilhas React**, muda a camada de asset/render (o `Core` de loader/toggles/admin continua válido):

- Cada widget Elementor renderiza um **mount + dados** (data-attrs / JSON script) e enfileira o bundle da ilha; o React hidrata por cima.
- Entra um **build (Vite)** e um pacote **`packages/ui`** (a lib de componentes) + entries por widget. Semear com o que já existia no `galaxie-for-woocommerce` admin (shadcn/Tailwind/Radix — plugin abandonado, mas o setup serve de base).
- **Assets buildados são commitados** e deployados junto (o deploy sobe a pasta inteira, sem `composer install`/`npm build` no servidor).
- Layout do monorepo passa a ter: `src/` (PHP), `packages/ui/` (lib React), `apps/*` ou entries por módulo, `assets/dist/` (build commitado), `vite.config`, `.gitattributes` (fixar LF).

---

## Workstreams (ordem sugerida)

### 0. Pré-requisitos (ações do Wagner, destravam o resto)
- [ ] Subir **staging** espelho da prod (provável: feature de staging da Hostinger).
- [ ] **Rotacionar** no Google Cloud: OAuth client secret + Maps key (restringir Maps por referrer). Definir as constantes em `wp-config` do staging.
- [ ] Restaurar o **app-password do FluentCRM** (revogado hoje) pra reconectar o MCP e validar os IDs de tag/lista.

### 1. Fundação (a espinha da componentização)
- [ ] Revisar o scaffold pra ilhas React: convenção de mount nos widgets, enfileiramento de bundle, passagem de dados.
- [ ] Setup Vite + `packages/ui` + Tailwind + design tokens (portar do admin abandonado).
- [ ] **Componentes base** (fonte única): `Button`, `Tabs`, `Switch`, `Dialog`, `Field`/`Input`, `Card`, `Toast`, `OtpInput`, `PhoneField` (intl-tel-input). Nenhum módulo desenha esses na mão.
- [ ] `.gitattributes` (LF) + doc do fluxo build→commit→deploy.

### 2. Serviços compartilhados (Integrations / Support)
- [ ] `Integrations/Google`: OAuth (authorization-code, People API) + Maps/Places, lendo segredos de `wp-config`. Portar de `class-eir-checkout-auth.php`.
- [ ] `Integrations/FluentCRM`: `sync_to_fluentcrm`, tags/listas, interesses, e a classe de **order-tags** (por status de pedido). IDs em config.
- [ ] `Support`: validação de CPF, schema de **ProfileFields** (nome social, nascimento, CPF, gênero), base de assets/i18n.

### 3. Módulos (ordem de porte)
- [ ] **a. ToastNotices** — primeiro (theme-agnóstico, autocontido). Valida o padrão módulo + ilha + assets. Porta de `toast-notices.*`.
- [ ] **b. PasswordlessAuth** — OTP login/registro, rate-limit, transients. Porta de `class-eir-checkout-auth.php`.
- [ ] **c. Checkout (widget)** — a cirurgia. Widget autocontido: renderiza WC checkout escondido + stepper (ilha React). Portar `checkout-auth-gate.php` + `checkout-auth.js/css`, **removendo `inject()` e todos os `.etheme-*`**. Relocação de frete + **limite de frete grátis como config nossa** (não há Free Shipping nativo no site — confirmado ao vivo). Depende de (a),(b),(2).
- [ ] **d. MyAccount (widget)** — tabs (Dados/Interesses/Comunicação/Conta), address cards, trim de menu (nível WC), UI de delete. Portar `form-edit-account.php`, `my-address.php`, `my-account*.css/js`; **`--et_*` → tokens nossos**.
- [ ] **e. Cart (widget)** — relocação de frete no carrinho + busca Places. Porta de `cart-shipping.*`. Compartilha o limite de frete com o Checkout.
- [ ] **f. Wishlist (rebuild)** — user-meta, endpoints add/remove, botão via hooks WC, visão no My Account. Reaproveitar o visual da versão skin-XStore.
- [ ] **g. AccountDeletion** — soft-delete + cancelamento em pageview + cron de purga (6 meses). **Corrigir** a comparação de data string→date no porte.

### 4. Templates Elementor prontos
- [ ] Exportar templates importáveis (Checkout, My Account, Cart) usando os widgets Galaxie, pro Wagner importar e ajustar.

### 5. Cutover
- [ ] Paridade validada no staging (com v1 desligado) → deploy do bundle → desativar `eir-my-account-ux` → aposentar.
- [ ] Quarentena `theme-adapters/xstore` só pro "chrome" (header/footer) enquanto o XStore for o tema do site; encolhe a cada página widgetizada.

---

## Riscos / pontos de atenção
- **Ilha React dentro do checkout** precisa espelhar nos campos nativos do WC (billing/shipping/payment) — é o nó técnico central; herda a lógica provada do v1 (`setNativeField`, relocação no `updated_checkout`).
- **Tailwind dentro de página Woo/Elementor/XStore:** escopar no root do widget + desligar preflight (o admin abandonado já fazia isso scoped a `#galaxie-admin-root`).
- **FluentCRM MCP caído** bloqueia validar IDs — não é bloqueio de código, mas confirmar antes do go-live.
- **Sem staging ainda** = workstream 0 é bloqueante pra testar de verdade.
