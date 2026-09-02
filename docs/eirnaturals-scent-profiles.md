# Eir Naturals — Nordic Rituals · Perfil Sensorial (barras do carrossel)

Dados de conteúdo para o **widget de carrossel** (as *progress bars* do template).

> **Fonte da verdade:** repo `galaxie-works/eir-naturals` →
> `brandbook/linhas/nordic-rituals.md` e `brandbook/linhas/fragrancias/*`.
> Este arquivo é só a camada de apresentação pro widget. Se a linha mudar, o brandbook
> manda — atualizar lá primeiro.

> ⚠️ **Regra do brandbook: NUNCA publicar a composição.** A fórmula (matérias-primas +
> proporção) é interna. As barras aqui mostram **percepção da família olfativa pública**
> (o "perfil" que já vai no rótulo e na descrição), não a receita. São impressões
> comparáveis entre velas, não o blend.

A coleção tem **5 fragrâncias**. Cada card usa as **2 características olfativas oficiais**
como barras (impressões independentes, 0–100) + uma barra de **Intensidade**.
Formatos de todas: **50 g · 190 g · personalizado**.

---

## Perfil pronto por fragrância

### 🕯️ Nordic Moss — *Floral amadeirado* · **Serena**
> Para a leitura do fim de tarde, quando a casa pede silêncio.
- Floral      `████████░░` 80
- Amadeirada  `██████░░░░` 60
- **Intensidade:** `█████░░░░░` Média
- Barra (paleta oficial): Verde musgo `#6F7D6A` → Cinza pedra `#A8A29E`

### 🕯️ Fjord Citrus — *Cítrico herbal* · **Leve**
> A manhã na cozinha com as janelas abertas.
- Cítrica     `█████████░` 90
- Herbal      `████░░░░░░` 40
- **Intensidade:** `███░░░░░░░` Suave
- Barra (paleta oficial): Dourado fosco `#C6A96B` → Cinza pedra `#A8A29E`

### 🕯️ Golden Tundra — *Frutado cítrico* · **Acolhedora**
> Aquela sala pronta pra receber quem você gosta.
- Frutada     `████████░░` 80
- Cítrica     `█████░░░░░` 50
- **Intensidade:** `█████░░░░░` Média
- Barra (paleta oficial): Dourado fosco `#C6A96B` → Verde musgo `#6F7D6A`

### 🕯️ Boreal Herbs — *Herbal floral* · **Renovadora**
> O ar limpo de quando você acabou de arrumar a casa.
- Herbal      `████████░░` 80
- Floral      `█████░░░░░` 50
- **Intensidade:** `████░░░░░░` Suave
- Barra (paleta oficial): Verde musgo `#6F7D6A` → Cinza pedra `#A8A29E`

### 🕯️ Solstice Light — *Amadeirado cítrico* · **Envolvente**
> A noite mais lenta, o cantinho de sempre.
- Amadeirada  `█████████░` 90
- Cítrica     `████░░░░░░` 40
- **Intensidade:** `████████░░` Marcante
- Barra (paleta oficial): Dourado fosco `#C6A96B` → Preto profundo `#0B0B0B`

---

## Tabela mestre (bater o olho)

| Fragrância | Perfil | Barra 1 | Barra 2 | Intensidade | Mini copy (card) |
|------------|--------|:-------:|:-------:|:-----------:|------------------|
| **Nordic Moss** | Floral amadeirado | Floral 80 | Amadeirada 60 | Média | Serena · Floral amadeirada |
| **Fjord Citrus** | Cítrico herbal | Cítrica 90 | Herbal 40 | Suave | Leve · Cítrica herbal |
| **Golden Tundra** | Frutado cítrico | Frutada 80 | Cítrica 50 | Média | Acolhedora · Frutada cítrica |
| **Boreal Herbs** | Herbal floral | Herbal 80 | Floral 50 | Suave | Renovadora · Herbal floral |
| **Solstice Light** | Amadeirado cítrico | Amadeirada 90 | Cítrica 40 | Marcante | Envolvente · Amadeirada cítrica |

> As 2 barras de cada card são as **características olfativas oficiais** do brandbook.
> Os valores 0–100 são **impressão de intensidade percebida**, independentes — não somam
> 100 e não correspondem à proporção da fórmula.

---

## Cor da barra (paleta oficial de cada fragrância)

