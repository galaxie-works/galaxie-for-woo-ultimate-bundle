# Eir Naturals — Perfis Olfativos (barras de progresso)

Referência de conteúdo para o **widget de carrossel** (inspirado nas *progress bars* do
template Nordic Rituals). Cada vela expõe sua "pirâmide olfativa" como barras — informação
real que ajuda a escolher **e** desperta curiosidade.

> **Regra de ouro:** máximo de **3 notas por vela**, sempre somando 100%. Uma nota
> dominante (≥50%) + 1–2 de apoio. Quatro barras viram poluição visual.

---

## 1. Tabela mestre — composição olfativa

| Vela | Família | Composição (barra) | Intensidade | Momento | Queima |
|------|---------|--------------------|-------------|---------|--------|
| **Fjord** | Fresco / Aquático | Cítrico `60%` · Sálvia `25%` · Sal marinho `15%` | Suave | Manhã / Foco | ~45h |
| **Skog** *(Bosque)* | Amadeirado / Terroso | Cedro `55%` · Musgo `30%` · Fumaça `15%` | Marcante | Trabalho / Leitura | ~50h |
| **Aurora** | Floral / Etéreo | Floral branco `60%` · Íris `25%` · Almíscar `15%` | Média | Autocuidado | ~45h |
| **Hygge** | Gourmand / Aconchego | Baunilha `55%` · Âmbar `30%` · Canela `15%` | Média | Noite / Relaxar | ~48h |
| **Névoa** *(Mist)* | Herbal / Calmante | Lavanda `60%` · Eucalipto `25%` · Menta `15%` | Suave | Sono / Meditar | ~45h |
| **Solstício** | Cálido / Especiado | Âmbar `50%` · Cravo `30%` · Laranja `20%` | Marcante | Jantar / Inverno | ~50h |
| **Vetr** *(Inverno)* | Resinoso / Frio | Pinho `55%` · Zimbro `30%` · Hortelã `15%` | Marcante | Festas / Ar puro | ~50h |
| **Brasa** *(Ember)* | Defumado / Intenso | Sândalo `50%` · Fumaça `30%` · Couro `20%` | Intensa | Encontros / Estar | ~52h |

---

## 2. Biblioteca de cores da barra (por família olfativa)

O gradiente da barra preenchida comunica o "humor" da vela antes mesmo da leitura.

| Família | Gradiente sugerido | HEX (início → fim) |
|---------|--------------------|--------------------|
| Cítrico / Fresco | Amarelo → verde-limão | `#F6D365` → `#A8E063` |
| Aquático | Azul-gelo → turquesa | `#A1C4FD` → `#5FC9C9` |
| Amadeirado / Terroso | Âmbar → marrom-musgo | `#D4A257` → `#6B5B3E` |
| Floral / Etéreo | Rosé → lilás | `#F6A5C0` → `#B892D6` |
| Gourmand / Aconchego | Caramelo → âmbar quente | `#E6B980` → `#C0803A` |
| Herbal / Calmante | Lilás → verde-sálvia | `#C3AED6` → `#9DC6A0` |
| Cálido / Especiado | Laranja → vermelho-queimado | `#F5A623` → `#C0392B` |
| Resinoso / Frio | Verde-pinho → azul-gelo | `#4B7A5A` → `#A9D6E5` |
| Defumado / Intenso | Marrom → vinho | `#7A4B3A` → `#4A2530` |

> **Dica de implementação:** a cor da barra segue a **nota dominante** de cada vela.

---

## 3. Métricas alternativas (mesmo efeito de barra)

Além das notas, cada card pode alternar a barra para outra leitura — ótimo para
combater a objeção "vela forte demais":

### 3.1 Intensidade da fragrância
| Vela | Suave ▸ Intensa |
|------|-----------------|
| Fjord | `▓▓▓░░░░░░░` 30% |
| Névoa | `▓▓▓▓░░░░░░` 40% |
| Aurora | `▓▓▓▓▓░░░░░` 50% |
| Hygge | `▓▓▓▓▓░░░░░` 55% |
| Skog | `▓▓▓▓▓▓▓░░░` 70% |
| Solstício | `▓▓▓▓▓▓▓░░░` 75% |
| Vetr | `▓▓▓▓▓▓▓▓░░` 80% |
| Brasa | `▓▓▓▓▓▓▓▓▓░` 90% |

### 3.2 Perfil de momento (Relaxar ▸ Energizar)
| Vela | Relaxar | Energizar |
|------|---------|-----------|
| Névoa | 90% | 10% |
| Hygge | 80% | 20% |
| Aurora | 65% | 35% |
| Brasa | 60% | 40% |
| Skog | 50% | 50% |
| Solstício | 45% | 55% |
| Vetr | 30% | 70% |
| Fjord | 20% | 80% |

