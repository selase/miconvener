# NativePHP Mobile App Development Plan

## Overview

This document is the comprehensive development plan for `xaudition-mobile-app/` — the NativePHP Mobile companion app for the Meeting Minutes & Action Tracker. The mobile app is a **thin API client** that provides push-to-talk recording, task viewing, and meeting participation on native iOS and Android.

**Framework:** NativePHP Mobile v3 "Air" (released Feb 1, 2026, MIT license)
**Architecture:** Embeds PHP 8.4 into native shells via WebView + Bridge system
**Minimum OS:** iOS 18.2+, Android 33+
**Phase:** Build after web tracks are complete (all 9 tracks done, 1055+ tests passing)
**Plugin Strategy:** Free plugins only for MVP. Build custom alternatives for premium features.
**Directory:** Lives at `/Users/selase/Sites/xdata-audition/xaudition-mobile-app/` — git-ignored by parent app, separate git repo

---

## 1. Project Setup & Configuration

### Create the Project

```bash
cd /Users/selase/Sites/xdata-audition
composer create-project laravel/laravel xaudition-mobile-app
cd xaudition-mobile-app
git init
```

### Install NativePHP + Free Plugins

```bash
# Core framework
composer require nativephp/mobile

# Free plugins (MIT — all bundled with framework)
composer require nativephp/mobile-microphone    # Native mic access + background recording
composer require nativephp/mobile-network       # Connectivity detection
composer require nativephp/mobile-device        # Device info, vibration
composer require nativephp/mobile-share         # Native share sheet
composer require nativephp/mobile-browser       # OAuth flows, in-app browser
composer require nativephp/mobile-dialog        # Native alerts, toasts
composer require nativephp/mobile-system        # Platform detection
composer require nativephp/mobile-camera        # Photo/video capture
composer require nativephp/mobile-file          # File operations

# NO premium plugins — we build alternatives:
# - Token storage: Laravel encrypted cookies in WebView + local SQLite cache
# - Push notifications: WebSocket polling via Laravel Reverb when app is active
# - Biometrics: Not needed for MVP
```

### Install NativePHP

```bash
php artisan native:install
# Downloads PHP binaries, sets up Xcode/Android Studio project shells
```

### Configuration (`config/nativephp.php`)

```php
return [
    'app_id' => env('NATIVEPHP_APP_ID', 'com.xdata.meetings'),
    'app_version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),
    'deeplink_scheme' => 'xdata',
    'deeplink_host' => 'meetings',

    // Permissions
    'permissions' => [
        'microphone' => true,
        'microphone_background' => true,   // Background recording
        'internet' => true,
        'camera' => false,                 // Not needed for MVP
    ],

    // Environment cleanup (removes secrets from bundle)
    'cleanup_env_keys' => [
        'DB_*', 'REDIS_*', 'MAIL_*', 'AWS_*',
        'STRIPE_*', 'PAYSTACK_*',
    ],

    // Keep these env vars in the bundle
    'cleanup_exclude_keys' => [
        'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
        'API_BASE_URL',     // Main backend URL
        'API_VERSION',      // API version prefix
    ],
];
```

### API Configuration (`config/api.php`)

```php
return [
    'base_url' => env('API_BASE_URL', 'https://app.xdata.test'),
    'version' => env('API_VERSION', 'v1'),
    'timeout' => 30,
    'retry_attempts' => 3,
    'retry_delay' => 1000, // ms
];
```

### Directory Structure

```
xaudition-mobile-app/
├── .git/                                # Independent git history
├── app/
│   ├── Livewire/
│   │   ├── Auth/
│   │   │   └── Login.php               # Login screen
│   │   ├── Meeting/
│   │   │   ├── JoinMeeting.php         # Join via code/link
│   │   │   ├── MeetingRoom.php         # Push-to-talk + recording
│   │   │   └── MeetingList.php         # Active/upcoming meetings
│   │   ├── Task/
│   │   │   ├── TaskList.php            # Assigned action items
│   │   │   └── TaskDetail.php          # Single task view
│   │   └── Settings/
│   │       └── AppSettings.php         # User preferences
│   ├── Models/
│   │   ├── AudioChunk.php              # Local SQLite - queued audio
│   │   ├── CachedMeeting.php           # Local SQLite - meeting cache
│   │   └── CachedTask.php             # Local SQLite - task cache
│   ├── Providers/
│   │   └── NativeAppServiceProvider.php
│   └── Services/
│       ├── ApiClient.php               # Sanctum-authenticated HTTP client
│       ├── AuthService.php             # Login, logout, token management
│       ├── OfflineBuffer.php           # Audio chunk queue management
│       ├── SyncService.php             # Pull data from API, push audio
│       └── AudioService.php            # Recording coordination
├── config/
│   ├── nativephp.php                   # App ID, permissions, cleanup
│   └── api.php                         # Backend URL, version, timeouts
├── database/
│   └── migrations/
│       ├── create_audio_chunks_table.php
│       ├── create_cached_meetings_table.php
│       ├── create_cached_tasks_table.php
│       └── create_app_settings_table.php
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   │   └── app.blade.php           # Base layout with Edge components
│   │   ├── livewire/
│   │   │   ├── auth/login.blade.php
│   │   │   ├── meeting/
│   │   │   │   ├── join.blade.php
│   │   │   │   ├── room.blade.php      # Push-to-talk UI
│   │   │   │   └── list.blade.php
│   │   │   ├── task/
│   │   │   │   ├── list.blade.php
│   │   │   │   └── detail.blade.php
│   │   │   └── settings.blade.php
│   │   └── components/
│   │       ├── connection-status.blade.php
│   │       └── push-to-talk-button.blade.php
│   └── js/
│       ├── rnnoise-processor.js        # RNNoise AudioWorklet
│       └── audio-pipeline.js           # Recording + noise cancellation
├── public/
│   └── js/
│       └── rnnoise-processor.js        # AudioWorklet (must be in public)
├── routes/
│   └── web.php                         # Livewire routes only
└── composer.json                       # Minimal dependencies
```

