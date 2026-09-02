# Eir Naturals — Perfil Sensorial (barras de progresso)

Referência de conteúdo para o **widget de carrossel** (as *progress bars* do template
Nordic Rituals).

> ⚠️ **A barra NÃO expõe a composição.** Nada de "Cítrico 70%" — isso entregaria a
> fórmula. Aqui a barra mede **percepção/sensação**: um "equalizador" com eixos fixos
> (Frescor · Amadeirado · Floral · Aconchego) que **não somam 100%**. São impressões, não
> proporções de ingrediente. Mesma curiosidade, zero receita revelada.

Os 4 eixos são os mesmos para todas as velas, o que deixa os cards comparáveis entre si.
A **Intensidade** é uma barra separada (Suave ▸ Intensa).

---

## Perfil pronto por vela

### 🕯️ Fjord — *Fresco / Aquático*
> Uma lufada de manhã à beira-mar. Limpo, vivo, respirável.
- Frescor    `█████████░` 90
- Amadeirado `███░░░░░░░` 30
- Floral     `██░░░░░░░░` 20
- Aconchego  `█░░░░░░░░░` 10
- **Intensidade:** `███░░░░░░░` Suave

### 🕯️ Skog *(Bosque)* — *Amadeirado / Terroso*
> Chão de floresta depois da chuva. Encorpado e enraizado.
- Frescor    `██░░░░░░░░` 20
- Amadeirado `█████████░` 90
- Floral     `█░░░░░░░░░` 10
- Aconchego  `████░░░░░░` 40
- **Intensidade:** `███████░░░` Marcante

### 🕯️ Aurora — *Floral / Etéreo*
> Pétalas ao amanhecer. Delicada, luminosa, presente.
- Frescor    `████░░░░░░` 40
- Amadeirado `██░░░░░░░░` 20
- Floral     `█████████░` 90
- Aconchego  `████░░░░░░` 40
- **Intensidade:** `█████░░░░░` Média

### 🕯️ Hygge — *Gourmand / Aconchego*
> Cobertor, luz baixa, xícara quente. O abraço em forma de vela.
- Frescor    `█░░░░░░░░░` 10
- Amadeirado `████░░░░░░` 40
- Floral     `██░░░░░░░░` 20
- Aconchego  `█████████░` 90
- **Intensidade:** `██████░░░░` Média

### 🕯️ Névoa *(Mist)* — *Herbal / Calmante*
> Ar de montanha ao entardecer. Silêncio que se respira.
- Frescor    `███████░░░` 70
- Amadeirado `██░░░░░░░░` 20
- Floral     `████░░░░░░` 40
- Aconchego  `███░░░░░░░` 30
- **Intensidade:** `████░░░░░░` Suave

### 🕯️ Solstício — *Cálido / Especiado*
> Noite longa de inverno, especiarias no ar. Aconchego intenso.
- Frescor    `███░░░░░░░` 30
- Amadeirado `█████░░░░░` 50
- Floral     `███░░░░░░░` 30
- Aconchego  `████████░░` 80
- **Intensidade:** `███████░░░` Marcante

### 🕯️ Vetr *(Inverno)* — *Resinoso / Frio*
> Pinheiros cobertos de gelo. Ar cortante e limpo.
- Frescor    `███████░░░` 70
- Amadeirado `████████░░` 80
- Floral     `█░░░░░░░░░` 10
- Aconchego  `██░░░░░░░░` 20
- **Intensidade:** `████████░░` Marcante

### 🕯️ Brasa *(Ember)* — *Defumado / Intenso*
> Lareira ao fim da noite. Profundo, quente, envolvente.
- Frescor    `█░░░░░░░░░` 10
- Amadeirado `████████░░` 80
- Floral     `█░░░░░░░░░` 10
- Aconchego  `██████░░░░` 60
- **Intensidade:** `█████████░` Intensa

---

## Tabela mestre (para bater o olho)

| Vela | Família | Frescor | Amadeirado | Floral | Aconchego | Intensidade |
|------|---------|:-------:|:----------:|:------:|:---------:|:-----------:|
| **Fjord** | Fresco / Aquático | 90 | 30 | 20 | 10 | Suave |
| **Skog** | Amadeirado / Terroso | 20 | 90 | 10 | 40 | Marcante |
| **Aurora** | Floral / Etéreo | 40 | 20 | 90 | 40 | Média |
| **Hygge** | Gourmand / Aconchego | 10 | 40 | 20 | 90 | Média |
| **Névoa** | Herbal / Calmante | 70 | 20 | 40 | 30 | Suave |
| **Solstício** | Cálido / Especiado | 30 | 50 | 30 | 80 | Marcante |
| **Vetr** | Resinoso / Frio | 70 | 80 | 10 | 20 | Marcante |
| **Brasa** | Defumado / Intenso | 10 | 80 | 10 | 60 | Intensa |