Cada fragrância já tem paleta aprovada no brandbook. A barra usa o gradiente
**cor primária → secundária** dela — nada de cor nova.

| Fragrância | Gradiente | HEX |
|------------|-----------|-----|
| Nordic Moss | Verde musgo → Cinza pedra | `#6F7D6A` → `#A8A29E` |
| Fjord Citrus | Dourado fosco → Cinza pedra | `#C6A96B` → `#A8A29E` |
| Golden Tundra | Dourado fosco → Verde musgo | `#C6A96B` → `#6F7D6A` |
| Boreal Herbs | Verde musgo → Cinza pedra | `#6F7D6A` → `#A8A29E` |
| Solstice Light | Dourado fosco → Preto profundo | `#C6A96B` → `#0B0B0B` |

---

## Dados prontos para o widget (JSON)

`bars` é genérico (`label` + `value`) — o widget renderiza sem saber que são características
olfativas. `tagline` sai da "atmosfera de uso" do brandbook (momento cotidiano, não paisagem
nórdica). Nenhum ingrediente da fórmula aparece aqui.

```json
{
  "collection": "Nordic Rituals",
  "formats": ["50 g", "190 g", "personalizado"],
  "candles": [
    { "id": "nordic-moss",   "name": "Nordic Moss",   "profile": "Floral amadeirado",  "tagline": "Para a leitura do fim de tarde, quando a casa pede silêncio.", "miniCopy": "Serena · Floral amadeirada",      "intensity": 50, "barColor": ["#6F7D6A", "#A8A29E"], "bars": [{ "label": "Floral", "value": 80 }, { "label": "Amadeirada", "value": 60 }] },
    { "id": "fjord-citrus",  "name": "Fjord Citrus",  "profile": "Cítrico herbal",     "tagline": "A manhã na cozinha com as janelas abertas.",                  "miniCopy": "Leve · Cítrica herbal",           "intensity": 30, "barColor": ["#C6A96B", "#A8A29E"], "bars": [{ "label": "Cítrica", "value": 90 }, { "label": "Herbal", "value": 40 }] },
    { "id": "golden-tundra", "name": "Golden Tundra", "profile": "Frutado cítrico",    "tagline": "Aquela sala pronta pra receber quem você gosta.",             "miniCopy": "Acolhedora · Frutada cítrica",    "intensity": 50, "barColor": ["#C6A96B", "#6F7D6A"], "bars": [{ "label": "Frutada", "value": 80 }, { "label": "Cítrica", "value": 50 }] },
    { "id": "boreal-herbs",  "name": "Boreal Herbs",  "profile": "Herbal floral",      "tagline": "O ar limpo de quando você acabou de arrumar a casa.",         "miniCopy": "Renovadora · Herbal floral",      "intensity": 40, "barColor": ["#6F7D6A", "#A8A29E"], "bars": [{ "label": "Herbal", "value": 80 }, { "label": "Floral", "value": 50 }] },
    { "id": "solstice-light","name": "Solstice Light","profile": "Amadeirado cítrico", "tagline": "A noite mais lenta, o cantinho de sempre.",                   "miniCopy": "Envolvente · Amadeirada cítrica", "intensity": 80, "barColor": ["#C6A96B", "#0B0B0B"], "bars": [{ "label": "Amadeirada", "value": 90 }, { "label": "Cítrica", "value": 40 }] }
  ]
}
```

---

## Notas de uso (regras do brandbook)

- **Nunca publicar composição.** Ingrediente + proporção é interno. Barra = percepção da
  família olfativa pública, só isso.
- **Não inventar nota** que não está na fórmula (o brandbook proíbe citar musgo, âmbar,
  cedro, eucalipto, bergamota, especiarias etc. como nota, mesmo que "combine" com o nome).
- **Taglines = atmosfera de uso**, no cotidiano do público BR — não a paisagem nórdica.
  A inspiração nórdica fica no nome e na direção visual, não na tagline do card.
- Valores das barras são **impressão percebida** (placeholder de design). Ajuste é
  sensorial, não confidencial — pode calibrar à vontade.
- Acessibilidade: sempre rótulo + número visível, nunca só a cor.
- Coleção: **Nordic Rituals** · Categoria: **Velas aromáticas** · Formatos: **50 g / 190 g /
  personalizado**.
