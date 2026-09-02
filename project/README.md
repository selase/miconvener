# Meeting Minutes & Action Tracker SaaS
## Complete Technical Specification & Implementation Guide

**Version:** 2.0  
**Last Updated:** February 2025  
**Technology:** Laravel 12, React PWA, NativePHP Mobile, AWS Serverless  
**Target Market:** Corporate teams, Healthcare, Legal, Education, Government  

---

## 📋 Document Overview

This is a comprehensive specification for building a meeting minutes and action tracking SaaS platform that uses participants' phones as microphones for in-person meetings. The system provides automated transcription, AI-powered action item extraction, and professional meeting minutes generation.

**Total Pages:** 5 documents  
**Estimated Reading Time:** 2-3 hours  
**Implementation Time:** 6-8 weeks with 2-3 developers  

---

## 📚 Document Structure

### [Part 1: Executive Summary & System Architecture](./01-executive-summary-architecture.md)

**What you'll learn:**
- Problem statement and market opportunity
- Solution overview and key innovations
- Complete system architecture diagram
- Technology stack (Laravel 12, React PWA, NativePHP Mobile, AWS)
- Core workflow and user experience
- Speaker identification strategy (session-based vs. diarization)
- Recording mode comparison (push-to-talk, floor control, open mics)
- Client application architecture (PWA + Native Mobile)
- Integration with your existing Laravel 12 starter kit

**Key Sections:**
1. Executive Summary
2. Core Architecture
3. Technology Stack
4. High-Level System Architecture
5. NativePHP Mobile Integration
6. Core Workflow & User Experience
7. Recording Strategy Decision Matrix
8. Speaker Identification Strategy
9. Client Applications (React PWA & NativePHP)
10. Integration with Existing Starter Kit

**Time to Read:** 30-40 minutes

---

### [Part 2: Database Schema & Data Model](./02-database-schema.md)

**What you'll learn:**
- Complete PostgreSQL database schema
- All table definitions with constraints and indexes
- Relationships between entities
- Data model best practices (multi-tenancy, audit trails, soft deletes)
- Optimized views for common queries
- Usage tracking and metrics tables

**Key Tables:**
- `meetings` - Core meeting entity
- `meeting_participants` - Who's in the meeting
- `audio_segments` - Temporary audio storage
- `transcript_segments` - Transcribed text with speaker attribution
- `action_items` - Tasks extracted from meetings
- `meeting_minutes` - Final professional output
- `secretary_markers` - Live markers during meeting
- `notifications` & `task_reminders` - Communication system
- `transcription_jobs` - AWS Transcribe job tracking
- `usage_metrics` - Billing and analytics
- `audit_logs` - Security and compliance

**Time to Read:** 45-60 minutes

---

### [Part 3: Implementation Phases & Development Roadmap](./03-implementation-roadmap.md)

**What you'll learn:**
- 8-week MVP development plan
- Week-by-week breakdown of tasks
- Phase 2 enhancement features (weeks 9-14)
- Phase 3 enterprise features (weeks 15-20)
- Post-launch continuous improvement strategy
- Metrics to track (product, business, technical)
- Feedback loops and user research
- Team structure recommendations
- Budget estimates for development and operations
- Risk mitigation strategies

**Implementation Phases:**

**Phase 1: Ship MVP (Weeks 1-8)**
- Week 1-2: Foundation & Core Infrastructure
- Week 3-4: PWA & Native Mobile
- Week 5: AWS Transcription Pipeline
- Week 6: AI Processing & Task Distribution
- Week 7: Testing & Bug Fixes
- Week 8: Launch Preparation & MVP Release

**Phase 2: "Feels Magical" (Weeks 9-14)**
- Secretary live markers
- Floor control system
- Room capture fallback
- Advanced minutes editor
- Custom vocabulary & domain accuracy

**Phase 3: Enterprise Features (Weeks 15-20)**
- Real-time live captions
- Calendar integrations (Google, Outlook)
- Project management integrations (Jira, Asana, Linear)
- Team communication integrations (Slack, Teams)
- Compliance & governance features

**Time to Read:** 40-50 minutes

---

### [Part 4: Technical Implementation - Backend & AWS](./04-backend-implementation.md)

**What you'll learn:**
- Laravel controller implementations with complete code
- Queue job implementations (CleanupTranscript, ExtractActionItems, GenerateMinutes)
- AI provider service architecture
- AWS Bedrock integration for Claude AI
- AWS Lambda function implementations (Python)
- Error handling and retry strategies
- Webhook and EventBridge integrations

**Key Code Examples:**
- `MeetingController` - Complete CRUD with S3 pre-signed URLs
- `ProcessMeetingJob` - Orchestrates end-to-end workflow
- `CleanupTranscriptJob` - AI-powered text cleanup
- `ExtractActionItemsJob` - Action item extraction with assignee matching
- `GenerateMeetingMinutesJob` - Professional minutes generation
- `AIProvider` interface and `BedrockProvider` implementation
- Lambda functions: `audio_processor.py`, `transcription_orchestrator.py`

