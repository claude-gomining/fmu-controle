# Gomining – Brand Identity Kit

> Kit de identidade visual oficial da Gomining para uso em todos os projetos da empresa.

---

## 📁 Arquivos incluídos

| Arquivo | Descrição |
|---|---|
| `gomining-tokens.css` | CSS Custom Properties (design tokens) – importe em qualquer projeto |
| `gomining-styleguide.html` | Guia visual interativo com todos os componentes e exemplos |
| `README.md` | Este arquivo |

---

## 🚀 Como usar

### 1. Importe os tokens

```html
<!-- No HTML -->
<link rel="stylesheet" href="gomining-tokens.css">
```

```css
/* No CSS */
@import './gomining-tokens.css';
```

```js
// No JavaScript / React / Vue
import './gomining-tokens.css';
```

### 2. Adicione as fontes (Google Fonts)

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
```

### 3. Use as variáveis

```css
.meu-componente {
  color: var(--color-primary-500);     /* Laranja Gomining */
  font-family: var(--font-heading);    /* Poppins */
  border-radius: var(--radius-xl);
  padding: var(--space-4) var(--space-8);
}
```

---

## 🎨 Paleta de Cores

### Primária – Laranja Gomining (energia, inovação)

| Token | Valor | Uso |
|---|---|---|
| `--color-primary-400` | `#FF9940` | Destaque em fundo escuro, hover |
| `--color-primary-500` | `#FF7A00` | ★ **Cor principal** – CTAs, links |
| `--color-primary-600` | `#E06C00` | Hover de botões |
| `--color-primary-700` | `#B85800` | Texto sobre fundo laranja claro |

### Secundária – Azul Institucional (confiança, educação)

| Token | Valor | Uso |
|---|---|---|
| `--color-secondary-600` | `#2642A8` | ★ **Botão secundário** |
| `--color-secondary-700` | `#1C3287` | Hover botão secundário |
| `--color-secondary-800` | `#122266` | Texto institucional |
| `--color-dark-900`      | `#0D1B3E` | Fundo hero / seções escuras |

### Neutros

| Token | Valor | Uso |
|---|---|---|
| `--color-neutral-0`   | `#FFFFFF` | Fundo de cards, superfícies |
| `--color-neutral-50`  | `#F8F9FF` | Fundo da página |
| `--color-neutral-200` | `#DDE1EF` | Bordas |
| `--color-neutral-500` | `#636B85` | Texto secundário |
| `--color-neutral-900` | `#0D1018` | Texto principal |

### Feedback

| Token | Valor |
|---|---|
| `--color-success` | `#22C55E` |
| `--color-warning` | `#F59E0B` |
| `--color-error`   | `#EF4444` |
| `--color-info`    | `#3B82F6` |

### Gradientes

```css
--gradient-primary:  linear-gradient(135deg, #ff9940 0%, #ff7a00 100%)
--gradient-hero:     linear-gradient(135deg, #0D1B3E 0%, #1c3287 60%, #2642a8 100%)
--gradient-cta:      linear-gradient(90deg, #ff7a00 0%, #ffb300 100%)
```

---

## 🔤 Tipografia

| Variável | Fonte | Pesos | Uso |
|---|---|---|---|
| `--font-heading` | **Poppins** | 500, 600, 700, 800 | Títulos, botões, destaques |
| `--font-body`    | **Inter**   | 400, 500, 600      | Texto corrido, labels, UI |

### Escala de tamanhos

```
--text-xs:   0.64rem  (~10px)
--text-sm:   0.80rem  (~13px)
--text-base: 1.00rem  (16px)  ← base
--text-md:   1.25rem  (20px)
--text-lg:   1.56rem  (25px)
--text-xl:   1.95rem  (31px)
--text-2xl:  2.44rem  (39px)
--text-3xl:  3.05rem  (49px)
--text-4xl:  3.81rem  (61px)
```

---

## 📐 Espaçamento

Sistema baseado em múltiplos de 4px:

```
--space-1:  4px    --space-6:  24px
--space-2:  8px    --space-8:  32px
--space-3:  12px   --space-10: 40px
--space-4:  16px   --space-12: 48px
--space-5:  20px   --space-16: 64px
                   --space-20: 80px ← espaçamento entre seções
```

---

## ⬜ Raios de borda

```
--radius-sm:   4px
--radius-md:   8px
--radius-lg:   12px
--radius-xl:   20px   ← padrão para cards
--radius-2xl:  32px
--radius-full: 9999px ← botões e badges
```

---

## 🌑 Sombras

```
--shadow-sm:      0 1px 3px rgba(0,0,0,.10)
--shadow-md:      0 4px 12px rgba(0,0,0,.12)
--shadow-lg:      0 10px 30px rgba(0,0,0,.15)
--shadow-xl:      0 20px 60px rgba(0,0,0,.20)
--shadow-primary: 0 8px 24px rgba(255,122,0,.35)  ← botões laranja
--shadow-card:    0 4px 20px rgba(13,27,62,.15)    ← cards padrão
```

---

## ⏱ Animações

```
--transition-fast:   150ms ease
--transition-base:   250ms ease
--transition-slow:   400ms ease
--transition-spring: 300ms cubic-bezier(.34,1.56,.64,1)
```

---

## 📏 Layout

```
--container-sm:  640px
--container-md:  768px
--container-lg:  1024px
--container-xl:  1280px   ← padrão
--container-2xl: 1440px

--header-height: 80px
--sidebar-width: 260px
```

---

## 🌙 Modo Escuro

Para ativar o modo escuro, adicione `data-theme="dark"` no elemento `<html>`:

```js
document.documentElement.setAttribute('data-theme', 'dark');
```

---

## ✅ Regras de uso da marca

### Use ✅
- Laranja `#FF7A00` como cor principal de CTAs e destaques
- Azul escuro `#0D1B3E` para seções hero e backgrounds institucionais
- Poppins para todos os títulos e botões
- Inter para texto corrido e interfaces
- Bordas arredondadas (`--radius-xl` ou maior) para cards e painéis

### Evite ❌
- Usar o laranja como background em grandes áreas de texto
- Mixar fontes fora do sistema definido
- Usar preto puro (`#000000`) – prefira `--color-neutral-900`
- Criar sombras sem os tokens definidos

### Atenção ⚠️
- Contraste mínimo WCAG AA: 4.5:1 para texto normal
- Texto branco sobre laranja: testar contraste sempre
- Botões laranja: sempre use `--shadow-primary` para elevar

---

## 📞 Contato

**Gomining** · IA para Educação  
📧 contato@gomining.com.br  
📍 Av. Júlio de Castilhos, 1259/SL 106 – Caxias do Sul – RS  
🌐 [gomining.com.br](https://gomining.com.br)

---

*Gomining Brand Identity Kit v1.0.0 – 2025*
