# Eir Naturals — Nordic Rituals · Perfil Sensorial (barras do carrossel)

Dados de conteúdo para o **widget de carrossel** (as *progress bars* do template).

> **Fonte da verdade:** repo `galaxie-works/eir-naturals` →
> `brandbook/linhas/nordic-rituals.md` e `brandbook/linhas/fragrancias/*`.
> Este arquivo é a camada de apresentação pro widget. Se a linha mudar, o brandbook manda.

> ⚠️ **Nunca publicar a composição.** A fórmula (matérias-primas + proporção) é interna.
> As barras mostram **percepção da família olfativa pública** — dominância e intensidade
> **derivadas** da composição, mas expressas como impressão, não como receita.

## Como os números foram derivados (interno)

Os valores **não são chute** — saem da composição oficial, lida com lógica de perfumaria:

- **Dominância (barra 1 vs barra 2):** a proporção da fórmula define quão à frente a
  característica primária fica da secundária. Ex.: uma fragrância ~90% de uma família e
  ~10% de outra vira `90` vs `20`; uma 60/40 vira uma disputa apertada tipo `80` vs `50`.
- **Intensidade (throw/permanência):** notas de **topo** (cítricos, verbena) são voláteis —
  abrem brilhantes e têm pouco alcance no ambiente → intensidade baixa. Notas de **base**
  (madeira, baunilha) são persistentes e "enchem" o cômodo → intensidade alta. A
  intensidade sai do balanço topo↔base de cada blend.

Isso mantém as barras **fiéis** ao produto sem expor a fórmula.

A coleção tem **5 fragrâncias**. Cada card usa as **2 características olfativas oficiais**
como barras + uma barra de **Intensidade**. Formatos: **50 g · 190 g · personalizado**.

---

## Perfil pronto por fragrância

### 🕯️ Nordic Moss — *Floral amadeirado* · **Serena**
> Para a leitura do fim de tarde, quando a casa pede silêncio.
- Floral      `████████░░` 80
- Amadeirada  `████░░░░░░` 40
- **Intensidade:** `█████░░░░░` Média — *floral forte com um fundo amadeirado que segura a permanência*
- Barra (paleta oficial): Verde musgo `#6F7D6A` → Cinza pedra `#A8A29E`

### 🕯️ Fjord Citrus — *Cítrico herbal* · **Leve**
> A manhã na cozinha com as janelas abertas.
- Cítrica     `█████████░` 90
- Herbal      `██░░░░░░░░` 20
- **Intensidade:** `███░░░░░░░` Suave — *só notas de topo cítricas: abre brilhante e não pesa*
- Barra (paleta oficial): Dourado fosco `#C6A96B` → Cinza pedra `#A8A29E`

### 🕯️ Golden Tundra — *Frutado cítrico* · **Acolhedora**
> Aquela sala pronta pra receber quem você gosta.
- Frutada     `████████░░` 80
- Cítrica     `████░░░░░░` 40
- **Intensidade:** `████░░░░░░` Média — *frutado macio, sem base pesada; presença suave*
- Barra (paleta oficial): Dourado fosco `#C6A96B` → Verde musgo `#6F7D6A`

### 🕯️ Boreal Herbs — *Herbal floral* · **Renovadora**
> O ar limpo de quando você acabou de arrumar a casa.
- Herbal      `████████░░` 80
- Floral      `█████░░░░░` 50
- **Intensidade:** `██████░░░░` Marcante — *dois aromáticos assertivos; disputa apertada entre as duas*
- Barra (paleta oficial): Verde musgo `#6F7D6A` → Cinza pedra `#A8A29E`

### 🕯️ Solstice Light — *Amadeirado cítrico* · **Envolvente**
> A noite mais lenta, o cantinho de sempre.
- Amadeirada  `█████████░` 90
- Cítrica     `██░░░░░░░░` 20
- **Intensidade:** `████████░░` Intensa — *base amadeirada de longa permanência; enche o ambiente*
- Barra (paleta oficial): Dourado fosco `#C6A96B` → Preto profundo `#0B0B0B`

---

## Tabela mestre (bater o olho)

| Fragrância | Perfil | Barra 1 | Barra 2 | Intensidade | Mini copy (card) |
|------------|--------|:-------:|:-------:|:-----------:|------------------|
| **Nordic Moss** | Floral amadeirado | Floral 80 | Amadeirada 40 | Média (50) | Serena · Floral amadeirada |
| **Fjord Citrus** | Cítrico herbal | Cítrica 90 | Herbal 20 | Suave (30) | Leve · Cítrica herbal |
| **Golden Tundra** | Frutado cítrico | Frutada 80 | Cítrica 40 | Média (40) | Acolhedora · Frutada cítrica |
| **Boreal Herbs** | Herbal floral | Herbal 80 | Floral 50 | Marcante (60) | Renovadora · Herbal floral |
| **Solstice Light** | Amadeirado cítrico | Amadeirada 90 | Cítrica 20 | Intensa (80) | Envolvente · Amadeirada cítrica |

