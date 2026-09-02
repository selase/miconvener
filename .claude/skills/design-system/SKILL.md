---
name: design-system
description: >-
  Applies the Metronic 8 + Bootstrap 5 design system and creates distinctive, production-grade
  frontend interfaces. Activates when creating pages, building layouts, adding cards, forms,
  tables, alerts, badges, modals, or stat widgets; structuring page templates; choosing CSS
  classes; implementing loading states; designing creative UI for public-facing or real-time
  experiences; or when the user mentions design, layout, UI component, page structure, card,
  form, table, badge, alert, restyle, or frontend.
---

# Design System

## When to Apply

Activate this skill when:

- Creating new pages or Blade templates
- Building card layouts, forms, tables, or stat widgets
- Adding alerts, badges, status indicators, or flash messages
- Choosing CSS classes for spacing, typography, or color
- Implementing Livewire loading states or interactive UI patterns
- Structuring page layouts with sidebar, header, and content areas
- Designing creative, distinctive UI for public-facing pages or real-time experiences

## Project Context

This application uses **Metronic 8** (enterprise Bootstrap 5 template) for tenant admin UI, with creative freedom for public-facing and real-time experiences.

| Context | Approach |
|---------|----------|
| **Tenant admin pages** | Follow Metronic patterns below — cards, forms, tables must use existing classes |
| **Public-facing / marketing pages** | Full creative freedom — use the Creative Design section |
| **Meeting room UI** | Creative freedom for real-time experience (Alpine.js), structural elements stay consistent |

When in doubt, check if an existing admin pattern applies first.

## Implementation Stack

- **CSS**: `public/assets/css/style.bundle.css` (Metronic) + Tailwind CSS v4 (utility supplement)
- **Markup**: Blade templates (not React/Vue)
- **Interactivity**: Alpine.js (bundled with Livewire 4) — use `x-data`, `x-bind`, `x-transition`
- **Animations**: CSS transitions/keyframes preferred; Alpine's `x-transition` for enter/leave
- **Icons**: Font Awesome 6+ (`<i class="fas fa-*">`) — already loaded globally
- **Fonts**: Poppins (primary), Nunito (fallback) — loaded via Google Fonts CDN in layout head

---

# Part 1: Metronic Admin Patterns

## Page Layout

All tenant pages extend `layouts.admin.master` and yield into `content`:

<code-snippet name="Page Template" lang="blade">
@extends('layouts.admin.master')
@section('content')
<div class="container-fluid">
    <livewire:meeting.meeting-dashboard />
</div>
@endsection
</code-snippet>

The master layout provides: sidebar (`aside#kt_aside`), header (`#kt_header`), content area (`#kt_content`), and footer.

## Card Component

Cards are the primary content container. Always use `card-flush` with `shadow-sm`.

<code-snippet name="Standard Card" lang="blade">
<div class="card card-flush shadow-sm mb-10">
    <div class="card-header border-0 pt-6">
        <h3 class="card-title fw-boldest text-dark">Title</h3>
        <div class="card-toolbar">
            <button class="btn btn-sm btn-primary">Action</button>
        </div>
    </div>
    <div class="card-body pt-0">
        {{-- Content --}}
    </div>
</div>
</code-snippet>

**Variants:**
- `h-100` — full height (for grid cards)
- `card-footer flex-wrap pt-0` — footer with wrapping

## Stat Card

Used on dashboards for KPI display.

<code-snippet name="Stat Card" lang="blade">
<div class="card card-flush shadow-sm h-100">
    <div class="card-body d-flex align-items-center justify-content-between">
        <div>
            <span class="text-gray-400 fw-bold fs-7">Label</span>
            <div class="fs-2hx fw-boldest text-dark">{{ $value }}</div>
        </div>
        <i class="fas fa-chart-bar fs-2x text-primary"></i>
    </div>
</div>
</code-snippet>

## Forms

All inputs use `form-control-solid` (filled style). Labels use `form-label fw-bold`.

