# Admin UI Design Brief — Payments-Console Style

Paste everything below into Claude Code as the design directive for the admin/back-office UI.

---

## 0. Intent

Rebuild the admin interface to match a modern payments-console aesthetic: light, near-monochrome, dense with data but generously spaced, with colour used only to carry meaning (status, links, brand). The interface should feel like a financial operations tool — calm, legible, precise. No gradients, no decorative illustration, no shadow-heavy cards. Restraint is the point.

Apply this system globally. Do not introduce component styles outside these tokens.

---

## 1. Design tokens

### Colour

```
--surface            #FFFFFF   page + primary panels
--surface-sunken     #F7F7F8   sidebar, inputs, table header, segmented-control track
--surface-hover      #F2F2F4   row + nav hover
--ink                #111113   primary text, headings, amounts
--ink-secondary      #6B7280   labels, timestamps, placeholder, inactive tabs
--ink-tertiary       #9CA3AF   captions, disabled
--border             #E8E8EC   1px hairline, all dividers and control outlines
--border-strong      #D9D9DE   focus/pressed outline
--accent             #2563EB   links, values in detail panes, secondary-nav links
--accent-graph       #4F46E5   primary chart series
--inverse            #0A0A0A   toast, card art, logo tile, active tab underline
```

Semantic:

```
--success-fg  #0F7A4D   --success-bg  #E9F7F0
--warning-fg  #9A6400   --warning-bg  #FDF5E3
--danger-fg   #C4281C   --danger-bg   #FDF0EF
--neutral-fg  #4B5563   --neutral-bg  #F1F1F3
```

Rules: never use accent blue for buttons or backgrounds — it is reserved for text links and data values. Status colour appears only in pills, dots, and one-off row tints. Black is the only "strong" surface (toast, payment-card art, brand tile).

### Typography

One family throughout: **Inter** (or Geist / Söhne if licensed) with `font-feature-settings: 'cv11','ss01'` and **tabular numerals on every numeric value** (`font-variant-numeric: tabular-nums`).

| Role | Size / Line | Weight | Notes |
|---|---|---|---|
| Page title | 30 / 36 | 700 | tracking -0.02em |
| Panel amount (hero figure) | 32 / 38 | 700 | tracking -0.02em, tabular |
| Section heading | 16 / 24 | 600 | inside bordered cards |
| Body / table cell | 14 / 20 | 400 | 500 for customer names |
| Nav item | 15 / 20 | 500 | |
| List row primary | 15 / 20 | 600 | |
| Meta / timestamp | 13 / 18 | 400 | `--ink-secondary` |
| Table column header | 11 / 16 | 600 | uppercase, tracking 0.06em, `--ink-secondary` |
| Currency chip | 11 / 14 | 600 | uppercase |

Uppercase is used in exactly two places: table column headers and currency chips. Nowhere else.

### Geometry

```
radius-sm   6px   chips, dots container
radius-md   10px  buttons, inputs, nav items, filter controls
radius-lg   12px  bordered content cards, banners, segmented control
radius-xl   16px  floating panels, payment-card art, toast
radius-full       status pills only
```

Borders do the work shadows normally do. Only two shadows exist in the system:

```
shadow-float   0 16px 40px -12px rgba(17,17,19,.18), 0 2px 6px rgba(17,17,19,.06)   floating/overlay panels
shadow-raised  0 1px 2px rgba(17,17,19,.06)                                          active segmented tab only
```

### Spacing

8px base grid (4px allowed for tight inline gaps). Control height is a fixed **44px** for every button, input, and filter chip in the toolbar row. Panel padding 24px; card padding 20–24px; table cell padding 16px vertical / 20px horizontal.

---

## 2. Application shell

Three columns, full viewport height, no page-level scroll — each column scrolls independently.

```
┌──────────────┬────────────────────────────────┬──────────────────────┐
│ SIDEBAR      │ CONTENT                        │ DETAIL DRAWER        │
│ 280–320px    │ fluid                          │ 420–560px            │
│ sunken bg    │ white                          │ white                │
│ 1px right    │                                │ 1px left border      │
└──────────────┴────────────────────────────────┴──────────────────────┘
```

Drawer is persistent on wide screens, slides over as a sheet below 1280px. Sidebar collapses to icon rail below 1024px, off-canvas below 768px.

### Sidebar

- **Identity block** (top, 20px padding): 48px black rounded-lg tile holding the mark, then org name at 15/600 and account email at 13/400 in `--ink-secondary`, truncated with ellipsis. Whole block is not a link.
- **Primary nav**: icon (20px, 1.75 stroke, line style — Lucide) + label, 12px gap, 10px vertical padding, `radius-md`. Active = `--surface-hover` fill, `--ink` text, icon at full weight. Hover = same fill at 60% opacity. No left accent bar, no bold-on-active.
- **Secondary links** below a 24px gap, no divider: text in `--accent`, each preceded by a 16px arrow-up-right icon. Smaller (14px), for things like Create payment link, Submit a dispute, Help centre.
- **Footer strip**: pinned to bottom, a square brand/app-launcher button and an environment toggle (e.g. "Test mode") on the same baseline.

---

## 3. Content column

### Header

Page title, then a tab row directly beneath: 15/500, 20px gap, inactive `--ink-secondary`, active `--ink` with a 2px `--inverse` underline sitting on the container's 1px bottom border. The underline is flush with that border, not floating above it.

### Toolbar

Single 44px row, 12px gaps, wrapping to a second row on narrow widths:

1. **Search** — sunken fill, no border, 16px magnifier icon at left, placeholder in `--ink-secondary`, min-width 260px.
2. **Date range** — bordered white control containing a calendar icon and preset label ("Last 30 days"), then a 1px vertical divider, then start date, a `→` glyph in `--ink-tertiary`, end date. It reads as one control, not three.
3. **More filters** — bordered white, sliders icon + label. Shows a count badge when filters are active.