---

## 2. Architecture

### Data Flow

```
┌─────────────────────────────────────────┐
│          NativePHP Mobile App            │
│  ┌─────────────────────────────────┐     │
│  │  WebView (Blade + Livewire +    │     │
│  │  Alpine.js)                     │     │
│  │                                 │     │
│  │  ┌──────────────────────────┐   │     │
│  │  │ Audio Pipeline (JS)      │   │     │
│  │  │ getUserMedia -> RNNoise  │   │     │
│  │  │ -> MediaRecorder         │   │     │
│  │  └──────────┬───────────────┘   │     │
│  │             │ chunks             │     │
│  │             ▼                    │     │
│  │  ┌──────────────────────────┐   │     │
│  │  │ IndexedDB / SQLite       │   │     │
│  │  │ (offline audio queue)    │   │     │
│  │  └──────────┬───────────────┘   │     │
│  └─────────────┼───────────────────┘     │
│                │                          │
│  ┌─────────────┼───────────────────┐     │
│  │ PHP Runtime (embedded)          │     │
│  │  ApiClient -> HTTPS             │     │
│  │  SyncService -> queue/upload    │     │
│  │  SecureStorage -> auth token    │     │
│  └─────────────┬───────────────────┘     │
│                │                          │
│  ┌─────────────┼───────────────────┐     │
│  │ Native Bridge                   │     │
│  │  Network status, Push Notif,    │     │
│  │  SecureStorage, Device info     │     │
│  └─────────────┬───────────────────┘     │
└────────────────┼────────────────────────┘
                 │ HTTPS
                 ▼
┌─────────────────────────────────────────┐
│     Main Backend (xdata-audition)        │
│  Sanctum API  │  S3 Pre-signed URLs      │
│  WebSocket    │  Reverb Broadcasting     │
└─────────────────────────────────────────┘
```

### Screen Map

| Screen       | Livewire Component     | Route             | Edge Components        |
| ------------ | ---------------------- | ----------------- | ---------------------- |
| Login        | `Auth\Login`           | `/login`          | TopBar (app name)      |
| Meeting List | `Meeting\MeetingList`  | `/`               | TopBar + BottomNav     |
| Join Meeting | `Meeting\JoinMeeting`  | `/join`           | TopBar + BottomNav     |
| Meeting Room | `Meeting\MeetingRoom`  | `/meeting/{code}` | TopBar (meeting title) |
| Task List    | `Task\TaskList`        | `/tasks`          | TopBar + BottomNav     |
| Task Detail  | `Task\TaskDetail`      | `/tasks/{id}`     | TopBar (back)          |
| Settings     | `Settings\AppSettings` | `/settings`       | TopBar + BottomNav     |

---

## 3. Authentication

### Login Flow

```
User enters email/password
    │
    ▼
Livewire Login component
    │  Http::post(config('api.base_url') . '/api/v1/auth/login', credentials)
    ▼
Main backend validates, returns Sanctum token + user data
    │
    ▼
SecureStorage::set('api_token', $token)     # iOS Keychain / Android Keystore
SecureStorage::set('user', json_encode($user))
    │
    ▼
Redirect to Meeting List (/)
```

### AuthService Implementation

```php
class AuthService
{
    public function login(string $email, string $password): array
    {
        $response = Http::post(config('api.base_url') . '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => Device::getId(),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            SecureStorage::set('api_token', $data['token']);
            SecureStorage::set('user', json_encode($data['user']));
            SecureStorage::set('token_expires_at', $data['expires_at']);
            return $data;
        }

        throw new AuthenticationException($response->json('message', 'Login failed'));
    }

    public function logout(): void
    {
        $client = app(ApiClient::class);
        $client->post('/auth/logout');
        SecureStorage::delete('api_token');
        SecureStorage::delete('user');
        SecureStorage::delete('token_expires_at');
    }

    public function isAuthenticated(): bool
    {
        $token = SecureStorage::get('api_token');
        $expiresAt = SecureStorage::get('token_expires_at');

        if (! $token || ! $expiresAt) {
            return false;
        }

        return Carbon::parse($expiresAt)->isFuture();
    }

    public function refreshToken(): void
    {
        $client = app(ApiClient::class);
        $response = $client->post('/auth/refresh');

        if ($response->successful()) {
            SecureStorage::set('api_token', $response->json('token'));
            SecureStorage::set('token_expires_at', $response->json('expires_at'));
        }
    }

    public function user(): ?array
    {
        $user = SecureStorage::get('user');
        return $user ? json_decode($user, true) : null;
    }
}
```

### OAuth Flow (Google/Microsoft SSO)

```php
// For tenants with SSO configured
$result = Browser::auth(config('api.base_url') . '/api/v1/auth/oauth/google');
// Returns with token in callback URL
// Parse token from deep link: xdata://auth/callback?token=xxx
```

### Token Refresh Strategy

- Tokens have 30-day lifespan
- On each API call, check if token expires within 24 hours
- If near expiry, call `/api/v1/auth/refresh` before the actual request
- On 401 response, attempt refresh once, then redirect to login if still failing

---

## 4. Authorization

All authorization is enforced **server-side** on the main backend. The mobile app is a presentation layer only.

### Permission Loading

```php
// On login and periodically, fetch user permissions
$client = app(ApiClient::class);
$response = $client->get('/me');

// Response includes:
// - user.permissions: ['create-meetings', 'view-tasks', 'record-audio', ...]
// - user.roles: ['meeting-organizer', 'participant']
// - tenant.features: ['meetings', 'transcription', 'ai_minutes']
// - tenant.limits: { meetings_remaining: 15, max_duration_minutes: 240 }
```

### Client-Side Gating (UI only, not security)

```php
// In Livewire components
public function mount(): void
{
    $user = app(AuthService::class)->user();
    $this->canCreateMeeting = in_array('create-meetings', $user['permissions']);
    $this->canRecord = in_array('record-audio', $user['permissions']);
}
```