<code-snippet name="Text Input" lang="blade">
<div class="col-md-6 mb-5">
    <label class="form-label fw-bold required">Field Name</label>
    <input type="text"
           wire:model="fieldName"
           class="form-control form-control-solid @error('fieldName') is-invalid @enderror"
           placeholder="Placeholder text">
    <div class="form-text">Helper text describing the field.</div>
    @error('fieldName')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
</code-snippet>

<code-snippet name="Select Input" lang="blade">
<select wire:model="field"
        class="form-select form-select-solid @error('field') is-invalid @enderror">
    <option value="">Choose...</option>
    @foreach($options as $option)
        <option value="{{ $option->value }}">{{ $option->label }}</option>
    @endforeach
</select>
</code-snippet>

<code-snippet name="Switch/Checkbox" lang="blade">
<label class="form-check form-switch form-check-custom form-check-solid">
    <input class="form-check-input" type="checkbox" wire:model="flag" />
    <span class="form-check-label fw-bold">Toggle Label</span>
</label>
<div class="form-text mt-2">Description of what this toggle does.</div>
</code-snippet>

**Validation display:**
- `is-invalid` class on the input triggers red border
- `<div class="invalid-feedback">` for error message (inside Bootstrap's validation)
- `<div class="text-danger mt-1">` for standalone error text

## Buttons

<code-snippet name="Button Variants" lang="blade">
{{-- Primary action --}}
<button class="btn btn-primary">Save</button>

{{-- Secondary/cancel --}}
<a href="{{ route('...') }}" class="btn btn-light">Cancel</a>

{{-- Destructive --}}
<button class="btn btn-danger btn-sm">Delete</button>

{{-- Icon button --}}
<button class="btn btn-icon btn-light-primary"><i class="fas fa-edit"></i></button>

{{-- With Livewire loading state --}}
<button type="submit" class="btn btn-primary">
    <span wire:loading.remove wire:target="save">
        <i class="fas fa-save me-1"></i> Save
    </span>
    <span wire:loading wire:target="save">
        <span class="spinner-border spinner-border-sm me-2"></span>Saving...
    </span>
</button>
</code-snippet>

**Button grouping:** Use `d-flex justify-content-end gap-3` for right-aligned button rows.

## Alerts & Flash Messages

<code-snippet name="Flash Alert" lang="blade">
@if(session('message'))
    <div class="alert alert-success d-flex align-items-center p-5 mb-10">
        <i class="fas fa-check-circle text-success me-4 fs-2"></i>
        <span>{{ session('message') }}</span>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger d-flex align-items-center p-5 mb-10">
        <i class="fas fa-exclamation-circle text-danger me-4 fs-2"></i>
        <span>{{ session('error') }}</span>
    </div>
@endif
</code-snippet>

## Badges

Status values map to light badge variants:

| Status | Class |
|--------|-------|
| Draft | `badge-light-dark` |
| Scheduled | `badge-light-primary` |
| Active | `badge-light-success` |
| Processing | `badge-light-warning` |
| Completed | `badge-light-info` |
| Cancelled | `badge-light-danger` |

<code-snippet name="Status Badge" lang="blade">
<span class="badge badge-light-{{ $badgeColor }} fw-bolder fs-8 px-2 py-1">
    {{ ucfirst($status) }}
</span>
</code-snippet>

## Tables

<code-snippet name="Data Table" lang="blade">
<div class="table-responsive">
    <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
        <thead>
            <tr class="fw-boldest text-muted">
                <th class="min-w-200px">Name</th>
                <th class="min-w-100px">Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr wire:key="item-{{ $item->id }}">
                    <td class="text-dark fw-bold">{{ $item->name }}</td>
                    <td><span class="badge badge-light-success">Active</span></td>
                    <td class="text-end">
                        <a href="#" class="btn btn-sm btn-light">View</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
</code-snippet>

## Separators

Use between form sections:

```blade
<div class="separator my-7"></div>
```

`my-5` for tighter spacing, `my-7` for standard section breaks.

## Section Headers

For subsections within a card:

```blade
<h4 class="fw-bolder text-dark mb-2">Section Title</h4>
<span class="text-muted fw-bold fs-7 d-block mb-5">Description of this section</span>
```

## Info Row

For displaying key-value metadata:

<code-snippet name="Info Row" lang="blade">
<div class="d-flex align-items-center mb-4">
    <i class="fas fa-calendar-alt me-3 text-primary fs-5"></i>
    <div>
        <span class="text-gray-400 fw-bold fs-7 d-block">Scheduled</span>
        <span class="text-dark fw-bold">{{ $meeting->scheduled_at->format('M d, Y g:i A') }}</span>
    </div>
</div>
</code-snippet>

## Avatars / Symbols

<code-snippet name="User Avatar" lang="blade">
<div class="symbol symbol-35px symbol-circle">
    <span class="symbol-label bg-light-primary text-primary fw-boldest">
        {{ strtoupper(substr($user->first_name, 0, 1)) }}
    </span>
</div>
</code-snippet>

Sizes: `symbol-25px`, `symbol-35px`, `symbol-50px`, `symbol-65px`.

## Collapsible Section

<code-snippet name="Collapsible" lang="blade">
<div class="d-flex align-items-center mb-3 cursor-pointer" wire:click="$toggle('showSection')">
    <i class="fas fa-chevron-{{ $showSection ? 'down' : 'right' }} me-2 text-muted"></i>
    <h5 class="mb-0 fw-bold">Section Title</h5>
    <span class="badge badge-light-info ms-2">Optional</span>
</div>
@if($showSection)
    <div class="ps-5 pt-3">
        {{-- Collapsible content --}}
    </div>
@endif
</code-snippet>

## Typography

| Purpose | Classes |
|---------|---------|
| Page title | `fw-boldest text-dark` (in card-title) |
| Section header | `fw-bolder text-dark` |
| Label | `form-label fw-bold` |
| Body text | `text-dark fw-bold` |
| Secondary text | `text-muted` or `text-gray-400 fw-bold fs-7` |
| Helper text | `form-text` |
| Small text | `fs-7` or `fs-8` |
| Large number | `fs-2hx fw-boldest text-dark` |

## Spacing Scale

| Class | Size |
|-------|------|
| `mb-3` / `mt-3` | ~0.75rem |
| `mb-5` / `mt-5` | ~1.25rem |
| `mb-6` / `pt-6` | ~1.5rem |
| `my-7` | ~1.75rem |
| `mb-10` | ~2.5rem |
| `gap-3` | ~0.75rem between flex items |
| `p-5` | ~1.25rem padding |

## Grid Layout

Use Bootstrap 5 grid. Common patterns:

```blade
{{-- Two-column form --}}
<div class="row mb-5">
    <div class="col-md-6 mb-5"><!-- Field 1 --></div>
    <div class="col-md-6 mb-5"><!-- Field 2 --></div>
</div>

{{-- Three-column form --}}
<div class="row">
    <div class="col-md-4 mb-5"><!-- Field 1 --></div>
    <div class="col-md-4 mb-5"><!-- Field 2 --></div>
    <div class="col-md-4 mb-5"><!-- Field 3 --></div>
</div>

{{-- Dashboard stat cards --}}
<div class="row g-5 g-xl-8 mb-8">
    <div class="col-xl-3"><!-- Stat card --></div>
    <div class="col-xl-3"><!-- Stat card --></div>
    <div class="col-xl-3"><!-- Stat card --></div>
    <div class="col-xl-3"><!-- Stat card --></div>
</div>
```

## Creative Within Metronic

Ways to add visual distinction while staying in the framework:

- Override CSS variables (`--kt-primary`, `--kt-success`) for tenant white-label themes
- Use `bg-light-*` badge variants with custom gradients for status indicators
- Leverage Metronic's symbol/avatar system with creative color combos
- Apply CSS `backdrop-filter`, `mix-blend-mode`, and custom `@keyframes` on top of Metronic cards
- The meeting room page is the best canvas for creative UI — real-time recording visualizations, audio level meters, and participant status indicators

---

# Part 2: Creative Frontend Design

Use this section for public-facing pages, landing pages, marketing UI, or any context where the user requests distinctive, high-quality design that goes beyond the admin framework.

## Design Thinking

Before coding, understand the context and commit to a BOLD aesthetic direction:
- **Purpose**: What problem does this interface solve? Who uses it?
- **Tone**: Pick an extreme: brutally minimal, maximalist chaos, retro-futuristic, organic/natural, luxury/refined, playful/toy-like, editorial/magazine, brutalist/raw, art deco/geometric, soft/pastel, industrial/utilitarian, etc. Use these for inspiration but design one that is true to the aesthetic direction.
- **Constraints**: Technical requirements (Blade templates, Alpine.js, existing font stack).
- **Differentiation**: What makes this UNFORGETTABLE? What's the one thing someone will remember?

**CRITICAL**: Choose a clear conceptual direction and execute it with precision. Bold maximalism and refined minimalism both work — the key is intentionality, not intensity.

Then implement working code that is:
- Production-grade and functional
- Visually striking and memorable
- Cohesive with a clear aesthetic point-of-view
- Meticulously refined in every detail

## Frontend Aesthetics Guidelines

Focus on:
- **Typography**: Choose fonts that are beautiful, unique, and interesting. Avoid generic fonts like Arial and Inter; opt for distinctive choices that elevate the aesthetic. Pair a distinctive display font with a refined body font. Load via Google Fonts CDN in layout head.
- **Color & Theme**: Commit to a cohesive aesthetic. Use CSS variables for consistency. Dominant colors with sharp accents outperform timid, evenly-distributed palettes.
- **Motion**: Use animations for effects and micro-interactions. Prioritize CSS-only solutions for Blade templates. Use Alpine.js `x-transition` and `x-intersect` for scroll-triggered reveals. Focus on high-impact moments: one well-orchestrated page load with staggered reveals (`animation-delay`) creates more delight than scattered micro-interactions.
- **Spatial Composition**: Unexpected layouts. Asymmetry. Overlap. Diagonal flow. Grid-breaking elements. Generous negative space OR controlled density.
- **Backgrounds & Visual Details**: Create atmosphere and depth rather than defaulting to solid colors. Apply creative forms like gradient meshes, noise textures, geometric patterns, layered transparencies, dramatic shadows, decorative borders, and grain overlays.

NEVER use generic AI-generated aesthetics: overused font families (Inter, Roboto, Arial, system fonts), cliched color schemes (purple gradients on white), predictable layouts, cookie-cutter components. No design should be the same — vary between light and dark themes, different fonts, different aesthetics across generations.

**IMPORTANT**: Match implementation complexity to the aesthetic vision. Maximalist designs need elaborate code with extensive animations and effects. Minimalist or refined designs need restraint, precision, and careful attention to spacing, typography, and subtle details. Elegance comes from executing the vision well.

## Project-Specific Anti-Patterns

- Don't replace `form-control-solid` with custom input styling in admin pages
- Don't use raw Tailwind layout utilities (`flex`, `p-4`) when Metronic equivalents exist (`d-flex`, `p-5`)
- Don't import external CSS frameworks alongside Metronic — they will conflict
- Don't add custom fonts to admin pages — Poppins/Nunito are the established pair
- Don't import Alpine.js separately — it's bundled with Livewire 4

## Common Pitfalls

- Using raw Tailwind (`flex`, `p-4`, `text-sm`) instead of Metronic equivalents (`d-flex`, `p-5`, `fs-7`) in admin UI
- Missing `form-control-solid` on inputs — all admin inputs must use the solid variant
- Forgetting `wire:key` on `@foreach` loops in Livewire components
- Using `alert-*` without the flex layout (`d-flex align-items-center`)
- Placing buttons without `gap-3` spacing in button groups
- Missing `card-flush` on cards — all admin cards use the flush variant
- Using `@error` with `text-danger` instead of `invalid-feedback` inside form groups
- Creating inline validation instead of FormRequest classes for controllers
- Applying creative design choices to admin pages that should follow Metronic patterns