**Technologies Covered:**
- Laravel 12 (Controllers, Jobs, Services)
- AWS SDK for PHP
- AWS Bedrock (Claude Haiku & Sonnet)
- AWS Lambda (Python 3.11)
- AWS Transcribe
- S3 pre-signed URLs
- EventBridge

**Time to Read:** 60-75 minutes

---

### [Part 5: Frontend Implementation & Cost Analysis](./05-frontend-cost-analysis.md)

**What you'll learn:**
- Complete React PWA implementation with TypeScript
- Push-to-talk recorder component (production-ready)
- Offline storage service using IndexedDB
- Background audio recording with NativePHP
- Offline synchronization strategies
- Complete cost breakdown by AWS service
- Per-meeting cost calculation
- Cost optimization strategies with actual savings
- Pricing strategy and profit margin analysis
- Break-even analysis
- First-year revenue projections

**Key Code Examples:**
- `PushToTalkRecorder.tsx` - Full recording component with visual feedback
- `offlineStorage.ts` - IndexedDB service for failed uploads
- `BackgroundAudioRecorder.php` - NativePHP background recording
- Cost calculation spreadsheets
- Pricing tier recommendations

**Cost Analysis Highlights:**
- **Total monthly infrastructure:** $568.23 for 100 meetings
- **Per-meeting cost:** $5.68
- **Largest expense:** AWS Transcribe ($432/month - 76% of total)
- **Break-even point:** 10 Professional plan customers ($199/month)
- **Cost optimization:** Up to 80% savings with floor control mode

**Time to Read:** 50-60 minutes

---

## 🎯 Quick Start Guide

### For Developers

1. **Start with Part 1** to understand the overall architecture
2. **Read Part 2** to understand the data model
3. **Follow Part 3** for step-by-step development plan
4. **Reference Part 4 & 5** during implementation for code examples

### For Product Managers

1. **Part 1** - Understand market opportunity and solution
2. **Part 3** - Review development roadmap and timeline
3. **Part 5** - Understand pricing strategy and unit economics

### For CTOs / Technical Leaders

1. **Part 1** - Architecture and technology decisions
2. **Part 2** - Data model and scalability considerations
3. **Part 5** - Cost analysis and infrastructure planning

---

## 🔑 Key Features

### Core Features (MVP - Week 8)
- ✅ Meeting creation and participant invitations
- ✅ Push-to-talk recording via phone (PWA + Native apps)
- ✅ Automatic transcription with AWS Transcribe
- ✅ Session-based speaker attribution (100% accurate)
- ✅ AI-powered transcript cleanup
- ✅ Automated action item extraction
- ✅ Task email distribution and reminders
- ✅ Professional meeting minutes generation
- ✅ Offline audio buffering and sync

### Enhanced Features (Phase 2 - Week 14)
- ✅ Secretary live markers (Decision, Action, Risk, etc.)
- ✅ Floor control system (one speaker at a time)
- ✅ Room capture fallback with diarization
- ✅ Advanced minutes editor with version control
- ✅ Custom vocabulary for domain-specific terms

### Enterprise Features (Phase 3 - Week 20)
- ✅ Real-time live captions
- ✅ Calendar integrations (Google, Outlook)
- ✅ Project management integrations (Jira, Asana, Linear, Monday)
- ✅ Team communication integrations (Slack, Microsoft Teams)
- ✅ Compliance features (GDPR, HIPAA, SOC 2)
- ✅ White-label option

---

## 💰 Business Model

### Target Pricing

```
Starter: $49/month
├─ 5 meetings
├─ 10 participants max
├─ 2-hour max duration
├─ Text-only storage
└─ 42% profit margin

Professional: $199/month
├─ 25 meetings
├─ 50 participants max
├─ 4-hour max duration
├─ 30-day audio retention
└─ 29% profit margin

Business: $499/month
├─ 100 meetings
├─ Unlimited participants
├─ Unlimited duration
├─ 90-day audio retention
└─ Break-even tier (volume play)

Enterprise: $2,000+/month
├─ 200+ meetings
├─ White-label
├─ Dedicated support
├─ Compliance features
└─ 43% profit margin
```

### Unit Economics

- **Cost per meeting:** $5.68
- **Break-even:** 10 Professional customers
- **Year 1 target:** 50 Professional customers = $119,400 ARR

---

## 🛠️ Technology Stack Summary

### Frontend
- **React 18** + TypeScript + Vite
- **TailwindCSS** for styling
- **React Query** for data fetching
- **IndexedDB** for offline storage
- **Service Workers** for PWA functionality
- **WebSocket** for real-time features