```blade
{{-- In Blade views --}}
@if($canCreateMeeting)
    <button wire:click="createMeeting">New Meeting</button>
@endif
```

---

## 5. Session & State Management

- **No server sessions** — The mobile app uses Sanctum API tokens, not session cookies
- **Livewire state** — Component properties are the primary state management
- **Persistent state** — Stored in local SQLite (preferences, cached data)
- **Auth state** — SecureStorage (encrypted, persists across app restarts)

### NativeAppServiceProvider

```php
class NativeAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Redirect to login if not authenticated
        $this->app->booted(function () {
            if (! app(AuthService::class)->isAuthenticated()
                && ! request()->is('login*')) {
                redirect('/login');
            }
        });
    }
}
```

---

## 6. Database (Local SQLite)

NativePHP forces SQLite. The local database is for **caching and offline support only** — the main backend is the source of truth.

### Migration: `audio_chunks`

```php
Schema::create('audio_chunks', function (Blueprint $table) {
    $table->id();
    $table->string('meeting_code');
    $table->integer('chunk_index');
    $table->string('file_path');          // Local file path
    $table->integer('file_size');
    $table->float('duration_seconds');
    $table->enum('status', ['pending', 'uploading', 'uploaded', 'failed']);
    $table->string('upload_url')->nullable();   // S3 pre-signed URL
    $table->string('s3_key')->nullable();
    $table->integer('retry_count')->default(0);
    $table->timestamp('recorded_at');
    $table->timestamps();
});
```

### Migration: `cached_meetings`

```php
Schema::create('cached_meetings', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('remote_id');    // ID on main backend
    $table->string('title');
    $table->string('join_code');
    $table->string('status');
    $table->json('participants')->nullable();
    $table->timestamp('scheduled_at')->nullable();
    $table->timestamp('synced_at');
    $table->timestamps();
});
```

### Migration: `cached_tasks`

```php
Schema::create('cached_tasks', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('remote_id');
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('priority');
    $table->string('status');
    $table->string('meeting_title')->nullable();
    $table->date('due_date')->nullable();
    $table->timestamp('synced_at');
    $table->timestamps();
});
```

### Migration: `app_settings`

```php
Schema::create('app_settings', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();
    $table->text('value')->nullable();
    $table->timestamps();
});
```

### Sync Strategy

```php
class SyncService
{
    public function __construct(
        private ApiClient $client,
    ) {}

    public function syncMeetings(): void
    {
        $response = $this->client->get('/meetings', [
            'status' => ['scheduled', 'active'],
            'per_page' => 20,
        ]);

        if ($response->successful()) {
            $meetings = $response->json('data');
            foreach ($meetings as $meeting) {
                CachedMeeting::updateOrCreate(
                    ['remote_id' => $meeting['id']],
                    [
                        'title' => $meeting['title'],
                        'join_code' => $meeting['join_code'],
                        'status' => $meeting['status'],
                        'participants' => $meeting['participants'],
                        'scheduled_at' => $meeting['scheduled_at'],
                        'synced_at' => now(),
                    ]
                );
            }
        }
    }

    public function syncTasks(): void
    {
        $response = $this->client->get('/tasks', [
            'status' => ['pending', 'in_progress'],
            'assigned_to' => 'me',
        ]);

        if ($response->successful()) {
            $tasks = $response->json('data');
            foreach ($tasks as $task) {
                CachedTask::updateOrCreate(
                    ['remote_id' => $task['id']],
                    [
                        'title' => $task['title'],
                        'description' => $task['description'],
                        'priority' => $task['priority'],
                        'status' => $task['status'],
                        'meeting_title' => $task['meeting']['title'] ?? null,
                        'due_date' => $task['due_date'],
                        'synced_at' => now(),
                    ]
                );
            }
        }
    }

    public function uploadPendingAudio(): void
    {
        $networkStatus = Network::status();
        if (! $networkStatus['connected'] || $networkStatus['isExpensive']) {
            return; // Skip upload on metered/no connection
        }

        $chunks = AudioChunk::where('status', 'pending')
            ->orderBy('recorded_at')
            ->limit(5)
            ->get();

        foreach ($chunks as $chunk) {
            $this->uploadChunk($chunk);
        }
    }

    private function uploadChunk(AudioChunk $chunk): void
    {
        $chunk->update(['status' => 'uploading']);

        try {
            // Get pre-signed URL from main backend
            if (! $chunk->upload_url) {
                $response = $this->client->post('/audio/presigned-url', [
                    'meeting_code' => $chunk->meeting_code,
                    'chunk_index' => $chunk->chunk_index,
                    'content_type' => 'audio/webm',
                ]);
                $chunk->update([
                    'upload_url' => $response->json('url'),
                    's3_key' => $response->json('key'),
                ]);
            }

            // Upload directly to S3
            Http::withHeaders(['Content-Type' => 'audio/webm'])
                ->put($chunk->upload_url, file_get_contents($chunk->file_path));

            $chunk->update(['status' => 'uploaded']);

            // Clean up local file
            if (file_exists($chunk->file_path)) {
                unlink($chunk->file_path);
            }
        } catch (\Exception $e) {
            $chunk->increment('retry_count');
            $chunk->update([
                'status' => $chunk->retry_count >= 3 ? 'failed' : 'pending',
            ]);
        }
    }
}
```

---

## 7. Audio Recording (Push-to-Talk)

### Approach: WebView MediaRecorder + RNNoise WASM

The audio pipeline runs entirely in the WebView's JavaScript context using the Web Audio API. This is the **same code as the web PWA version**, ensuring consistent noise cancellation quality.

**Why WebView over native Microphone plugin:**

- RNNoise WASM runs in AudioWorklet — native Microphone plugin bypasses this
- Same codebase for PWA and mobile app audio pipeline
- Noise cancellation is critical for transcription accuracy
- NativePHP's WebView supports Web Audio API, AudioWorklet, MediaRecorder

