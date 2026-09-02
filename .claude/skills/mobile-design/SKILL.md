---
name: mobile-design
description: >-
  Designs mobile UI for the NativePHP companion app. Activates when creating mobile screens,
  designing native navigation (TopBar, BottomNav), building touch-optimized interfaces,
  prototyping mobile layouts, or when the user mentions mobile design, mobile UI, iOS, Android,
  app screens, mobile prototype, or touch interface.
---

# Mobile Design System

## When to Apply

Activate this skill when:

- Designing or building mobile app screens (Blade templates for NativePHP WebView)
- Working with EDGE native components (TopBar, BottomNav, SideNav)
- Creating touch-optimized interfaces for recording, task management, or meeting rooms
- Prototyping mobile layouts or screen flows
- Building offline-capable UI with loading/sync states

## Platform Context

This is a **NativePHP Mobile v3** app rendered in a native WebView. The UI is built with **Livewire 4 + Tailwind CSS v4** Blade templates that run inside the native shell. Native chrome (TopBar, BottomNav) is provided by EDGE components.

**IMPORTANT:** The mobile app uses its own design system — pure **Tailwind CSS v4** with a simple, minimalist aesthetic. It does **NOT** use Metronic, Bootstrap, or the admin design system from the parent app. Keep everything clean, lightweight, and minimal.

| Layer | Technology | Purpose |
|-------|-----------|---------|
| Native chrome | EDGE components (`native:top-bar`, `native:bottom-nav`) | Navigation, status bar |
| Layout | Blade + Tailwind CSS v4 (no Bootstrap, no Metronic) | Screen structure |
| Interactivity | Alpine.js (bundled with Livewire) | Client-side UI state |
| Data | Livewire 4 server components | Server state, API communication |
| Local storage | SQLite (via NativePHP) | Offline cache, draft data |

## Design Philosophy

**Simple. Clean. Minimal.**

- Generous whitespace — let content breathe
- Flat surfaces with subtle borders (`border-gray-100`) over shadows
- Single accent color (`blue-600`) — avoid color overload
- System font stack — native feel, zero loading time
- No decorative elements, gradients, or visual noise
- Content-first: every pixel earns its place

## Design Principles

### 1. Mobile-First, Touch-Native

- **Minimum touch target**: 44x44px (Apple HIG) / 48x48dp (Material Design 3)
- **Thumb zone**: Place primary actions in the bottom 40% of screen (reachable area)
- **Edge margins**: 16px (`px-4`) horizontal padding on all screens
- **Spacing between interactive elements**: Minimum 8px gap to prevent mis-taps

### 2. Safe Area Awareness

All screens must respect device safe areas (notch, home indicator, status bar).

<code-snippet name="Safe Area Layout" lang="blade">
<div class="min-h-screen flex flex-col"
     style="padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);">
    {{-- Screen content --}}
</div>
</code-snippet>

### 3. Bottom Sheet Pattern (Preferred Over Modals)

Use bottom sheets instead of centered modals for mobile. They're easier to reach and dismiss.

<code-snippet name="Bottom Sheet" lang="blade">
<div x-data="{ open: false }"
     x-show="open"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-y-full"
     x-transition:enter-end="translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-y-0"
     x-transition:leave-end="translate-y-full"
     class="fixed inset-x-0 bottom-0 z-50">
    {{-- Drag handle --}}
    <div class="flex justify-center pt-3 pb-2">
        <div class="w-10 h-1 bg-gray-300 rounded-full"></div>
    </div>
    {{-- Sheet content --}}
    <div class="bg-white rounded-t-3xl px-4 pb-8"
         style="padding-bottom: calc(env(safe-area-inset-bottom) + 2rem);">
        {{-- Content --}}
    </div>
</div>
</code-snippet>

### 4. Offline-First Visual States

Every data-dependent screen must handle three states:

| State | Visual Treatment |
|-------|-----------------|
| **Loading** | Skeleton placeholders (pulsing gray blocks), never spinners for lists |
| **Offline / Cached** | Subtle banner: "Viewing cached data" with sync icon, muted badge on stale items |
| **Error / Empty** | Centered illustration + message + retry button in thumb zone |

