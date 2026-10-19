# Enterprise pricing dossier

Started 2026-10-19. A living record of what an organisation can use on MiConvener, how much of it, and what each one costs us, so an Enterprise price can be built from facts rather than guessed. Add to it whenever a feature ships or a limit changes.

**How to use it.** For an Enterprise prospect, walk the questionnaire (section 4), fill in their quantities against each dimension (section 2), and price from the included amount plus the per-unit rate once those rates are set (section 3). Today every Enterprise limit is unlimited (`-1` in `EventPackageSeeder`); the point of this dossier is to replace "unlimited" with an agreed quantity.

Sources: `database/seeders/EventPackageSeeder.php` (plan limits), `app/Services/Billing/TenantAddonService.php` (add-ons), `config/llm.php` (AI tokens), `config/product-page.php` (what we advertise). Values below are as of 2026-10-19, after the plan changes on branch `claude/plan-terms-and-leads`.

## 1. What the plans give today

| Dimension | Feature key | Free | Starter | Growth | Enterprise |
|---|---|---|---|---|---|
| Live events at a time | `events_in_flight` | 1 | 3 | 15 | unlimited |
| Registrations per month (free and paid, all events) | `event_registrations` | 50 | 500 | 1,500 | unlimited |
| Team seats (console users) | `team_seats` | 1 | 2 | 4 | unlimited |
| Staff links (door and floor crew, per event) | `staff_links` | 1 | 2 | 5 | unlimited |
| Community members | `community_members` | 50 | 500 | 5,000 | unlimited |
| Email credits per month | `email_credits` | 200 | 3,000 | 15,000 | unlimited |
| SMS credits per month | `sms_credits` | 0 | 150 | 500 | unlimited |
| Paid ticket sales | `paid_tickets` | no | yes | yes | yes |
| Ticket commission | package default | 5% | 3.5% | 2% | negotiated |
| Ticket commission cap | package default | none | none | none | per deal |
| Live polling and Q&A | `live_polling` | no | add-on | yes | yes |
| Speaker materials | `event_materials` | no | yes | yes | yes |
| CSV settlement export | `csv_settlement_export` | no | yes | yes | yes |
| White label, no platform branding | `white_label`, `remove_platform_branding` | no | no | yes | yes |
| Own domain | `custom-domains` | no | no | no | yes |
| Community library | `community_library_access` | no | yes | yes | yes |
| Scheduled community messages | `community_message_scheduling` | no | yes | yes | yes |
| Community polls and surveys | `community_polls_access` | no | no | yes | yes |
| Group community messages | `community_group_messaging` | no | no | yes | yes |
| SSO | `sso` | no | no | no | flag only, not built |
| API access | `api_access` | no | no | no | flag only, not built (API-first is out of scope) |

Add-ons on sale (GHS / USD): team seat 75 / 6 a month; usher pack 60 / 5 a month; live polling 85 / 7 a month or 50 / 4 per event; SMS 500, 1,500, 5,000 for 35, 95, 290 / 3, 8, 24; email 5,000 and 25,000 for 25, 90 / 2, 8; shop verification 150 / 13 a year; listing boost 50 / 4 a week; featured listing 200 / 17 a month. AI tokens: 60, 180, 600 / 5, 15, 50 per pack.

## 2. Pricing dimensions

Each dimension: what it measures, why it matters to price, and how to measure it for a prospect. "Cost to us" is what MiConvener pays when it is used; where a number is unknown it says so.

### People

| Dimension | Measures | Why it prices | Cost to us | Measure for a prospect |
|---|---|---|---|---|
| Team seats | People with a console login | Value scales with the team running events | Negligible | How many people plan, sell and report? |
| **Staff (bookers)** | People in the organisation who may book its private rooms (planned, see the staff booking design) | Pivotal for institutions: the number of staff is the size of the internal market the facility serves | Negligible per person; support grows with it | How many staff could book a room? Do they have their own email domain? |
| Staff links | Door and floor crew per event | Large events need many ushers | Negligible | Largest event: how many doors and ushers? |
| Community members | Members on the books | Communities are priced by size | Storage and messaging | How many members, and how many active? |
| Followers | People following the organisation | Free to follow; drives messaging volume | Messaging | Expected followers? |

### Events and attendance