### Alpine.js Audio Component

```javascript
// resources/js/audio-pipeline.js
document.addEventListener('alpine:init', () => {
    Alpine.data('pushToTalk', () => ({
        isRecording: false,
        isInitialized: false,
        audioContext: null,
        rnnoiseNode: null,
        mediaRecorder: null,
        chunks: [],
        chunkIndex: 0,
        meetingCode: null,
        vadActive: false,

        async init() {
            this.meetingCode = this.$el.dataset.meetingCode;
        },

        async initAudio() {
            if (this.isInitialized) return;

            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: false, // We use RNNoise instead
                    sampleRate: 16000,
                },
            });

            this.audioContext = new AudioContext({ sampleRate: 16000 });
            const source = this.audioContext.createMediaStreamSource(stream);

            // Load RNNoise AudioWorklet
            await this.audioContext.audioWorklet.addModule('/js/rnnoise-processor.js');
            this.rnnoiseNode = new AudioWorkletNode(this.audioContext, 'rnnoise-processor');

            // Listen for VAD messages from RNNoise
            this.rnnoiseNode.port.onmessage = (event) => {
                this.vadActive = event.data.vadProbability > 0.7;
            };

            // Clean audio output
            const destination = this.audioContext.createMediaStreamDestination();
            source.connect(this.rnnoiseNode).connect(destination);

            // MediaRecorder on the clean stream
            this.mediaRecorder = new MediaRecorder(destination.stream, {
                mimeType: 'audio/webm;codecs=opus',
            });

            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.handleChunk(event.data);
                }
            };

            this.isInitialized = true;
        },

        async startRecording() {
            await this.initAudio();
            this.isRecording = true;
            this.mediaRecorder.start(5000); // 5-second chunks

            // Notify server via Livewire
            this.$wire.startedRecording();
        },

        stopRecording() {
            this.isRecording = false;
            this.mediaRecorder.stop();
            this.$wire.stoppedRecording();
        },

        async handleChunk(blob) {
            const arrayBuffer = await blob.arrayBuffer();
            const base64 = btoa(
                new Uint8Array(arrayBuffer).reduce(
                    (data, byte) => data + String.fromCharCode(byte),
                    ''
                )
            );

            // Send to Livewire for local storage + upload queue
            this.$wire.saveAudioChunk(base64, this.chunkIndex++, blob.size);
        },
    }));
});
```

### RNNoise AudioWorklet Processor

```javascript
// public/js/rnnoise-processor.js
// This file loads @jitsi/rnnoise-wasm and processes audio frames
// through the RNNoise neural network for noise suppression.
// See audio-processing.md spec for full implementation details.

class RNNoiseProcessor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.module = null;
        this.state = null;
        this.init();
    }

    async init() {
        // Load RNNoise WASM module
        const { createRNNoiseProcessor } = await import('/js/rnnoise-wasm/index.js');
        this.module = await createRNNoiseProcessor();
        this.state = this.module.newState();
    }

    process(inputs, outputs, parameters) {
        if (!this.state || !inputs[0] || !inputs[0][0]) {
            return true;
        }

        const input = inputs[0][0];
        const output = outputs[0][0];

        // RNNoise processes 480-sample frames (10ms at 48kHz)
        const vadProbability = this.module.processFrame(this.state, input);
        output.set(input); // RNNoise modifies in-place

        // Send VAD info to main thread
        this.port.postMessage({ vadProbability });

        return true;
    }
}

registerProcessor('rnnoise-processor', RNNoiseProcessor);
```

### MeetingRoom Livewire Component (Server Side)

```php
class MeetingRoom extends Component
{
    public string $meetingCode;
    public string $meetingTitle = '';
    public array $participants = [];
    public bool $isRecording = false;
    public string $connectionStatus = 'connecting';

    public function mount(string $code): void
    {
        $this->meetingCode = $code;

        $client = app(ApiClient::class);
        $response = $client->post("/meetings/{$code}/join", [
            'device_id' => Device::getId(),
        ]);

        if ($response->successful()) {
            $meeting = $response->json();
            $this->meetingTitle = $meeting['title'];
            $this->participants = $meeting['participants'];
            $this->connectionStatus = 'connected';
        }
    }

    public function startedRecording(): void
    {
        $this->isRecording = true;
        // Notify other participants via API -> WebSocket broadcast
        app(ApiClient::class)->post("/meetings/{$this->meetingCode}/recording-started");
    }

    public function stoppedRecording(): void
    {
        $this->isRecording = false;
        app(ApiClient::class)->post("/meetings/{$this->meetingCode}/recording-stopped");
    }

    public function saveAudioChunk(string $base64Data, int $chunkIndex, int $fileSize): void
    {
        $filePath = storage_path("app/audio/{$this->meetingCode}_{$chunkIndex}.webm");
        $directory = dirname($filePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filePath, base64_decode($base64Data));

        AudioChunk::create([
            'meeting_code' => $this->meetingCode,
            'chunk_index' => $chunkIndex,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'duration_seconds' => 5.0,
            'status' => 'pending',
            'recorded_at' => now(),
        ]);

        // Attempt immediate upload if online
        app(SyncService::class)->uploadPendingAudio();
    }

    public function render(): View
    {
        return view('livewire.meeting.room');
    }
}
```

---

## 8. Push Notifications

### Setup

1. **Create Firebase project** at console.firebase.google.com
2. **Download config files:**
    - Android: `google-services.json` -> `xaudition-mobile-app/nativephp/android/app/`
    - iOS: `GoogleService-Info.plist` -> `xaudition-mobile-app/nativephp/ios/App/`
3. **Install plugin:** `composer require nativephp/mobile-push-notifications`

### Registration Flow