<code-snippet name="Skeleton Loader" lang="blade">
<div class="animate-pulse space-y-4 px-4">
    <div class="h-5 bg-gray-200 rounded w-3/4"></div>
    <div class="h-4 bg-gray-200 rounded w-1/2"></div>
    <div class="h-20 bg-gray-200 rounded"></div>
    <div class="h-20 bg-gray-200 rounded"></div>
</div>
</code-snippet>

<code-snippet name="Offline Banner" lang="blade">
<div x-data="{ online: navigator.onLine }"
     x-on:online.window="online = true"
     x-on:offline.window="online = false"
     x-show="!online"
     x-transition
     class="bg-amber-50 border-b border-amber-200 px-4 py-2 flex items-center gap-2 text-sm text-amber-700">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728"/>
    </svg>
    <span>You're offline. Changes will sync when connected.</span>
</div>
</code-snippet>

---

## EDGE Native Components

### TopBar

<code-snippet name="TopBar" lang="blade">
<native:top-bar
    title="Meetings"
    :title-color="'#111827'"
    :background-color="'#ffffff'"
    :buttons="[
        ['id' => 'add', 'text' => '+', 'position' => 'right'],
        ['id' => 'back', 'text' => '←', 'position' => 'left'],
    ]"
/>
</code-snippet>

### BottomNav

<code-snippet name="BottomNav" lang="blade">
<native:bottom-nav
    :items="[
        ['id' => 'meetings', 'label' => 'Meetings', 'icon' => 'calendar', 'url' => '/meetings'],
        ['id' => 'tasks', 'label' => 'Tasks', 'icon' => 'checklist', 'url' => '/tasks', 'badge' => $pendingCount],
        ['id' => 'record', 'label' => 'Record', 'icon' => 'mic', 'url' => '/record'],
        ['id' => 'notifications', 'label' => 'Alerts', 'icon' => 'bell', 'url' => '/notifications', 'news' => $hasUnread],
        ['id' => 'settings', 'label' => 'Settings', 'icon' => 'gear', 'url' => '/settings'],
    ]"
/>
</code-snippet>

**BottomNav rules:**
- Maximum 5 items
- Use `badge` (number) for counts, `news` (boolean) for dot indicators
- Items need `id`, `label`, `icon`, and `url` (not `href`)
- Active item is auto-detected by current URL

---

## Screen Patterns

### List Screen

Standard pattern for meetings, tasks, notifications.

<code-snippet name="List Screen" lang="blade">
<div class="flex flex-col min-h-screen bg-gray-50">
    {{-- Search/Filter bar --}}
    <div class="sticky top-0 z-10 bg-white border-b border-gray-100 px-4 py-3">
        <div class="relative">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Search meetings..."
                   class="w-full pl-10 pr-4 py-3 bg-gray-100 rounded-xl text-base
                          focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white">
            <svg class="absolute left-3 top-3.5 w-5 h-5 text-gray-400" fill="none"
                 stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
    </div>

    {{-- List items --}}
    <div class="flex-1 overflow-y-auto">
        @foreach($items as $item)
            <div wire:key="item-{{ $item->id }}"
                 wire:click="select('{{ $item->id }}')"
                 class="px-4 py-3.5 border-b border-gray-100
                        active:bg-gray-50 transition-colors duration-150">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <h3 class="text-base font-medium text-gray-900 truncate">
                            {{ $item->title }}
                        </h3>
                        <p class="text-sm text-gray-500 mt-0.5">{{ $item->subtitle }}</p>
                    </div>
                    <span class="text-xs font-medium text-gray-500 ml-3 mt-0.5">
                        {{ $item->status }}
                    </span>
                </div>
            </div>
        @endforeach
    </div>
</div>
</code-snippet>

### Detail Screen