### Chart block

Full-width, ~220px tall, no card wrapper and no axis chrome beyond a light baseline. Primary series is a 2px `--accent-graph` line, smooth curve; comparison series is a 1.5px dashed `--border-strong` line. On hover: a 1px vertical crosshair spanning full height, a 5px filled dot on each series, and a floating tooltip on `--surface-sunken`, `radius-md`, 12px padding, showing right-aligned tabular values with `--ink-secondary` labels. No legend, no gridlines, no area fill.

### Data table

- Header row: sunken background, uppercase micro-labels, no vertical rules.
- Rows: 64px tall, 1px bottom hairline, hover `--surface-hover`, click selects and loads the detail drawer. Selected row keeps a persistent hover tint.
- **Status pills**: `radius-full`, 2px/10px padding, 12/500, semantic fg on semantic bg. No borders, no icons inside the pill.
- A failed/flagged row tints its entire background `--danger-bg` at 40% and appends a 14px alert icon in `--danger-fg` after the ID.
- IDs render in the base family with tabular numerals; truncate long IDs with ellipsis and copy-on-click.
- Amounts and dates right-aligned; everything else left-aligned.

---

## 4. Detail drawer

Top to bottom, 24px padding, 20px between blocks:

1. **Hero row** — the transaction amount at 32/700 with the currency code in the same size and weight, then right-aligned actions: a primary outlined button ("Refund" + return-arrow icon, 44px, `radius-md`, 1px border, white fill) and a 44px square icon button holding the overflow menu.
2. **Status banner** — `--success-bg` fill, `radius-lg`, 16px padding, 20px check-circle icon in `--success-fg` at left, then a 15/600 status word with the gateway result code in `--ink-secondary` parentheses on the same line, and a 14/400 plain-language line under it. Swap tokens for warning/danger states; the shape never changes.
3. **Segmented control** — sunken track, `radius-lg`, 4px inset padding, equal-width segments at 15/500; active segment is white with `shadow-raised` and `--ink` text.
4. **Payment-card art** — `--inverse` fill, `radius-xl`, 16:10 ratio, 24px padding. The brand mark is embossed at ~4% white opacity, oversized and bleeding off the right edge. Card number in monospace at 20/500, letter-spaced 0.12em, with middle groups masked. Expiry and cardholder name at the bottom left in monospace 13, uppercase, `rgba(255,255,255,.85)`. Network logo bottom right. This is the only decorative surface in the product — everything else stays flat.
5. **Detail cards** — 1px bordered, `radius-lg`, 20px padding. A 16/600 heading, then label/value rows: label left in `--ink`, value right-aligned in `--accent`. Values that are identifiers, names, or institutions render as links; plain factual values render in `--ink`. 14px row gap, no dividers between rows.
6. **Toast** — `--inverse` fill, white 14/500 text, `radius-xl`, 14px/18px padding, dismiss X at right, bottom-right of the drawer, 4s auto-dismiss. Used for confirmations like copy-to-clipboard.

---

## 5. Floating / overlay panel

When a secondary list opens over the page (a picker, a live-activity feed, a mobile-width preview), it uses: white fill, `radius-xl`, `shadow-float`, no border, and a self-contained header of brand tile + title + a single icon button. Inside, list rows are 76px: primary name at 15/600 with timestamp at 13/400 beneath; right side carries a right-aligned tabular amount at 15/600, then a bordered currency chip (`radius-sm`, 1px border, 2px/6px padding), then an 8px status dot. Negative amounts take `--danger-fg` and a leading minus. Rows separated by hairlines that inset 16px from each edge.

---

## 6. Interaction and quality floor

- Focus: 2px `--accent` ring at 2px offset on every interactive element. Never remove outlines.
- Transitions: 120ms ease-out on background and border colour only. Drawer and sheet movement 200ms. No entrance animations on lists, cards, or sections.
- Loading: skeleton bars in `--surface-sunken` matching the real row geometry. No spinners inside tables.
- Empty states: one 15/600 line saying what's absent and one action, left-aligned inside the table body. No illustrations.
- Copy: sentence case everywhere, active voice, buttons name their outcome ("Refund payment" → toast "Payment refunded").
- Everything reachable by keyboard; drawer traps focus and closes on Escape.
- Respect `prefers-reduced-motion`.

---

## 7. Do not

- Do not add card shadows to table containers, chart blocks, or detail cards — hairline borders only.
- Do not use blue as a fill for buttons or badges.
- Do not add a second typeface, except monospace on card art and masked numbers.
- Do not use uppercase outside table headers and currency chips.
- Do not use icon-plus-text inside status pills, or coloured borders on them.
- Do not vary border-radius within a tier; pick from the scale.
- Do not centre-align body content or table cells.

---

## 8. Deliverable

Implement as a token layer first (CSS custom properties or the Tailwind theme), then refactor components to consume it — no hard-coded hex values, sizes, or radii in component files. Build these primitives before screens:

`Button`, `IconButton`, `Input`, `SearchInput`, `DateRangeControl`, `FilterButton`, `Tabs`, `SegmentedControl`, `StatusPill`, `CurrencyChip`, `StatusDot`, `DataTable` (+ header, row, skeleton, empty), `DetailCard`, `LabelValueRow`, `StatusBanner`, `PaymentCardArt`, `Toast`, `Drawer`, `FloatingPanel`, `SidebarNav`.

Ship a `/design-system` route rendering every primitive in all states (default, hover, focus, active, disabled, loading, error) so the system can be reviewed in one screen.
