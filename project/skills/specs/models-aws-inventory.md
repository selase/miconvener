# Models & AWS Services Inventory

## All Models

### Existing Models (Starter Kit - Already Built)

These 31 models exist in the starter kit and are ready to use:

| # | Model | Table | Domain | Used By Meeting Feature |
|---|---|---|---|---|
| 1 | `User` | `users` | Auth | Yes - organizer, participants, assignees |
| 2 | `Tenant` | `tenants` | Tenancy | Yes - scopes all meeting data |
| 3 | `Role` | `roles` | Auth | Yes - meeting roles map to permissions |
| 4 | `Permission` | `permissions` | Auth | Yes - meeting-specific permissions |
| 5 | `Feature` | `features` | Features | Yes - meeting feature flags registered here |
| 6 | `TenantFeature` | `tenant_features` | Features | Yes - which meeting features tenant has |
| 7 | `TenantFeatureUsage` | `tenant_feature_usages` | Features | Yes - tracks meeting count, duration usage |
| 8 | `TenantLlmConfig` | `tenant_llm_configs` | LLM | Yes - AI model config for minutes generation |
| 9 | `TenantApiKey` | `tenant_api_keys` | API | Indirect - mobile app auth |
| 10 | `Package` | `packages` | Billing | Yes - plan determines meeting limits |
| 11 | `PackageFeature` | `package_features` | Billing | Yes - maps packages to meeting features |
| 12 | `Subscription` | `subscriptions` | Billing | Yes - active sub determines access |
| 13 | `Invoice` | `invoices` | Billing | Indirect - meeting usage on invoices |
| 14 | `InvoiceItem` | `invoice_items` | Billing | Indirect - meeting line items |
| 15 | `Transaction` | `transactions` | Billing | No |
| 16 | `MerchantTransaction` | `merchant_transactions` | Billing | No |
| 17 | `Tax` | `taxes` | Billing | No |
| 18 | `UsagePrice` | `usage_prices` | Billing | Yes - meeting usage pricing |
| 19 | `UsageEvent` | `usage_events` | Usage | Yes - records meeting events |
| 20 | `UsageRollup` | `usage_rollups` | Usage | Yes - aggregated meeting metrics |
| 21 | `UsageLimit` | `usage_limits` | Usage | Yes - meeting count/duration limits |
| 22 | `LlmTokenUsage` | `llm_token_usages` | LLM | Yes - tracks AI token cost per meeting |
| 23 | `LlmUsageSummary` | `llm_usage_summaries` | LLM | Yes - aggregated AI usage |
| 24 | `WebhookEndpoint` | `webhook_endpoints` | Webhooks | Yes - meeting events trigger webhooks |
| 25 | `WebhookCall` | `webhook_calls` | Webhooks | Yes - delivery tracking |
| 26 | `LoginLog` | `login_logs` | Audit | No |
| 27 | `UserLoginHistory` | `user_login_histories` | Audit | No |
| 28 | `Lead` | `leads` | Marketing | No |
| 29 | `Post` | `posts` (tenant) | Content | No |
| 30 | `Activity` | `activity_log` | Audit | Yes - logs meeting actions |
| 31 | `HealthCheck` | `health` tables | Monitoring | No |

---

### New Models (To Be Built)

| # | Model | Table | Phase | Description |
|---|---|---|---|---|
| 1 | `Meeting` | `meetings` | MVP | Core meeting entity - title, status, recording mode, join code |
| 2 | `MeetingParticipant` | `meeting_participants` | MVP | Participant in a meeting - role, invitation, voice enrollment |
| 3 | `AudioSegment` | `audio_segments` | MVP | Audio chunk metadata - S3 key, duration, processing status |
| 4 | `TranscriptionJob` | `transcription_jobs` | MVP | AWS Transcribe job tracker - status, cost, confidence |
| 5 | `TranscriptSegment` | `transcript_segments` | MVP | Transcribed text - speaker attribution, timestamps, confidence |
| 6 | `ActionItem` | `action_items` | MVP | Task extracted from meeting - assignee, priority, due date |
| 7 | `MeetingMinutes` | `meeting_minutes` | MVP | Generated minutes document - Markdown, PDF, version, approval |
| 8 | `TaskReminder` | `task_reminders` | MVP | Scheduled reminder for action items |
| 9 | `MeetingNotification` | `meeting_notifications` | MVP | Meeting notification log - type, channel, delivery status |
| 10 | `SecretaryMarker` | `secretary_markers` | Phase 2 | Live marker during meeting - decision, action, risk, note |

**Total model count: 31 existing + 10 new = 41 models**

---

### Model Relationship Summary (New Models)

```
Meeting
├── belongsTo: Tenant, User (organizer)
├── hasMany: MeetingParticipant, AudioSegment, TranscriptionJob,
│            TranscriptSegment, ActionItem, MeetingMinutes,
│            MeetingNotification, SecretaryMarker
└── scopes: byTenant, byStatus, upcoming, active, completed

MeetingParticipant
├── belongsTo: Meeting, User (nullable - external participants)
├── hasMany: AudioSegment, SecretaryMarker (if secretary)
└── scopes: byRole, joined, invited

AudioSegment
├── belongsTo: Meeting, MeetingParticipant
├── hasOne: TranscriptionJob
└── scopes: byStatus, uploaded, transcribed

TranscriptionJob
├── belongsTo: Meeting, AudioSegment
├── hasMany: TranscriptSegment
└── scopes: pending, completed, failed

TranscriptSegment
├── belongsTo: Meeting, MeetingParticipant, TranscriptionJob
├── hasMany: SecretaryMarker (linked by timestamp)
└── scopes: chronological, byParticipant, unattributed

ActionItem
├── belongsTo: Meeting, MeetingParticipant (assigned_to), User (assigned_to_user)
├── hasMany: TaskReminder
├── belongsTo: TranscriptSegment (source - nullable)
└── scopes: pending, overdue, byAssignee, byPriority

MeetingMinutes
├── belongsTo: Meeting, User (approved_by)
└── scopes: latest, approved, draft

TaskReminder
├── belongsTo: ActionItem, User
└── scopes: pending, due

MeetingNotification
├── belongsTo: Meeting, User
└── scopes: unread, byType

SecretaryMarker
├── belongsTo: Meeting, MeetingParticipant (secretary), TranscriptSegment
└── scopes: byType, chronological
```

