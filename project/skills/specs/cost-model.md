# Cost Model Specification

## Per-Meeting Cost Breakdown

Based on a 60-minute meeting with 10 participants using push-to-talk mode.

### AWS Costs

| Service | Usage | Unit Cost | Meeting Cost | % of Total |
|---|---|---|---|---|
| **Transcribe** | ~20 min actual speech | $0.024/min | $4.32 | 76% |
| **S3 Storage** | ~120 MB audio | $0.023/GB | $0.003 | <1% |
| **S3 Requests** | ~60 PUT + GET | $0.005/1K | $0.001 | <1% |
| **Lambda** | ~30 invocations | $0.20/1M + compute | $0.41 | 7% |
| **Bedrock (Haiku)** | Cleanup + extraction | ~$0.003/call | $0.006 | <1% |
| **Bedrock (Sonnet)** | Minutes generation | ~$0.015/call | $0.015 | <1% |
| **SES** | ~12 emails | $0.10/1K | $0.001 | <1% |
| **EventBridge** | ~10 events | $1.00/1M | $0.00001 | <1% |
| **SQS** | ~50 messages | $0.40/1M | $0.00002 | <1% |
| **Hosting (amortized)** | EC2/RDS share | $70/mo / 100 | $0.70 | 12% |

**Total per meeting: ~$5.68** (push-to-talk mode)

### Cost by Recording Mode

| Mode | Transcribe Minutes | Cost/Meeting | Savings vs Open Mic |
|---|---|---|---|
| Push-to-Talk | ~20 min (only when button held) | $5.68 | -67% |
| Floor Control | ~25 min (one speaker at a time) | $6.50 | -61% |
| Auto VAD | ~35 min (voice activity detected) | $8.00 | -52% |
| Open Mic | ~55 min (always on, all participants) | $15.00 | baseline |

> Push-to-talk is the clear winner for cost optimization. This is the MVP default.

---

## Monthly Infrastructure Costs (100 meetings/month)

| Category | Service | Monthly Cost |
|---|---|---|
| Compute | EC2 (t3.medium) or Forge | $35.00 |
| Database | RDS PostgreSQL (db.t3.micro) | $15.25 |
| Cache | ElastiCache Redis (t3.micro) | $12.50 |
| Transcription | AWS Transcribe (2,000 min) | $432.00 |
| Storage | S3 (50 GB) | $1.15 |
| Functions | Lambda (3,000 invocations) | $4.25 |
| AI | Bedrock (200 calls) | $2.10 |
| Email | SES (1,200 emails) | $0.12 |
| Monitoring | CloudWatch | $7.50 |
| Domain/SSL | Route53 + ACM | $1.00 |
| Misc | Data transfer, other | $5.00 |
| **Total** | | **$515.87** |

---

## Pricing vs. Cost Analysis

| Plan | Price | Meetings | Revenue/Meeting | Cost/Meeting | Margin |
|---|---|---|---|---|---|
| Starter | $49/mo | 5 | $9.80 | $5.68 | **42%** |
| Professional | $199/mo | 25 | $7.96 | $5.68 | **29%** |
| Business | $499/mo | 100 | $4.99 | $5.68 | **-14%** |
| Enterprise | $2000/mo | 200 | $10.00 | $5.68 | **43%** |

> Business plan is a volume/retention play - margin comes from most customers not hitting 100 meetings. Enterprise is high-margin with premium features.

---

## Break-Even Analysis

| Metric | Value |
|---|---|
| Fixed costs (hosting, team) | ~$500/mo |
| Variable cost per meeting | ~$5.68 |
| **Break-even: 10 Professional customers** | $1,990/mo revenue vs ~$1,920/mo costs |
| Year 1 target: 50 Professional | **$119,400 ARR** |

---

## Cost Optimization Strategies

| Strategy | Savings | Complexity | Phase |
|---|---|---|---|
| Push-to-talk as default | 67% on Transcribe | Low | MVP |
| Batch transcription (non-real-time) | 10-15% on Transcribe | Low | MVP |
| S3 Intelligent-Tiering | 30-40% on storage | Low | MVP |
| Reserved Instances (compute) | 30-40% on EC2/RDS | Medium | Post-launch |
| Transcribe custom vocabulary | Fewer retranscriptions | Medium | Phase 2 |
| Floor control mode | 80% vs open mic | Medium | Phase 2 |
| Savings Plans (1-year) | 20-30% across AWS | Low | Post-launch |