```php
// In Login component, after successful auth:
public function registerForPush(): void
{
    PushNotifications::enroll(); // Requests permission + registers with FCM/APNS
}

// Listen for token event
#[OnNative(PushNotificationTokenReceived::class)]
public function handlePushToken(string $token): void
{
    $client = app(ApiClient::class);
    $client->post('/devices', [
        'device_id' => Device::getId(),
        'push_token' => $token,
        'platform' => System::isIos() ? 'ios' : 'android',
        'device_name' => Device::getInfo()['model'] ?? 'Unknown',
    ]);
}
```

### Notification Types (sent from main backend)

| Type                | Trigger                        | Content                            |
| ------------------- | ------------------------------ | ---------------------------------- |
| `meeting.starting`  | 5 min before scheduled meeting | "Meeting X starts in 5 minutes"    |
| `meeting.invite`    | Organizer invites participant  | "You've been invited to Meeting X" |
| `task.assigned`     | Action item assigned to user   | "New task: Review Q4 budget"       |
| `task.due_reminder` | 24h before task due date       | "Task X is due tomorrow"           |
| `minutes.ready`     | AI minutes generated           | "Meeting minutes for X are ready"  |

### Deep Link Handling

```php
// config/nativephp.php
'deeplink_scheme' => 'xdata',
'deeplink_host' => 'meetings',

// Push notification tapped -> deep link -> route
// xdata://meetings/join/ABC123 -> /meeting/ABC123
```

---

## 9. Offline Support

### Connectivity Detection

```php
// Network plugin (free)
$status = Network::status();
// Returns: ['connected' => true, 'type' => 'wifi', 'isExpensive' => false, 'isConstrained' => false]
```

### Offline Capabilities

| Feature            | Online                   | Offline                 |
| ------------------ | ------------------------ | ----------------------- |
| View meetings list | Live from API            | Cached in SQLite        |
| Join meeting       | Yes                      | No (requires WebSocket) |
| Record audio       | Yes (upload immediately) | Yes (queue locally)     |
| Upload audio       | Immediate                | Queued until online     |
| View tasks         | Live from API            | Cached in SQLite        |
| Update task status | Yes                      | Queued action           |
| Push notifications | Received                 | Queued by OS            |

### Connection Status UI Component

```blade
{{-- resources/views/components/connection-status.blade.php --}}
<div x-data="networkStatus" x-cloak>
    <div x-show="!connected"
         class="bg-amber-500 text-white text-center text-sm py-1 px-3">
        Offline - recordings will upload when connected
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('networkStatus', () => ({
        connected: true,
        init() {
            // Check via NativePHP bridge
            this.checkStatus();
            setInterval(() => this.checkStatus(), 10000);
        },
        async checkStatus() {
            try {
                const response = await fetch('/_native/api/call', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        method: 'Network.Status',
                        params: {}
                    })
                });
                const data = await response.json();
                this.connected = data.connected;
            } catch {
                this.connected = false;
            }
        }
    }));
});
</script>
```

---

## 10. Native UI (Edge Components)

### Base Layout

```blade
{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-50">
    {{-- Native Top Bar --}}
    <native:top-bar
        title="{{ $title ?? config('app.name') }}"
        :show-navigation-icon="{{ isset($showBack) && $showBack ? 'true' : 'false' }}"
    />

    {{-- Connection Status --}}
    <x-connection-status />

    {{-- Main Content --}}
    <main class="pb-16">
        {{ $slot }}
    </main>

    {{-- Native Bottom Navigation --}}
    @if($showNav ?? true)
    <native:bottom-nav>
        <native:bottom-nav-item
            id="meetings"
            label="Meetings"
            icon="calendar-today"
            url="/"
        />
        <native:bottom-nav-item
            id="tasks"
            label="Tasks"
            icon="check-circle"
            url="/tasks"
        />
        <native:bottom-nav-item
            id="settings"
            label="Settings"
            icon="settings"
            url="/settings"
        />
    </native:bottom-nav>
    @endif

    @livewireScripts
</body>
</html>
```

### Push-to-Talk Button (WebView, not Edge — needs dynamic state)

```blade
{{-- resources/views/components/push-to-talk-button.blade.php --}}
<div class="fixed bottom-24 left-0 right-0 flex justify-center z-50">
    <button
        x-on:touchstart.prevent="startRecording()"
        x-on:touchend.prevent="stopRecording()"
        x-on:mousedown.prevent="startRecording()"
        x-on:mouseup.prevent="stopRecording()"
        :class="isRecording
            ? 'bg-red-600 scale-110 shadow-lg shadow-red-500/50'
            : 'bg-blue-600 hover:bg-blue-700'"
        class="w-20 h-20 rounded-full flex items-center justify-center
               text-white transition-all duration-150 select-none touch-none"
    >
        <svg x-show="!isRecording" class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
            <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
        </svg>
        <div x-show="isRecording" class="flex space-x-1">
            <div class="w-1 h-6 bg-white rounded animate-pulse"></div>
            <div class="w-1 h-4 bg-white rounded animate-pulse" style="animation-delay: 0.1s"></div>
            <div class="w-1 h-8 bg-white rounded animate-pulse" style="animation-delay: 0.2s"></div>
            <div class="w-1 h-5 bg-white rounded animate-pulse" style="animation-delay: 0.3s"></div>
        </div>
    </button>
</div>
```

---

## 11. API Client Service

