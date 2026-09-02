# Eir Naturals — Nordic Rituals · Perfil Sensorial (barras do carrossel)

Dados de conteúdo + spec visual para o **widget de carrossel** (as *progress bars*).

> **Fonte da verdade:** repo `galaxie-works/eir-naturals` →
> `brandbook/linhas/nordic-rituals.md` e `brandbook/linhas/fragrancias/*`.
> Este arquivo é a camada de apresentação. Se a linha mudar, o brandbook manda.

> ⚠️ **Nunca publicar a composição.** A fórmula (matéria-prima + proporção) é interna.
> As barras mostram **intensidade percebida** de cada característica olfativa pública.

---

## A escala (leia antes de mexer nos números)

**As barras NÃO são porcentagem e NÃO somam 100.** Isso é proposital e precisa ficar
explícito na UI, senão parece erro.

- Cada característica tem sua **própria escala 0–10** de *intensidade percebida*.
- Duas barras podem ser altas ao mesmo tempo (uma fragrância pode ser muito herbal **e**
  bem floral). Não existe "todo" sendo dividido.
- **Nunca renderizar com `%`.** Use `8/10`, ou só a barra + a faixa nomeada.

| Faixa | Rótulo | Significado |
|:-----:|--------|-------------|
| 8–10 | **Predominante** | É a cara da fragrância |
| 4–7 | **Presente** | Se percebe com clareza, sem dominar |
| 1–3 | **Sutil** | Um fio, dá acabamento |

**Por que somar 100 seria pior:** se as duas barras fechassem 100, elas viravam a
proporção da fórmula — exatamente o que não pode ser publicado. A escala independente é o
que deixa a barra fiel ao produto **e** segura.

### Como os números foram derivados (interno)

- **Dominância:** vem da proporção real da fórmula. Uma fragrância ~90/10 entre suas duas
  famílias vira `9` e `2`; uma 60/40 vira uma disputa apertada tipo `8` e `5`.
- **Intensidade (throw/permanência):** notas de **topo** (cítricos, verbena) são voláteis —
  abrem brilhantes, alcance curto → intensidade baixa. Notas de **base** (madeira, baunilha)
  são persistentes e enchem o cômodo → intensidade alta.

---

## Perfil pronto por fragrância

### 🕯️ Nordic Moss — *Floral amadeirado* · `Serena` · `Fim de tarde`
> Para a leitura do fim de tarde, quando a casa pede silêncio.
- Floral      `████████░░` 8/10 — Predominante
- Amadeirada  `████░░░░░░` 4/10 — Presente
- **Intensidade** `█████░░░░░` 5/10 — Média

### 🕯️ Fjord Citrus — *Cítrico herbal* · `Leve` · `Manhã`
> A manhã na cozinha com as janelas abertas.
- Cítrica     `█████████░` 9/10 — Predominante
- Herbal      `██░░░░░░░░` 2/10 — Sutil
- **Intensidade** `███░░░░░░░` 3/10 — Suave

### 🕯️ Golden Tundra — *Frutado cítrico* · `Acolhedora` · `Convívio`
> Aquela sala pronta pra receber quem você gosta.
- Frutada     `████████░░` 8/10 — Predominante
- Cítrica     `████░░░░░░` 4/10 — Presente
- **Intensidade** `████░░░░░░` 4/10 — Média

### 🕯️ Boreal Herbs — *Herbal floral* · `Renovadora` · `Rotina`
> O ar limpo de quando você acabou de arrumar a casa.
- Herbal      `████████░░` 8/10 — Predominante
- Floral      `█████░░░░░` 5/10 — Presente
- **Intensidade** `██████░░░░` 6/10 — Marcante

### 🕯️ Solstice Light — *Amadeirado cítrico* · `Envolvente` · `Noite`
> A noite mais lenta, o cantinho de sempre.
- Amadeirada  `█████████░` 9/10 — Predominante
- Cítrica     `██░░░░░░░░` 2/10 — Sutil
- **Intensidade** `████████░░` 8/10 — Intensa

---

## Tabela mestre