> Barras 1 e 2 = as **características olfativas oficiais**. Valores 0–100 = **impressão
> percebida derivada da composição** (dominância real), não a proporção da fórmula.
> A escala de intensidade é a mesma pra todas: <40 Suave · 40–59 Média · 60–79 Marcante · 80+ Intensa.

---

## Cor da barra (paleta oficial de cada fragrância)

A barra usa o gradiente **cor primária → secundária** da própria fragrância — nada de cor nova.

| Fragrância | Gradiente | HEX |
|------------|-----------|-----|
| Nordic Moss | Verde musgo → Cinza pedra | `#6F7D6A` → `#A8A29E` |
| Fjord Citrus | Dourado fosco → Cinza pedra | `#C6A96B` → `#A8A29E` |
| Golden Tundra | Dourado fosco → Verde musgo | `#C6A96B` → `#6F7D6A` |
| Boreal Herbs | Verde musgo → Cinza pedra | `#6F7D6A` → `#A8A29E` |
| Solstice Light | Dourado fosco → Preto profundo | `#C6A96B` → `#0B0B0B` |

---

## Dados prontos para o widget (JSON)

`bars` é genérico (`label` + `value`). `intensity` é 0–100 (mesma escala da tabela).
Nenhum ingrediente da fórmula aparece — só percepção derivada dela.

```json
{
  "collection": "Nordic Rituals",
  "formats": ["50 g", "190 g", "personalizado"],
  "candles": [
    { "id": "nordic-moss",   "name": "Nordic Moss",   "profile": "Floral amadeirado",  "tagline": "Para a leitura do fim de tarde, quando a casa pede silêncio.", "miniCopy": "Serena · Floral amadeirada",      "intensity": 50, "intensityLabel": "Média",    "barColor": ["#6F7D6A", "#A8A29E"], "bars": [{ "label": "Floral", "value": 80 }, { "label": "Amadeirada", "value": 40 }] },
    { "id": "fjord-citrus",  "name": "Fjord Citrus",  "profile": "Cítrico herbal",     "tagline": "A manhã na cozinha com as janelas abertas.",                  "miniCopy": "Leve · Cítrica herbal",           "intensity": 30, "intensityLabel": "Suave",    "barColor": ["#C6A96B", "#A8A29E"], "bars": [{ "label": "Cítrica", "value": 90 }, { "label": "Herbal", "value": 20 }] },
    { "id": "golden-tundra", "name": "Golden Tundra", "profile": "Frutado cítrico",    "tagline": "Aquela sala pronta pra receber quem você gosta.",             "miniCopy": "Acolhedora · Frutada cítrica",    "intensity": 40, "intensityLabel": "Média",    "barColor": ["#C6A96B", "#6F7D6A"], "bars": [{ "label": "Frutada", "value": 80 }, { "label": "Cítrica", "value": 40 }] },
    { "id": "boreal-herbs",  "name": "Boreal Herbs",  "profile": "Herbal floral",      "tagline": "O ar limpo de quando você acabou de arrumar a casa.",         "miniCopy": "Renovadora · Herbal floral",      "intensity": 60, "intensityLabel": "Marcante", "barColor": ["#6F7D6A", "#A8A29E"], "bars": [{ "label": "Herbal", "value": 80 }, { "label": "Floral", "value": 50 }] },
    { "id": "solstice-light","name": "Solstice Light","profile": "Amadeirado cítrico", "tagline": "A noite mais lenta, o cantinho de sempre.",                   "miniCopy": "Envolvente · Amadeirada cítrica", "intensity": 80, "intensityLabel": "Intensa",  "barColor": ["#C6A96B", "#0B0B0B"], "bars": [{ "label": "Amadeirada", "value": 90 }, { "label": "Cítrica", "value": 20 }] }
  ]
}
```

---

## Notas de uso (regras do brandbook)

- **Nunca publicar composição.** Barra = percepção derivada da fórmula, não a fórmula.
- **Não inventar nota** fora da composição oficial (nada de musgo, âmbar, cedro, eucalipto,
  bergamota, especiarias como "nota", mesmo que combine com o nome).
- **Taglines = atmosfera de uso**, no cotidiano BR — a inspiração nórdica fica no nome e na
  direção visual.
- Barras e intensidade são **derivadas da composição** (dominância + balanço topo/base).
  Recalibrar só se a formulação mudar — aí atualiza o brandbook primeiro.
- Acessibilidade: sempre rótulo + número visível, nunca só a cor.
- Coleção: **Nordic Rituals** · Categoria: **Velas aromáticas** · Formatos: **50 g / 190 g /
  personalizado**.