### 3.3 Estação (reforça a temática nórdica)
| Vela | Estação dominante |
|------|-------------------|
| Fjord | Verão `▓▓▓▓▓▓▓▓` |
| Aurora | Primavera `▓▓▓▓▓▓▓░` |
| Skog | Outono `▓▓▓▓▓▓▓░` |
| Névoa | Primavera `▓▓▓▓▓▓░░` |
| Hygge | Inverno `▓▓▓▓▓▓▓▓` |
| Solstício | Inverno `▓▓▓▓▓▓▓▓` |
| Vetr | Inverno `▓▓▓▓▓▓▓▓▓` |
| Brasa | Outono `▓▓▓▓▓▓▓▓` |

---

## 4. Dados prontos para o widget (JSON)

Estrutura sugerida para alimentar o carrossel. `bars` é genérico: serve para notas,
intensidade, estação — o widget só renderiza `label` + `value` + `color`.

```json
{
  "candles": [
    {
      "id": "fjord",
      "name": "Fjord",
      "family": "Fresco / Aquático",
      "intensity": 30,
      "burnHours": 45,
      "barColor": ["#F6D365", "#A8E063"],
      "notes": [
        { "label": "Cítrico", "value": 60 },
        { "label": "Sálvia", "value": 25 },
        { "label": "Sal marinho", "value": 15 }
      ]
    },
    {
      "id": "skog",
      "name": "Skog",
      "family": "Amadeirado / Terroso",
      "intensity": 70,
      "burnHours": 50,
      "barColor": ["#D4A257", "#6B5B3E"],
      "notes": [
        { "label": "Cedro", "value": 55 },
        { "label": "Musgo", "value": 30 },
        { "label": "Fumaça", "value": 15 }
      ]
    },
    {
      "id": "aurora",
      "name": "Aurora",
      "family": "Floral / Etéreo",
      "intensity": 50,
      "burnHours": 45,
      "barColor": ["#F6A5C0", "#B892D6"],
      "notes": [
        { "label": "Floral branco", "value": 60 },
        { "label": "Íris", "value": 25 },
        { "label": "Almíscar", "value": 15 }
      ]
    },
    {
      "id": "hygge",
      "name": "Hygge",
      "family": "Gourmand / Aconchego",
      "intensity": 55,
      "burnHours": 48,
      "barColor": ["#E6B980", "#C0803A"],
      "notes": [
        { "label": "Baunilha", "value": 55 },
        { "label": "Âmbar", "value": 30 },
        { "label": "Canela", "value": 15 }
      ]
    },
    {
      "id": "nevoa",
      "name": "Névoa",
      "family": "Herbal / Calmante",
      "intensity": 40,
      "burnHours": 45,
      "barColor": ["#C3AED6", "#9DC6A0"],
      "notes": [
        { "label": "Lavanda", "value": 60 },
        { "label": "Eucalipto", "value": 25 },
        { "label": "Menta", "value": 15 }
      ]
    },
    {
      "id": "solsticio",
      "name": "Solstício",
      "family": "Cálido / Especiado",
      "intensity": 75,
      "burnHours": 50,
      "barColor": ["#F5A623", "#C0392B"],
      "notes": [
        { "label": "Âmbar", "value": 50 },
        { "label": "Cravo", "value": 30 },
        { "label": "Laranja", "value": 20 }
      ]
    },
    {
      "id": "vetr",
      "name": "Vetr",
      "family": "Resinoso / Frio",
      "intensity": 80,
      "burnHours": 50,
      "barColor": ["#4B7A5A", "#A9D6E5"],
      "notes": [
        { "label": "Pinho", "value": 55 },
        { "label": "Zimbro", "value": 30 },
        { "label": "Hortelã", "value": 15 }
      ]
    },
    {
      "id": "brasa",
      "name": "Brasa",
      "family": "Defumado / Intenso",
      "intensity": 90,
      "burnHours": 52,
      "barColor": ["#7A4B3A", "#4A2530"],
      "notes": [
        { "label": "Sândalo", "value": 50 },
        { "label": "Fumaça", "value": 30 },
        { "label": "Couro", "value": 20 }
      ]
    }
  ]
}
```

---

## 5. Notas de uso

- Os nomes nórdicos (*Skog*, *Vetr*, *Névoa*) reforçam a coleção **Nordic Rituals**; o
  descritivo PT-BR fica no subtítulo do card.
- Percentuais e horas de queima são **placeholders de design** — ajustar aos blends reais
  da produção antes de publicar.
- Para acessibilidade: nunca comunicar só pela cor da barra — manter sempre o rótulo da
  nota + o valor numérico visível.