```php
class ApiClient
{
    private string $baseUrl;
    private string $version;

    public function __construct()
    {
        $this->baseUrl = config('api.base_url');
        $this->version = config('api.version');
    }

    public function get(string $endpoint, array $query = []): \Illuminate\Http\Client\Response
    {
        return $this->request('get', $endpoint, ['query' => $query]);
    }

    public function post(string $endpoint, array $data = []): \Illuminate\Http\Client\Response
    {
        return $this->request('post', $endpoint, ['json' => $data]);
    }

    public function put(string $endpoint, array $data = []): \Illuminate\Http\Client\Response
    {
        return $this->request('put', $endpoint, ['json' => $data]);
    }

    public function delete(string $endpoint): \Illuminate\Http\Client\Response
    {
        return $this->request('delete', $endpoint);
    }

    private function request(string $method, string $endpoint, array $options = []): \Illuminate\Http\Client\Response
    {
        $token = SecureStorage::get('api_token');

        $response = Http::withToken($token)
            ->timeout(config('api.timeout'))
            ->retry(config('api.retry_attempts'), config('api.retry_delay'))
            ->{$method}(
                $this->baseUrl . '/api/' . $this->version . $endpoint,
                $options['json'] ?? $options['query'] ?? []
            );

        // Handle 401 - attempt token refresh
        if ($response->status() === 401) {
            app(AuthService::class)->refreshToken();
            $token = SecureStorage::get('api_token');

            $response = Http::withToken($token)
                ->timeout(config('api.timeout'))
                ->{$method}(
                    $this->baseUrl . '/api/' . $this->version . $endpoint,
                    $options['json'] ?? $options['query'] ?? []
                );

            if ($response->status() === 401) {
                SecureStorage::delete('api_token');
                redirect('/login');
            }
        }

        return $response;
    }
}
```

---

## 12. Build & Deployment

### Development Workflow

```bash
# Run on iOS simulator
php artisan native:run ios

# Run on Android emulator
php artisan native:run android

# Hot reload (requires watchman)
php artisan native:run --watch

# Test on real device without compiling (requires Jump app)
php artisan native:jump
```

### Production Build

```bash
# Android APK/AAB
php artisan native:package android

# iOS IPA (requires macOS + Xcode)
php artisan native:package ios

# Publish to stores
php artisan native:release
```

### CI/CD (separate from main backend)

```yaml
# .github/workflows/mobile-build.yml
name: Mobile Build
on:
    push:
        branches: [main]
        paths: ['**']

jobs:
    android:
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v4
            - uses: actions/setup-java@v4
              with: { java-version: '17' }
            - run: composer install --no-dev
            - run: npm ci && npm run build
            - run: php artisan native:package android

    ios:
        runs-on: macos-latest
        steps:
            - uses: actions/checkout@v4
            - run: composer install --no-dev
            - run: npm ci && npm run build
            - run: php artisan native:package ios
```

### Environment Cleanup

NativePHP automatically removes sensitive env vars from the bundled `.env` file based on `cleanup_env_keys` in `config/nativephp.php`. Only `APP_NAME`, `APP_ENV`, `API_BASE_URL`, and `API_VERSION` remain in the production bundle.

---

## 13. Known Limitations & Mitigations