| Fragrância | Perfil | Barra 1 | Barra 2 | Intensidade | Tag | Momento |
|------------|--------|:-------:|:-------:|:-----------:|:---:|:-------:|
| **Nordic Moss** | Floral amadeirado | Floral **8** | Amadeirada **4** | 5 · Média | Serena | Fim de tarde |
| **Fjord Citrus** | Cítrico herbal | Cítrica **9** | Herbal **2** | 3 · Suave | Leve | Manhã |
| **Golden Tundra** | Frutado cítrico | Frutada **8** | Cítrica **4** | 4 · Média | Acolhedora | Convívio |
| **Boreal Herbs** | Herbal floral | Herbal **8** | Floral **5** | 6 · Marcante | Renovadora | Rotina |
| **Solstice Light** | Amadeirado cítrico | Amadeirada **9** | Cítrica **2** | 8 · Intensa | Envolvente | Noite |

Barra 1 = característica primária, barra 2 = secundária (as duas oficiais do brandbook).
Escala de intensidade: `1–3 Suave · 4–5 Média · 6–7 Marcante · 8–10 Intensa`.

**Tags:** a de **caráter** é o adjetivo da mini copy oficial; a de **momento** resume a
atmosfera de uso (serve de filtro no shop). Badges de merchandising (Mais vendida,
Novidade) são atribuídos por vocês com dado real — não são fixos aqui.

---

## Gradientes das barras

### Rampa derivada da paleta oficial

A paleta oficial tem 6 cores. Para a barra precisamos de **tom médio e escuro** (o claro
desaparece na trilha off-white), então usamos as cores oficiais + tint/shade delas — não são
cores novas de marca, são aplicação da paleta.

| Token | HEX | Origem |
|-------|-----|--------|
| `--moss-deep` | `#55604F` | shade de Verde musgo |
| `--moss` | `#6F7D6A` | **oficial** Verde musgo |
| `--moss-soft` | `#8E9B89` | tint de Verde musgo |
| `--gold-deep` | `#A88B4E` | shade de Dourado fosco |
| `--gold` | `#C6A96B` | **oficial** Dourado fosco |
| `--stone` | `#A8A29E` | **oficial** Cinza pedra |
| `--sand` | `#D8CFC4` | **oficial** Bege areia |
| `--ink` | `#0B0B0B` | **oficial** Preto profundo |
| `--ink-warm` | `#2A2622` | shade quente de Preto profundo |
| `--track` | `#E6E2DC` | shade de Off-white (trilha vazia) |

### Gradiente por fragrância (todos `90deg`, esquerda → direita)

Cada fragrância tem gradiente **próprio e distinto** — nenhum repetido entre cards.

| Fragrância | Gradiente | CSS |
|------------|-----------|-----|
| **Nordic Moss** | Musgo escuro → musgo suave | `linear-gradient(90deg, #55604F, #8E9B89)` |
| **Fjord Citrus** | Dourado profundo → dourado fosco | `linear-gradient(90deg, #A88B4E, #C6A96B)` |
| **Golden Tundra** | Dourado fosco → verde musgo | `linear-gradient(90deg, #C6A96B, #6F7D6A)` |
| **Boreal Herbs** | Verde musgo → cinza pedra | `linear-gradient(90deg, #6F7D6A, #A8A29E)` |
| **Solstice Light** | Dourado fosco → preto quente | `linear-gradient(90deg, #C6A96B, #2A2622)` |

Notas:
- **Nordic Moss vs Boreal Herbs** compartilham a paleta oficial (musgo/pedra/off-white).
  Diferenciamos pela rampa: Moss desce pro **musgo escuro** (contemplativa), Boreal abre
  pro **cinza pedra** (arejada). Nunca deixar as duas com o mesmo gradiente.
- **Solstice Light**: a paleta oficial fecha em Preto profundo `#0B0B0B`. Puro fica duro na
  barra, então recomendo `#2A2622` (preto quente). Se precisar do oficial estrito, use
  `#0B0B0B` como último stop.
- **Direção fixa em `90deg`** para todos — é o que faz os 5 cards lerem como um sistema.
- A **mesma** gradiente serve as 3 barras do card (primária, secundária, intensidade). O que
  muda é só o comprimento. Isso dá identidade de cor por vela sem poluir.

### CSS pronto

