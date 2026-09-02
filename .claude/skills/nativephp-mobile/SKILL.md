---
name: nativephp-mobile
description: >-
  Builds the NativePHP Mobile companion app. Activates when working on the mobile app,
  creating mobile Livewire components, configuring NativePHP plugins, building the API
  client, or when the user mentions mobile, NativePHP, iOS, Android, or xaudition-mobile-app.
---

# NativePHP Mobile Development

## When to Apply

Activate this skill when:

- Creating or modifying files in the `xaudition-mobile-app/` directory
- Building mobile Livewire components for the companion app
- Working with NativePHP EDGE components (TopBar, BottomNav, SideNav)
- Configuring NativePHP plugins (Camera, Microphone, Device, etc.)
- Building the API client layer that communicates with the parent backend
- Implementing offline-first patterns with local SQLite
- Working with push notifications, deep links, or device features

## Directory Convention (CRITICAL)

The mobile app is a **separate project** inside the parent repo folder, git-ignored by the parent.

| Context | Working Directory |
|---------|------------------|
| **Mobile app work** | `cd /Users/selase/Sites/xdata-audition/xaudition-mobile-app/` |
| **Parent backend work** | `cd /Users/selase/Sites/xdata-audition/` |

**NEVER** create mobile app files in the parent directory or parent files in the mobile directory.

## Reference Documentation

- **Framework reference**: `project/skills/nativephp-mobile-skill.md` — NativePHP v3 API, EDGE components, plugin catalog, JS bridge
- **Implementation plan**: `project/skills/specs/nativephp-mobile-plan.md` — Screen specs, architecture, API endpoints, offline strategy

Always consult these before building mobile features.

## Architecture Overview

```
┌─────────────────────────────────────┐
│       NativePHP Native Shell        │
│  (iOS / Android — WebView + Bridge) │
├─────────────────────────────────────┤
│     EDGE Components (native UI)     │
│  TopBar · BottomNav · SideNav       │
├─────────────────────────────────────┤
│   Livewire 4 + Blade Templates      │
│   (runs locally in embedded PHP)    │
├─────────────────────────────────────┤
│  Local SQLite   │   API Client      │
│  (offline cache)│   (→ parent API)  │
└─────────────────┴───────────────────┘
         ↕ HTTPS ↕
┌─────────────────────────────────────┐
│   Parent Backend (Laravel 12)       │
│   xdata-audition.test               │
│   API v1 + Sanctum Token Auth       │
└─────────────────────────────────────┘
```

## Key Conventions

### Free Plugins Only (MVP)

Use only the 9 bundled free plugins. Build alternatives for premium features:

| Need | Free Alternative |
|------|-----------------|
| Secure token storage | Laravel encrypted cookies in WebView + local SQLite cache |
| Push notifications | WebSocket polling via Laravel Reverb when app is active |
| Biometrics | Not needed for MVP — use standard login |

### API Client Pattern

All communication with the parent backend goes through a centralized API client service:

<code-snippet name="API Client Service" lang="php">
// app/Services/ApiClient.php
final class ApiClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $token = null,
    ) {}

    public function get(string $endpoint, array $query = []): array
    {
        return Http::withToken($this->token)
            ->baseUrl($this->baseUrl)
            ->get($endpoint, $query)
            ->throw()
            ->json();
    }

    public function post(string $endpoint, array $data = []): array
    {
        return Http::withToken($this->token)
            ->baseUrl($this->baseUrl)
            ->post($endpoint, $data)
            ->throw()
            ->json();
    }
}
</code-snippet>

### Authentication Flow

1. User enters email + password on login screen
2. Mobile app calls `POST /api/v1/auth/login` with `device_name`
3. Backend returns Sanctum token
4. Token stored in local SQLite + encrypted cookie
5. All subsequent API calls include `Authorization: Bearer {token}`

### EDGE Component Syntax

EDGE components use `native:` Blade component prefix:

<code-snippet name="EDGE Layout" lang="blade">
<native:top-bar
    title="{{ $title }}"
    :background-color="'#ffffff'"
    :title-color="'#111827'"
    :buttons="[
        ['id' => 'back', 'text' => '←', 'position' => 'left'],
    ]"
/>

{{-- Page content (Livewire + Blade) --}}
<div class="min-h-screen">
    {{ $slot }}
</div>