<code-snippet name="Detail Screen" lang="blade">
<div class="flex flex-col min-h-screen bg-white">
    {{-- Header --}}
    <div class="px-4 pt-4 pb-4 border-b border-gray-100">
        <h1 class="text-xl font-semibold text-gray-900">{{ $title }}</h1>
        <p class="text-sm text-gray-500 mt-1">{{ $subtitle }}</p>
    </div>

    {{-- Content --}}
    <div class="flex-1 px-4 py-5 space-y-5">
        {{-- Metadata row --}}
        <div class="flex gap-6">
            <div>
                <span class="text-xs text-gray-400">Duration</span>
                <p class="text-base font-medium text-gray-900">{{ $duration }}</p>
            </div>
            <div>
                <span class="text-xs text-gray-400">Participants</span>
                <p class="text-base font-medium text-gray-900">{{ $participantCount }}</p>
            </div>
        </div>

        {{-- Action buttons --}}
        <div class="flex gap-3 pt-2">
            <button class="flex-1 py-3 bg-blue-600 text-white font-medium text-sm
                           rounded-lg active:bg-blue-700 transition-colors">
                Primary Action
            </button>
            <button class="py-3 px-5 border border-gray-200 text-gray-700 font-medium text-sm
                           rounded-lg active:bg-gray-50 transition-colors">
                Secondary
            </button>
        </div>
    </div>
</div>
</code-snippet>

### Recording Screen

The most critical mobile screen — push-to-talk recording interface.

<code-snippet name="Recording Interface" lang="blade">
<div class="flex flex-col min-h-screen bg-gray-900 text-white"
     x-data="{ recording: false, duration: 0, interval: null }">

    {{-- Meeting info --}}
    <div class="px-4 pt-4 pb-3">
        <h2 class="text-lg font-semibold">{{ $meeting->title }}</h2>
        <p class="text-sm text-gray-400">{{ $participantCount }} participants</p>
    </div>

    {{-- Audio visualization area --}}
    <div class="flex-1 flex items-center justify-center px-8">
        <div class="text-center">
            {{-- Waveform / pulse animation --}}
            <div class="relative w-48 h-48 mx-auto mb-8">
                {{-- Outer pulse ring --}}
                <div x-show="recording"
                     class="absolute inset-0 rounded-full bg-red-500/20 animate-ping"></div>
                {{-- Inner ring --}}
                <div :class="recording ? 'bg-red-500/30 scale-110' : 'bg-gray-700'"
                     class="absolute inset-4 rounded-full transition-all duration-300"></div>
                {{-- Center indicator --}}
                <div :class="recording ? 'bg-red-500' : 'bg-gray-600'"
                     class="absolute inset-12 rounded-full transition-colors duration-300
                            flex items-center justify-center">
                    <svg x-show="!recording" class="w-12 h-12 text-white" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
                        <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                    </svg>
                    <span x-show="recording" class="text-2xl font-mono font-bold text-white"
                          x-text="Math.floor(duration / 60).toString().padStart(2, '0') + ':' + (duration % 60).toString().padStart(2, '0')">
                    </span>
                </div>
            </div>

            <p :class="recording ? 'text-red-400' : 'text-gray-400'"
               class="text-sm font-medium transition-colors">
                <span x-show="!recording">Hold to speak</span>
                <span x-show="recording">Recording...</span>
            </p>
        </div>
    </div>

    {{-- Push-to-talk button --}}
    <div class="px-8 pb-8" style="padding-bottom: calc(env(safe-area-inset-bottom) + 2rem);">
        <button @pointerdown="recording = true; duration = 0; interval = setInterval(() => duration++, 1000); $wire.startRecording()"
                @pointerup="recording = false; clearInterval(interval); $wire.stopRecording()"
                @pointerleave="if(recording) { recording = false; clearInterval(interval); $wire.stopRecording() }"
                :class="recording ? 'bg-red-600 scale-95' : 'bg-blue-600 hover:bg-blue-500'"
                class="w-full py-5 rounded-2xl font-bold text-lg text-white
                       transition-all duration-150 active:scale-95 select-none touch-none">
            <span x-show="!recording">Hold to Talk</span>
            <span x-show="recording">Release to Stop</span>
        </button>
    </div>
</div>
</code-snippet>

---

## Typography