---

## AWS Services Inventory

### Core Services (MVP)

| # | Service | Purpose | Usage | Cost Model |
|---|---|---|---|---|
| 1 | **S3** | Audio storage + transcript output | Upload audio chunks, store transcripts, PDFs, voice enrollments | $0.023/GB storage + $0.005/1K requests |
| 2 | **Transcribe** | Speech-to-text | Convert audio segments to text | $0.024/min (batch), $0.025/min (streaming) |
| 3 | **Bedrock** | AI processing (Claude) | Transcript cleanup, action extraction, minutes generation | Per-token pricing (Haiku ~$0.003/call, Sonnet ~$0.015/call) |
| 4 | **SQS** | Message queuing | Transcription completion notifications, job orchestration | $0.40/1M requests |
| 5 | **EventBridge** | Event routing | Transcribe job completion -> SQS -> Laravel worker | $1.00/1M events |
| 6 | **SES** | Email delivery | Meeting invitations, task assignments, reminders, minutes | $0.10/1K emails |
| 7 | **CloudWatch** | Monitoring + logging | Lambda logs, metrics, alarms, cost tracking | $0.30/GB logs ingested |
| 8 | **IAM** | Access management | Service roles, S3 policies, Lambda permissions | Free |

### Compute Services

| # | Service | Purpose | Usage | Cost Model |
|---|---|---|---|---|
| 9 | **Lambda** | Serverless compute | Audio processing, transcription orchestration | $0.20/1M requests + $0.0000166/GB-sec |
| 10 | **EC2** (or Forge/Herd) | Application hosting | Laravel app, Reverb WebSocket, Horizon workers | Instance-based or managed |
| 11 | **RDS** | PostgreSQL hosting | Landlord + tenant databases | Instance-based ($15-50/mo for small) |
| 12 | **ElastiCache** | Redis hosting | Queues, cache, sessions, broadcasting | Instance-based ($12-25/mo for small) |

### Phase 2 Services

| # | Service | Purpose | Phase |
|---|---|---|---|
| 13 | **Transcribe (Custom Vocabulary)** | Domain-specific term accuracy | Phase 2 (T08.5) |
| 14 | **Transcribe (Speaker Diarization)** | Room capture speaker separation | Phase 2 (T08.3) |

### Phase 3 Services

| # | Service | Purpose | Phase |
|---|---|---|---|
| 15 | **Transcribe Streaming** | Real-time live captions | Phase 3 (T09.1) |
| 16 | **Route53** | DNS management | Phase 3 (custom domains) |
| 17 | **ACM** | SSL certificates | Phase 3 (custom domains) |

---

### AWS Service Flow

```
[Phone] --audio--> [S3 Bucket]
                       |
                       ├── S3 Event --> [Lambda: AudioProcessor]
                       |                    |
                       |                    └── Starts [Transcribe Job]
                       |                             |
                       |                             ├── Output --> [S3: /transcripts/]
                       |                             └── Event --> [EventBridge]
                       |                                              |
                       |                                              └── [SQS Queue]
                       |                                                     |
                       |                                                     └── [Laravel Worker]
                       |                                                            |
                       |                                                            ├── Parse transcript
                       |                                                            ├── Store segments
                       |                                                            └── Trigger AI pipeline
                       |                                                                   |
                       |                                                                   ├── [Bedrock: Haiku]
                       |                                                                   │    ├── Cleanup
                       |                                                                   │    └── Extract actions
                       |                                                                   │
                       |                                                                   └── [Bedrock: Sonnet]
                       |                                                                        └── Generate minutes
                       |
                       └── PDF minutes --> [S3: /minutes/]
                                              |
                                              └── [SES] --> Email to participants
```

---

### Lambda Functions

| Function | Runtime | Trigger | Purpose |
|---|---|---|---|
| `audio-processor` | Python 3.11 | S3 ObjectCreated | Validate audio, start Transcribe job |
| `transcription-orchestrator` | Python 3.11 | EventBridge (Transcribe complete) | Fetch result, push to SQS for Laravel |
| `voice-embedding-extractor` | Python 3.11 | S3 (enrollment audio) | Extract SpeechBrain embeddings (Phase 2) |

---

### Third-Party Services (Non-AWS)

| Service | Purpose | Phase | Cost |
|---|---|---|---|
| **Stripe** | Payment processing | MVP (existing) | 2.9% + $0.30/transaction |
| **Paystack** | African payment processing | MVP (existing) | 1.5% + fees |
| **RNNoise WASM** | Client-side noise cancellation | MVP | Free (open source) |
| **SpeechBrain ECAPA-TDNN** | Speaker embedding extraction | Phase 2 | Free (open source, compute cost only) |
| **Google Calendar API** | Calendar integration | Phase 3 | Free (quota limits) |
| **Microsoft Graph API** | Outlook calendar integration | Phase 3 | Free (quota limits) |
| **Jira/Asana/Linear APIs** | PM tool integration | Phase 3 | Free (API access) |
| **Slack API** | Team communication | Phase 3 | Free (bot tokens) |