<native:bottom-nav
    :items="[
        ['id' => 'meetings', 'label' => 'Meetings', 'icon' => 'calendar', 'url' => '/meetings'],
        ['id' => 'tasks', 'label' => 'Tasks', 'icon' => 'checklist', 'url' => '/tasks'],
        ['id' => 'record', 'label' => 'Record', 'icon' => 'mic', 'url' => '/record'],
        ['id' => 'alerts', 'label' => 'Alerts', 'icon' => 'bell', 'url' => '/notifications'],
        ['id' => 'settings', 'label' => 'Settings', 'icon' => 'gear', 'url' => '/settings'],
    ]"
/>
</code-snippet>

### JavaScript Bridge

Access native device APIs from JavaScript:

<code-snippet name="JS Bridge Import" lang="javascript">
import { Microphone, Events } from '#nativephp';

// Start recording
await Microphone.start();

// Listen for recording complete
Events.on('NativePHP\\Events\\Microphone\\MicrophoneRecorded', (event) => {
    console.log('Recording saved to:', event.path);
});

// Stop recording
await Microphone.stop();
</code-snippet>

### Offline-First Pattern

<code-snippet name="Offline Sync" lang="php">
// 1. Read from local SQLite first (instant)
$meetings = LocalMeeting::query()->latest()->get();

// 2. Queue API sync in background
dispatch(function () {
    $remote = app(ApiClient::class)->get('/api/v1/meetings');
    foreach ($remote['data'] as $meeting) {
        LocalMeeting::updateOrCreate(
            ['remote_id' => $meeting['id']],
            $meeting,
        );
    }
});
</code-snippet>

## Backend API Prerequisites

These endpoints must exist on the parent backend before mobile features work:

### Already Implemented
- `GET /api/v1/meetings` — Meeting list with filters
- `POST /api/v1/meetings` — Create meeting
- `POST /api/v1/meetings/{meeting}/join` — Join by code
- `POST /api/v1/meetings/{meeting}/audio/presign` — Get S3 upload URL
- `POST /api/v1/meetings/{meeting}/audio/confirm` — Confirm upload
- `GET /api/v1/meetings/{meeting}/transcript` — Transcript segments
- `GET /api/v1/meetings/{meeting}/action-items` — Meeting-scoped tasks
- `GET /api/v1/meetings/{meeting}/markers` — Secretary markers

### Must Be Built (Before Mobile MVP)
- `POST /api/v1/auth/login` — Sanctum token from email+password+device_name
- `POST /api/v1/auth/logout` — Revoke current token
- `GET /api/v1/me` — User + roles + permissions + tenant features
- `GET /api/v1/action-items` — Cross-meeting list (assigned_to=me)
- `POST /api/v1/devices` — Register push notification token
- `DELETE /api/v1/devices/{device}` — Unregister device

## MVP Screens

| Screen | Route | Backend Endpoint |
|--------|-------|-----------------|
| Login | `/login` | `POST /api/v1/auth/login` |
| Meeting List | `/meetings` | `GET /api/v1/meetings` |
| Meeting Detail | `/meetings/{id}` | `GET /api/v1/meetings/{id}` |
| Join Meeting | `/join` | `POST /api/v1/meetings/{id}/join` |
| Meeting Room | `/meetings/{id}/room` | Audio presign + confirm |
| Live Transcript | `/meetings/{id}/transcript` | `GET /api/v1/meetings/{id}/transcript` |
| My Tasks | `/tasks` | `GET /api/v1/action-items` |
| Task Detail | `/tasks/{id}` | `PUT /api/v1/action-items/{id}` |
| Notifications | `/notifications` | `GET /api/v1/notifications` |
| Settings | `/settings` | Local SQLite + `POST /api/v1/auth/logout` |

## Common Pitfalls

- Creating files in the wrong directory (parent vs mobile) — always check `pwd`
- Using `native-*` HTML tags instead of `native:*` Blade component syntax
- Using `href` in BottomNav items instead of `url`
- Missing `id` attribute on BottomNav items
- Importing Alpine.js separately (already bundled with Livewire 4)
- Using premium plugins (`nativephp/mobile-firebase`, `nativephp/mobile-secure-storage`) — MVP is free-only
- Referencing `RecordingStopped` event instead of `MicrophoneRecorded`
- Referencing `TokenReceived` event instead of `TokenGenerated`
- Missing safe area insets on fixed/sticky elements
- Not handling offline state in API-dependent screens