| Limitation                           | Impact                                       | Mitigation                                                             |
| ------------------------------------ | -------------------------------------------- | ---------------------------------------------------------------------- |
| No background processing (Issue #7)  | Can't upload audio while app is backgrounded | Queue chunks locally, upload when app returns to foreground            |
| Single-threaded PHP                  | Heavy PHP computation blocks WebView UI      | Keep audio processing in JS (AudioWorklet), PHP only handles API calls |
| SQLite only                          | No complex queries, no full-text search      | Use remote API for search, SQLite for cache only                       |
| WebView-based UI                     | Not pixel-perfect native look                | Tailwind CSS mobile-first design, Edge components for native chrome    |
| Livewire 4 compatibility (Issue #13) | Some Livewire features may not work          | Use Alpine.js fallback for critical interactions, monitor issue        |
| No offline sync framework            | Manual sync code required                    | Custom SyncService with conflict resolution at API level               |
| App bundle size                      | Each plugin adds native code                 | Only install required plugins, keep deps minimal                       |

---

## 14. Cost Summary

| Item                        | Cost     | Type         |
| --------------------------- | -------- | ------------ |
| NativePHP Mobile v3         | Free     | MIT License  |
| Free plugins (9)            | Free     | MIT License  |
| **Total MVP**               | **$0**   | **Free**     |
| Apple Developer account     | $99/yr   | Annual       |
| Google Play developer       | $25      | One-time     |

**Premium plugins (deferred — build free alternatives first):**

| Plugin | Price | Our Alternative |
|--------|-------|----------------|
| SecureStorage | $49 | Encrypted cookies + local SQLite |
| Firebase Push | $49 | WebSocket polling via Reverb |
| Biometrics | $49 | Not needed for MVP |
| Starter Kit (all 5) | $199 | Purchase later if free alternatives prove insufficient |

---

## 15. Phase Mapping

### Phase 2 (after web PWA launch)

| Task               | Description                                               | Effort         |
| ------------------ | --------------------------------------------------------- | -------------- |
| Project setup      | Create xaudition-mobile-app, install NativePHP + plugins | 1 day          |
| Auth flow          | Login, SecureStorage, token refresh                       | 1 day          |
| API client         | ApiClient service, error handling, retry                  | 1 day          |
| Meeting room       | Push-to-talk + RNNoise + upload pipeline                  | 3 days         |
| Task list          | View/update assigned tasks                                | 1 day          |
| Push notifications | Firebase setup, registration, deep links                  | 1.5 days       |
| Offline support    | Audio queue, connectivity detection, sync                 | 2 days         |
| Testing            | Device testing on iOS + Android                           | 2 days         |
| **Total**          |                                                           | **~12.5 days** |

### Phase 3 Additions

| Task                 | Description                          | Effort   |
| -------------------- | ------------------------------------ | -------- |
| Biometric auth       | Face ID / fingerprint unlock         | 1 day    |
| Voice enrollment     | 20-30 sec recording for speaker ID   | 1.5 days |
| Background recording | Native Microphone plugin as fallback | 1 day    |
| App store submission | Screenshots, descriptions, review    | 2 days   |

---

## 16. API Endpoints Required (main backend)

The mobile app requires these API endpoints on the main backend (`xdata-audition`).
All endpoints below are implemented as of March 2026 (1055+ tests passing).

### Already Implemented (in `routes/api.php`)

#### Authentication & User Profile

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| POST | `/api/v1/auth/register` | `Api\V1\AuthController@register` | Working |
| POST | `/api/v1/auth/login` | `Api\V1\AuthController@login` | Working |
| POST | `/api/v1/auth/logout` | `Api\V1\AuthController@logout` | Working (auth:sanctum) |
| GET | `/api/v1/me` | `Api\V1\AuthController@me` | Working (auth:sanctum) |

#### Cross-Tenant Meeting Join (mobile deep-link)

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| POST | `/api/v1/join/token` | `Api\V1\MeetingJoinController@joinByToken` | Working (CrossTenantMeetingAccess) |
| POST | `/api/v1/join/code` | `Api\V1\MeetingJoinController@joinByCode` | Working (CrossTenantMeetingAccess) |

#### Personal Cross-Tenant Data (tenantless users)

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/v1/my/meetings` | `Api\V1\MyController@meetings` | Working (UserMeetingIndex) |
| GET | `/api/v1/my/tasks` | `Api\V1\MyController@tasks` | Working (enriched with retention_expires_at) |
| PATCH | `/api/v1/my/tasks/{id}` | `Api\V1\MyController@updateTask` | Working |

#### Participant Groups

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/v1/groups` | `Api\V1\ParticipantGroupController@index` | Working |
| POST | `/api/v1/groups` | `Api\V1\ParticipantGroupController@store` | Working |
| PATCH | `/api/v1/groups/{group}` | `Api\V1\ParticipantGroupController@update` | Working |
| DELETE | `/api/v1/groups/{group}` | `Api\V1\ParticipantGroupController@destroy` | Working |
| POST | `/api/v1/groups/{group}/members` | `Api\V1\ParticipantGroupController@addMember` | Working |
| DELETE | `/api/v1/groups/{group}/members/{member}` | `Api\V1\ParticipantGroupController@removeMember` | Working |

#### Cross-Meeting Action Items

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/v1/action-items` | `Api\V1\ActionItemController@myItems` | Working |

#### Meetings CRUD & Lifecycle

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/v1/meetings` | `Api\V1\MeetingController@index` | Working |
| POST | `/api/v1/meetings` | `Api\V1\MeetingController@store` | Working |
| GET | `/api/v1/meetings/{meeting}` | `Api\V1\MeetingController@show` | Working |
| POST | `/api/v1/meetings/{meeting}/start` | `Api\V1\MeetingController@start` | Working |
| POST | `/api/v1/meetings/{meeting}/end` | `Api\V1\MeetingController@end` | Working |
| POST | `/api/v1/meetings/{meeting}/join` | `Api\V1\MeetingController@join` | Working (throttle:10/min) |
| GET | `/api/v1/meetings/{meeting}/participants` | `Api\V1\MeetingController@participants` | Working |
| POST | `/api/v1/meetings/{meeting}/invite-group/{group}` | `Api\V1\ParticipantGroupController@inviteToMeeting` | Working |

#### Audio Upload

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| POST | `/api/v1/meetings/{meeting}/audio/presign` | `Api\V1\AudioUploadController@presign` | Working |
| POST | `/api/v1/meetings/{meeting}/audio/confirm` | `Api\V1\AudioUploadController@confirm` | Working |
| POST | `/api/v1/meetings/{meeting}/audio/upload` | `Api\V1\AudioUploadController@uploadMobile` | Working |

#### Transcript & Action Items (per-meeting)

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/v1/meetings/{meeting}/action-items` | `Api\V1\ActionItemController@index` | Working |
| PUT | `/api/v1/meetings/action-items/{actionItem}` | `Api\V1\ActionItemController@update` | Working |
| GET | `/api/v1/meetings/{meeting}/transcript` | `Api\V1\TranscriptController@show` | Working |
| GET | `/api/v1/meetings/{meeting}/transcript/status` | `Api\V1\TranscriptController@status` | Working |

#### Secretary Markers (feature:secretary_markers)

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/v1/meetings/{meeting}/markers` | `Api\V1\SecretaryMarkerController@index` | Working |
| POST | `/api/v1/meetings/{meeting}/markers` | `Api\V1\SecretaryMarkerController@store` | Working |
| GET | `/api/v1/meetings/{meeting}/markers/{marker}` | `Api\V1\SecretaryMarkerController@show` | Working |
| PUT | `/api/v1/meetings/{meeting}/markers/{marker}` | `Api\V1\SecretaryMarkerController@update` | Working |
| DELETE | `/api/v1/meetings/{meeting}/markers/{marker}` | `Api\V1\SecretaryMarkerController@destroy` | Working |

#### Mic Control & Floor Management

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| POST | `/api/v1/meetings/{meeting}/participants/{participant}/mic` | `Api\V1\MicControlController@setMic` | Working (organizer/chairperson or self-unmute) |
| POST | `/api/v1/meetings/{meeting}/participants/mute-all` | `Api\V1\MicControlController@muteAll` | Working (organizer/chairperson) |
| POST | `/api/v1/meetings/{meeting}/participants/{participant}/chairperson` | `Api\V1\MicControlController@setChairperson` | Working (organizer only) |

#### Floor Control (feature:floor_control)

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| POST | `/api/v1/meetings/{meeting}/floor/request` | `Api\V1\FloorControlController@request` | Working |
| POST | `/api/v1/meetings/{meeting}/floor/release` | `Api\V1\FloorControlController@release` | Working |
| POST | `/api/v1/meetings/{meeting}/floor/grant/{participant}` | `Api\V1\FloorControlController@grant` | Working |
| POST | `/api/v1/meetings/{meeting}/floor/revoke` | `Api\V1\FloorControlController@revoke` | Working |
| GET | `/api/v1/meetings/{meeting}/floor/status` | `Api\V1\FloorControlController@status` | Working |

#### Room Capture (feature:room_capture)

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| POST | `/api/v1/meetings/{meeting}/room-audio/presign` | `Api\V1\RoomCaptureController@presign` | Working |
| POST | `/api/v1/meetings/{meeting}/room-audio/confirm` | `Api\V1\RoomCaptureController@confirm` | Working |
| POST | `/api/v1/meetings/{meeting}/segments/{segment}/assign` | `Api\V1\SegmentAttributionController@assign` | Working |
| POST | `/api/v1/meetings/{meeting}/segments/bulk-assign` | `Api\V1\SegmentAttributionController@bulkAssign` | Working |

#### Notifications

| Method | Endpoint | Controller | Status |
|--------|----------|-----------|--------|
| GET | `/api/v1/notifications/meetings` | `Api\V1\MeetingNotificationController@index` | Working |
| POST | `/api/v1/notifications/{notification}/read` | `Api\V1\MeetingNotificationController@markRead` | Working |

#### ParticipantResource Fields

The `ParticipantResource` includes these fields for each participant:
- `id`, `user_id`, `user` (name, email), `role`, `status`
- `can_record` — server-side recording permission (hard gate)
- `is_mic_enabled` — floor control signal (soft, participant can self-unmute)
- `is_chairperson` — chairperson flag (organizer can assign)

### Remaining (not yet built)

| Method | Endpoint | Purpose | Notes |
|--------|----------|---------|-------|
| POST | `/api/v1/auth/refresh` | Issue new token, revoke old | Nice-to-have; apps can logout/login instead |
| POST | `/api/v1/devices` | Register FCM/APNS push token | Deferred — using Reverb WebSocket polling for MVP |
| DELETE | `/api/v1/devices/{device}` | Unregister device | Deferred — using Reverb WebSocket polling for MVP |

---

## 17. Mobile Feature Roadmap

Maps completed backend tracks to mobile screens.

### MVP Screens

| Screen | Backend Track | API Endpoints Used |
|--------|-------------|-------------------|
| Login / Register | Phase 3 (auth API) | `POST /auth/login`, `POST /auth/register` |
| Meeting List | Track 02 + Phase 3 | `GET /my/meetings` (cross-tenant via UserMeetingIndex) |
| Join Meeting | Track 02 + Phase 4 | `POST /join/token`, `POST /join/code` (cross-tenant deep-link) |
| Meeting Room (PTT) | Track 03 | `POST /audio/presign`, `/confirm`, `/upload` |
| Mic Control | Phase 8 (mic control) | `POST /participants/{id}/mic` (self-unmute), mute indicator from Reverb |
| Live Transcript | Track 04 | `GET /transcript`, `/transcript/status` |
| My Tasks | Track 06 + Phase 3 | `GET /my/tasks` (cross-tenant, enriched with retention_expires_at) |
| Task Detail | Track 06 | `PATCH /my/tasks/{id}` or `PUT /action-items/{id}` |
| Settings | — | Local SQLite + `POST /auth/logout` + `GET /me` |

### Post-MVP Screens

| Screen | Backend Track | Description |
|--------|-------------|-------------|
| Secretary Toolbar | Track 08 | Place markers during active meeting (`POST /markers`) |
| Floor Control | Track 08 | Request/grant/release floor (`POST /floor/*`) |
| Mic Management | Phase 8 | Organizer/chairperson mute/unmute all (`POST /mute-all`) |
| Participant Groups | Phase 5 | Manage reusable invite lists (`/groups/*`) |
| Invite Group to Meeting | Phase 5 | Bulk invite (`POST /meetings/{id}/invite-group/{group}`) |
| Minutes Viewer | Track 05 | View approved meeting minutes (HTML) |
| Notification Center | Track 06 | Full notification history (`GET /notifications/meetings`) |
| Calendar View | Track 09 | Upcoming meetings from calendar sync |

### Free Plugin Alternatives

| Premium Feature | Free Alternative |
|----------------|-----------------|
| SecureStorage (token) | Laravel encrypted session cookies in WebView + fallback to local SQLite `app_settings` table with application-level encryption |
| Firebase Push | WebSocket polling via Laravel Reverb when app is foregrounded; local notification scheduling for reminders |
| Biometrics | Deferred — standard password/PIN login for MVP |

---

## 18. Broadcast Events (Reverb WebSocket)

The mobile app should connect to the `meeting.{meetingId}` presence channel via Laravel Echo and listen for these events:

### Meeting Lifecycle

| Event | Payload | Use |
|-------|---------|-----|
| `.MeetingStarted` | `meeting_id` | Navigate to meeting room |
| `.MeetingEnded` | `meeting_id` | Show "meeting ended" state, disable PTT |
| `.MeetingCancelled` | `meeting_id` | Navigate away |

### Participants

| Event | Payload | Use |
|-------|---------|-----|
| `.ParticipantJoined` | `participant_id, user_id, user_name, role` | Update participant list |
| `.ParticipantLeft` | `participant_id` | Update participant list |
| `.RecordingPermissionChanged` | `participant_id, can_record` | Update PTT button enabled state |

### Mic Control & Floor Management

| Event | Payload | Use |
|-------|---------|-----|
| `.MicStateChanged` | `participant_id, is_mic_enabled` | Show mute indicator; if self, show "unmute" banner |
| `.ChairpersonAssigned` | `participant_id, is_chairperson` | Show chairperson badge; enable mic controls if self |
| `.FloorGranted` | `participant_id` | Show floor indicator |
| `.FloorReleased` | `participant_id` | Clear floor indicator |

### Audio & Transcript

| Event | Payload | Use |
|-------|---------|-----|
| `.ParticipantRecordingStarted` | `participant_id` | Show speaking indicator |
| `.ParticipantRecordingStopped` | `participant_id` | Clear speaking indicator |
| `.AudioSegmentUploaded` | `segment_id, participant_id` | Update upload progress |
| `.TranscriptSegmentReady` | `segment` | Append to live transcript view |

### Secretary Markers

| Event | Payload | Use |
|-------|---------|-----|
| `.MarkerPlaced` | `marker_id, type, label, timestamp` | Show marker in transcript view |