---

## Cor da barra (segue a sensação dominante)

| Sensação dominante | Gradiente | HEX (início → fim) |
|--------------------|-----------|--------------------|
| Frescor | Azul-gelo → turquesa | `#A1C4FD` → `#5FC9C9` |
| Amadeirado | Dourado → marrom | `#D4A257` → `#6B5B3E` |
| Floral | Rosé → lilás | `#F6A5C0` → `#B892D6` |
| Aconchego | Caramelo → dourado quente | `#E6B980` → `#C0803A` |

Cada card pinta a barra pela sensação de maior valor — assim o "humor" da vela se
comunica pela cor antes mesmo da leitura.

---

## Dados prontos para o widget (JSON)

`bars` é genérico (`label` + `value`), então o widget renderiza os 4 eixos sem saber
que são sensações — dá pra trocar por outra métrica sem tocar no código.

```json
{
  "candles": [
    { "id": "fjord",     "name": "Fjord",     "family": "Fresco / Aquático",     "tagline": "Uma lufada de manhã à beira-mar.",              "intensity": 30, "barColor": ["#A1C4FD", "#5FC9C9"], "bars": [{ "label": "Frescor", "value": 90 }, { "label": "Amadeirado", "value": 30 }, { "label": "Floral", "value": 20 }, { "label": "Aconchego", "value": 10 }] },
    { "id": "skog",      "name": "Skog",      "family": "Amadeirado / Terroso",  "tagline": "Chão de floresta depois da chuva.",             "intensity": 70, "barColor": ["#D4A257", "#6B5B3E"], "bars": [{ "label": "Frescor", "value": 20 }, { "label": "Amadeirado", "value": 90 }, { "label": "Floral", "value": 10 }, { "label": "Aconchego", "value": 40 }] },
    { "id": "aurora",    "name": "Aurora",    "family": "Floral / Etéreo",       "tagline": "Pétalas ao amanhecer.",                         "intensity": 50, "barColor": ["#F6A5C0", "#B892D6"], "bars": [{ "label": "Frescor", "value": 40 }, { "label": "Amadeirado", "value": 20 }, { "label": "Floral", "value": 90 }, { "label": "Aconchego", "value": 40 }] },
    { "id": "hygge",     "name": "Hygge",     "family": "Gourmand / Aconchego",  "tagline": "O abraço em forma de vela.",                    "intensity": 60, "barColor": ["#E6B980", "#C0803A"], "bars": [{ "label": "Frescor", "value": 10 }, { "label": "Amadeirado", "value": 40 }, { "label": "Floral", "value": 20 }, { "label": "Aconchego", "value": 90 }] },
    { "id": "nevoa",     "name": "Névoa",     "family": "Herbal / Calmante",     "tagline": "Ar de montanha ao entardecer.",                 "intensity": 40, "barColor": ["#A1C4FD", "#5FC9C9"], "bars": [{ "label": "Frescor", "value": 70 }, { "label": "Amadeirado", "value": 20 }, { "label": "Floral", "value": 40 }, { "label": "Aconchego", "value": 30 }] },
    { "id": "solsticio", "name": "Solstício", "family": "Cálido / Especiado",    "tagline": "Especiarias na noite longa de inverno.",        "intensity": 70, "barColor": ["#E6B980", "#C0803A"], "bars": [{ "label": "Frescor", "value": 30 }, { "label": "Amadeirado", "value": 50 }, { "label": "Floral", "value": 30 }, { "label": "Aconchego", "value": 80 }] },
    { "id": "vetr",      "name": "Vetr",      "family": "Resinoso / Frio",       "tagline": "Pinheiros cobertos de gelo.",                   "intensity": 80, "barColor": ["#D4A257", "#6B5B3E"], "bars": [{ "label": "Frescor", "value": 70 }, { "label": "Amadeirado", "value": 80 }, { "label": "Floral", "value": 10 }, { "label": "Aconchego", "value": 20 }] },
    { "id": "brasa",     "name": "Brasa",     "family": "Defumado / Intenso",    "tagline": "Lareira ao fim da noite.",                      "intensity": 90, "barColor": ["#D4A257", "#6B5B3E"], "bars": [{ "label": "Frescor", "value": 10 }, { "label": "Amadeirado", "value": 80 }, { "label": "Floral", "value": 10 }, { "label": "Aconchego", "value": 60 }] }
  ]
}
```

---

## Notas de uso

- Os 4 eixos são **percepção**, não receita — publicáveis sem risco. A composição real
  nunca aparece.
- Valores são **placeholders de design**; ajustar à percepção real de cada blend antes de
  publicar (é sensorial, não confidencial).
- Acessibilidade: manter sempre o rótulo do eixo + o número visível, nunca só a cor.