| Purpose | Classes |
|---------|---------|
| Screen title | `text-xl font-bold text-gray-900` |
| Section header | `text-base font-semibold text-gray-900` |
| Body text | `text-sm text-gray-700` or `text-base text-gray-700` |
| Secondary text | `text-sm text-gray-500` |
| Caption / metadata | `text-xs text-gray-400` |
| Label (uppercase) | `text-xs text-gray-500 uppercase tracking-wide` |
| Large number / stat | `text-2xl font-bold text-gray-900` |
| Mono (timers, codes) | `font-mono` |

Use system font stack for optimal native feel: `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`.

## Color System

Intentionally restrained — one accent, neutral everything else.

| Token | Value | Usage |
|-------|-------|-------|
| Primary | `blue-600` | CTAs, active states, links — the only color |
| Danger | `red-500` | Recording indicator, destructive actions, errors |
| Success | `green-600` | Synced, completed — used sparingly |
| Warning | `amber-500` | Offline banner only |
| Background | `white` | Screen backgrounds |
| Surface | `gray-50` | Card/section backgrounds |
| Border | `gray-100` | Dividers, card borders (prefer borders over shadows) |
| Text primary | `gray-900` | Headings, body |
| Text secondary | `gray-500` | Captions, metadata |
| Text muted | `gray-400` | Placeholders, disabled |

**Anti-patterns:** No gradients on surfaces. No colored backgrounds on cards. No shadows heavier than `shadow-sm`. No more than one accent color per screen.

## Touch Feedback

All interactive elements must provide immediate visual feedback:

<code-snippet name="Touch States" lang="blade">
{{-- Primary button --}}
<button class="bg-blue-600 text-white text-sm font-medium rounded-lg py-3 px-5
               active:bg-blue-700 transition-colors duration-150">
    Tap Me
</button>

{{-- Outlined button --}}
<button class="border border-gray-200 text-gray-700 text-sm font-medium rounded-lg py-3 px-5
               active:bg-gray-50 transition-colors duration-150">
    Cancel
</button>

{{-- List item (border-separated) --}}
<div class="px-4 py-3.5 border-b border-gray-100
            active:bg-gray-50 transition-colors duration-150
            cursor-pointer select-none">
    Tappable Row
</div>

{{-- Icon button --}}
<button class="w-11 h-11 rounded-full flex items-center justify-center
               active:bg-gray-100 transition-colors duration-150">
    <svg class="w-5 h-5 text-gray-500">...</svg>
</button>
</code-snippet>

## Animation Patterns

<code-snippet name="Stagger Animation" lang="blade">
{{-- List items entering with staggered delay --}}
@foreach($items as $index => $item)
    <div class="animate-fadeInUp" style="animation-delay: {{ $index * 50 }}ms">
        {{-- Item content --}}
    </div>
@endforeach

<style>
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fadeInUp {
    animation: fadeInUp 0.3s ease-out forwards;
    opacity: 0;
}
</style>
</code-snippet>

## Anti-Patterns (Do NOT Use)

- **No Metronic classes** — no `card-flush`, `fw-boldest`, `badge-light-*`, `form-control-solid`, `symbol-*`
- **No Bootstrap** — no `d-flex`, `p-5`, `mb-10`, `btn btn-primary`, `alert alert-*`
- **No shadows** on cards/lists — use `border border-gray-100` instead
- **No gradients** on surfaces — flat `bg-white` or `bg-gray-50` only
- **No rounded-2xl/3xl** on list items — keep borders flat, use `rounded-lg` on buttons only
- **No colored badges** — use plain `text-xs text-gray-500` for status text
- **No FABs** — use inline buttons or TopBar actions instead
- **No custom fonts** — system font stack only

## Common Pitfalls

- Missing `env(safe-area-inset-*)` — content will hide behind notch/home indicator
- Touch targets smaller than 44px — frustrating to tap on real devices
- Using `hover:` states without `active:` — hover doesn't exist on touch screens
- Centering modals on screen — use bottom sheets instead
- Fixed bottom elements without safe area offset — hidden behind home indicator
- Using `onClick` instead of `@pointerdown`/`@pointerup` for press-and-hold interactions
- Forgetting `select-none touch-none` on hold-to-record buttons
- Importing Metronic CSS or Bootstrap — mobile app is Tailwind-only