```css
.scent-bar {
  --fill: linear-gradient(90deg, #A88B4E, #C6A96B); /* trocar por fragrância */
  --track: #E6E2DC;

  height: 6px;
  border-radius: 999px;
  background: var(--track);
  overflow: hidden;
}

.scent-bar__fill {
  height: 100%;
  border-radius: inherit;
  background: var(--fill);
  /* largura = valor/10 */
  transition: width .6s cubic-bezier(.4, 0, .2, 1);
}

/* rótulo e valor */
.scent-row { display: flex; justify-content: space-between; gap: 12px; }
.scent-row__label { color: #0B0B0B; opacity: .85; }
.scent-row__value { color: #0B0B0B; opacity: .55; font-variant-numeric: tabular-nums; }

/* por fragrância */
.scent--nordic-moss   { --fill: linear-gradient(90deg, #55604F, #8E9B89); }
.scent--fjord-citrus  { --fill: linear-gradient(90deg, #A88B4E, #C6A96B); }
.scent--golden-tundra { --fill: linear-gradient(90deg, #C6A96B, #6F7D6A); }
.scent--boreal-herbs  { --fill: linear-gradient(90deg, #6F7D6A, #A8A29E); }
.scent--solstice-light{ --fill: linear-gradient(90deg, #C6A96B, #2A2622); }
```

---

## Dados prontos para o widget (JSON)

`scale: 10` deixa explícito que os valores são 0–10, **não** porcentagem.

```json
{
  "collection": "Nordic Rituals",
  "formats": ["50 g", "190 g", "personalizado"],
  "scale": 10,
  "scaleBands": [
    { "min": 8, "max": 10, "label": "Predominante" },
    { "min": 4, "max": 7,  "label": "Presente" },
    { "min": 1, "max": 3,  "label": "Sutil" }
  ],
  "track": "#E6E2DC",
  "candles": [
    { "id": "nordic-moss",   "name": "Nordic Moss",   "profile": "Floral amadeirado",  "tag": "Serena",     "moment": "Fim de tarde", "tagline": "Para a leitura do fim de tarde, quando a casa pede silêncio.", "gradient": ["#55604F", "#8E9B89"], "intensity": 5, "intensityLabel": "Média",    "bars": [{ "label": "Floral", "value": 8 }, { "label": "Amadeirada", "value": 4 }] },
    { "id": "fjord-citrus",  "name": "Fjord Citrus",  "profile": "Cítrico herbal",     "tag": "Leve",       "moment": "Manhã",        "tagline": "A manhã na cozinha com as janelas abertas.",                  "gradient": ["#A88B4E", "#C6A96B"], "intensity": 3, "intensityLabel": "Suave",    "bars": [{ "label": "Cítrica", "value": 9 }, { "label": "Herbal", "value": 2 }] },
    { "id": "golden-tundra", "name": "Golden Tundra", "profile": "Frutado cítrico",    "tag": "Acolhedora", "moment": "Convívio",     "tagline": "Aquela sala pronta pra receber quem você gosta.",             "gradient": ["#C6A96B", "#6F7D6A"], "intensity": 4, "intensityLabel": "Média",    "bars": [{ "label": "Frutada", "value": 8 }, { "label": "Cítrica", "value": 4 }] },
    { "id": "boreal-herbs",  "name": "Boreal Herbs",  "profile": "Herbal floral",      "tag": "Renovadora", "moment": "Rotina",       "tagline": "O ar limpo de quando você acabou de arrumar a casa.",         "gradient": ["#6F7D6A", "#A8A29E"], "intensity": 6, "intensityLabel": "Marcante", "bars": [{ "label": "Herbal", "value": 8 }, { "label": "Floral", "value": 5 }] },
    { "id": "solstice-light","name": "Solstice Light","profile": "Amadeirado cítrico", "tag": "Envolvente", "moment": "Noite",        "tagline": "A noite mais lenta, o cantinho de sempre.",                   "gradient": ["#C6A96B", "#2A2622"], "intensity": 8, "intensityLabel": "Intensa",  "bars": [{ "label": "Amadeirada", "value": 9 }, { "label": "Cítrica", "value": 2 }] }
  ]
}
```

---

## Notas de uso

- **Nunca renderizar as barras com `%`.** É escala 0–10 independente. Com `%` a soma passa
  de 100 e parece bug.
- **Nunca publicar composição.** Barra = percepção derivada da fórmula, não a fórmula.
- **Não inventar nota** fora da composição oficial (nada de musgo, âmbar, cedro, eucalipto,
  bergamota, especiarias como "nota", mesmo que combine com o nome).
- **Taglines = atmosfera de uso**, no cotidiano BR — a inspiração nórdica fica no nome e na
  direção visual.
- Gradiente é **um por fragrância**, direção fixa `90deg`, e serve as 3 barras do card.
- Acessibilidade: sempre rótulo + valor visível, nunca só a cor. Os stops escolhidos têm
  contraste suficiente contra a trilha `#E6E2DC`.
- Coleção: **Nordic Rituals** · Categoria: **Velas aromáticas** · Formatos: **50 g / 190 g /
  personalizado**.