### Backend
- **Laravel 12** (your existing starter kit)
- **PostgreSQL 16** for database
- **Redis 7** for caching and queues
- **Laravel Horizon** for queue management
- **Laravel Reverb** for WebSocket server
- **Laravel Sanctum** for API authentication
- **Spatie Laravel Permission** for RBAC

### Mobile
- **NativePHP Mobile v3** for iOS & Android
- **Native audio APIs** for background recording
- **Local notifications** for task reminders
- **Biometric authentication** support

### AWS Services
- **S3** - Audio and document storage
- **Lambda** - Serverless compute (Python 3.11)
- **Transcribe** - Speech-to-text
- **Bedrock** - Claude AI (Haiku & Sonnet)
- **SQS** - Job queuing
- **EventBridge** - Event orchestration
- **SES** - Email delivery
- **CloudWatch** - Monitoring and logs

### DevOps
- **Terraform** - Infrastructure as Code
- **GitHub Actions** - CI/CD
- **Laravel Forge** - Backend deployment
- **AWS SAM** - Lambda deployment
- **CloudWatch** - Monitoring
- **Sentry** - Error tracking

---

## 📊 Key Metrics

### Technical Metrics
- Audio upload success rate: >99%
- Transcription accuracy: >95%
- Average processing time: <5 minutes per meeting
- WebSocket uptime: >99.9%
- API response time: <200ms (p95)

### Product Metrics
- Monthly Active Users (MAU)
- Meetings per user per month
- Average meeting duration: 60 minutes
- Action item completion rate: >70%
- Time saved vs. manual transcription: 4 hours per meeting

### Business Metrics
- Monthly Recurring Revenue (MRR)
- Customer Acquisition Cost (CAC): <$200
- Lifetime Value (LTV): >$2,000
- LTV:CAC ratio: >10:1
- Churn rate: <5% monthly
- Net Promoter Score (NPS): >40

---

## 🚀 Getting Started

### Prerequisites
- Laravel 12 starter kit with multi-tenancy
- AWS account with billing enabled
- Domain name and SSL certificate
- OpenAI or AWS Bedrock API access
- DigitalOcean or AWS hosting account

### Setup Instructions

1. **Clone your Laravel starter kit**
```bash
git clone your-starter-kit meeting-minutes-saas
cd meeting-minutes-saas
```

2. **Run database migrations**
```bash
php artisan migrate
```

3. **Set up AWS infrastructure**
```bash
cd terraform
terraform init
terraform apply
```

4. **Deploy Lambda functions**
```bash
cd lambda
./deploy.sh
```

5. **Build React PWA**
```bash
cd client
npm install
npm run build
```

6. **Configure NativePHP Mobile**
```bash
php artisan native:install
php artisan native:build ios
```

7. **Launch!**
```bash
php artisan serve
php artisan queue:work
php artisan reverb:start
```

---

## 📞 Support & Resources

### Documentation Links
- Laravel 12: https://laravel.com/docs/12.x
- AWS Transcribe: https://docs.aws.amazon.com/transcribe/
- AWS Bedrock: https://docs.aws.amazon.com/bedrock/
- NativePHP: https://nativephp.com/docs/
- React PWA: https://create-react-app.dev/docs/making-a-progressive-web-app/

### Community
- GitHub Discussions (for questions)
- Discord Server (for real-time chat)
- Twitter/X (@meetingminutessaas)

---

## 📝 License & Credits

**Document License:** MIT  
**Author:** Technical Specification Team  
**Contributors:** AI assistance from Claude (Anthropic)  
**Last Updated:** February 2025  

---

## 🔄 Document Versioning

### Version 2.0 (Current)
- Added NativePHP Mobile integration
- Updated to Laravel 12
- Refined cost analysis
- Added offline support strategies
- Enhanced security considerations

### Version 1.0
- Initial specification
- Laravel 11 based
- Web-only (no mobile apps)

---

## 📋 Implementation Checklist

### Pre-Development
- [ ] Read all 5 parts of specification
- [ ] Assemble development team
- [ ] Set up development environments
- [ ] Create AWS account and set budgets
- [ ] Register domain name
- [ ] Set up project management (Linear, Jira, etc.)

### Development Milestones
- [ ] Week 2: Backend API functional
- [ ] Week 4: PWA deployed to staging
- [ ] Week 5: AWS transcription working
- [ ] Week 6: AI features operational
- [ ] Week 7: All tests passing
- [ ] Week 8: MVP LAUNCHED! 🚀

### Post-Launch
- [ ] Monitor infrastructure costs daily
- [ ] Collect user feedback
- [ ] Fix critical bugs within 24 hours
- [ ] Plan Phase 2 features
- [ ] Reach break-even (10 customers)
- [ ] Hire customer success manager

---

**Ready to build? Start with [Part 1: Executive Summary & Architecture](./01-executive-summary-architecture.md)!**