| Dimension | Measures | Why it prices | Cost to us | Measure for a prospect |
|---|---|---|---|---|
| Live events at a time | Concurrent published events | Operational load | Low | Events a year, and the most at once? |
| Registrations a month | Confirmed registrations, free or paid | The true usage meter: it counts even when tickets are not sold through us, so it protects us from heavy free use | Email, storage, support | Peak month registrations? Share free vs paid? |
| Paid ticket volume | Ticket revenue through MiConvener | Commission is our margin on it | Paystack fees are passed on; none to us | Annual ticket revenue, average ticket price |
| Commission rate and cap | Our share of ticket sales | A lower rate or a cap is a concession worth pricing into the subscription | None | Will they sell tickets through us at all? |

### Messaging

| Dimension | Measures | Why it prices | Cost to us | Measure for a prospect |
|---|---|---|---|---|
| Email a month | Emails sent (Amazon SES) | Volume | Small per email | Messages per event and per member a month |
| SMS a month | Texts sent (mNotify via Omnichannel) | Real cost per text, charged at submission | Per text (rate from mNotify; record it here) | Will they text attendees and members? How often? |
| Own-domain sending | Email from their own domain | Enterprise only; setup by a superadmin | Setup time | Do they need mail from their domain? |

### Venues and facilities (planned with staff booking)

| Dimension | Measures | Why it prices | Cost to us | Measure for a prospect |
|---|---|---|---|---|
| **Private bookable rooms** | Rooms and spaces only staff can see and book | Pivotal for institutions; Enterprise only | Low | How many rooms, halls, labs and spaces? |
| Public listings | Rooms listed on the public marketplace | Marketplace fees (venue 10%) | Low | Which spaces are rented to the public? |
| Booking volume | Internal bookings a month | Support and calendar load | Low | Bookings a week at peak |
| Approvers | Facility managers who approve bookings | Seats | Negligible | Who approves, per building? |

### Brand and access

| Dimension | Measures | Why it prices | Cost to us | Measure for a prospect |
|---|---|---|---|---|
| White label | Their brand instead of ours | Premium | None | Must attendees never see MiConvener? |
| Own domain | events.theirdomain.org | Enterprise only | Certificate and support | Do they want their own address? |
| SSO | Sign-in with their identity provider | Not built: price only with a build plan | Build cost | Which provider? |

### Money and support

| Dimension | Measures | Why it prices | Cost to us | Measure for a prospect |
|---|---|---|---|---|
| Settlement mode | Our Paystack account or their own (Paystack split or Stripe Connect) | Own gateway removes payout handling | Payout transfer fees are passed on | Do they have their own Paystack or Stripe account? |
| Payout frequency | Payouts released | Each payout needs an OTP release | Staff time | How often do they need their money? |
| AI usage | Tokens a month (AI minutes and summaries) | Real cost per token | Per token | Will they use AI features? |
| Onboarding and training | Set-up help, data import | Staff time | Staff time | Do they need us to set it up? |
| Support level | Response time commitment | Staff time | Staff time | Do they need a response-time commitment? |

## 3. Pricing approach (to agree)

For each dimension above, settle three numbers and record them here: the amount included in an Enterprise base price, the price per extra unit (or band), and any floor. Proposed first set, for the owner to fill in:

| Dimension | Included in base | Extra | Notes |
|---|---|---|---|
| Staff (bookers) | | per 100 staff | |
| Private bookable rooms | | per room or per band (1-10, 11-50, 50+) | |
| Team seats | | per seat (Starter add-on is GHS 75) | |
| Registrations a month | | per 1,000 | |
| SMS a month | | at cost plus margin | |
| Commission | negotiated rate | | A lower rate or a cap raises the base price |

## 4. Prospect questionnaire

1. What do you run, how often, and how big is the largest one?
2. Will you sell tickets through MiConvener? Roughly how much a year, at what average price?
3. How many people will run it from the console?
4. How many staff could book rooms, and does your organisation have its own email domain?
5. How many rooms and spaces would staff book? Are any also rented to the public?
6. Members and followers: how many now, how many in a year?
7. Will you message people by SMS? How often, to how many?
8. Do you need your own domain, your brand only, or sign-in with your own accounts?
9. Do you have your own Paystack or Stripe account?
10. Do you need us to set it up, import data or train your team?

## 5. Open questions

- The SMS cost per text from mNotify (to fill in section 2).
- Whether registration packs should be on sale to Starter and Growth (proposed 2026-10-19), which would give a per-1,000 reference price for Enterprise.
- SSO and API access are listed as Enterprise features but are not built; either remove them from the plan or plan them.
