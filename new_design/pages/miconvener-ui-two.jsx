import React, { useState, useEffect } from "react";
import {
  Calendar,
  Settings,
  MapPin,
  Send,
  MessageSquare,
  FileText,
  BarChart3,
  Building2,
  Users,
  Terminal,
  Sun,
  Moon,
  Plus,
  Check,
  X,
  ChevronRight,
  Download,
  Upload,
  Search,
  Clock,
  AlertTriangle,
  Sparkles,
  QrCode,
  Ticket,
  Mic,
  Globe,
  Lock,
  Trash2,
  ShieldCheck,
  Copy,
  Eye,
  CreditCard,
  Smartphone,
  Bell,
  Pin,
} from "lucide-react";

/* ================================================================== */
/*  Demo content                                                       */
/* ================================================================== */

const EVENTS = [
  { id: 1, name: "Accra Health Informatics Summit", dates: "22–24 Sep 2026", status: "live", reg: 587, cap: 640, rev: 412_400, days: 3 },
  { id: 2, name: "District Records Training", dates: "14 Oct 2026", status: "published", reg: 96, cap: 120, rev: 24_000, days: 1 },
  { id: 3, name: "Nursing Informatics Day", dates: "3 Nov 2026", status: "draft", reg: 0, cap: 200, rev: 0, days: 1 },
  { id: 4, name: "Clinical AI Roundtable", dates: "8 Aug 2026", status: "ended", reg: 74, cap: 80, rev: 0, days: 1 },
  { id: 5, name: "Q2 Partner Briefing", dates: "19 May 2026", status: "ended", reg: 212, cap: 250, rev: 63_600, days: 1 },
];

const SEATS = { rows: "ABCDEFGH".split(""), perRow: 14 };
const SEAT_STATE = (r, i) => {
  const n = r.charCodeAt(0) * 3 + i * 7;
  if (r === "A" && i < 4) return "accessible";
  if (n % 11 === 0) return "held";
  if (n % 3 === 0 || n % 5 === 1) return "taken";
  return "free";
};

const BLASTS = [
  { id: 1, subject: "Your entry code and what to bring", audience: "Everyone confirmed", when: "Sent 19 Sep", sent: 541, open: 82, click: 47, state: "sent" },
  { id: 2, subject: "Day 2 workshop rooms have changed", audience: "Day 2 workshop", when: "Sent 21 Sep", sent: 60, open: 91, click: 63, state: "sent" },
  { id: 3, subject: "Your table is set — meal preferences", audience: "Vegetarian meals", when: "Scheduled 22 Sep, 06:00", sent: 74, state: "scheduled" },
  { id: 4, subject: "Tell us how it went", audience: "Everyone who attended", when: "Draft", state: "draft" },
];

const AUTOMATIONS = [
  ["When someone registers", "Confirmation with entry code", true],
  ["When payment clears", "Receipt and calendar invite", true],
  ["Three days before", "What to bring and how to get here", true],
  ["When a session starts", "Push to everyone in that room", false],
  ["When someone does not arrive", "We missed you, materials inside", true],
  ["Two hours after close", "Feedback form", true],
];

const THREADS = [
  { id: 1, title: "Will the referral demo be available to try afterwards?", author: "Grace Amponsah", replies: 4, when: "24m", tags: ["important"], session: "Day 1 plenary", answered: false },
  { id: 2, title: "Is there a vegetarian option at the gala dinner?", author: "Anonymous", replies: 1, when: "41m", tags: ["faq"], session: "General", answered: true },
  { id: 3, title: "Which FHIR version are the labs teaching?", author: "Kofi Danso", replies: 6, when: "1h", tags: ["official_answer"], session: "Workshop hall", answered: true },
  { id: 4, title: "Can district hospitals join the registry pilot?", author: "Ibrahim Sulemana", replies: 9, when: "2h", tags: ["action_item", "important"], session: "Day 1 plenary", answered: true },
  { id: 5, title: "Parking is full — is there overflow?", author: "Comfort Adjei", replies: 2, when: "3h", tags: [], session: "General", answered: true },
];

const REPLIES = [
  { who: "Grace Amponsah", role: "Attendee", when: "24m", text: "The cross-facility referral demo was impressive but it went past very quickly. Will there be a sandbox we can log into after the summit?" },
  { who: "Kwame Asare", role: "Speaker", when: "18m", text: "Yes. We are opening a sandbox with synthetic patient data on 25 September. It will stay up for six weeks.", tag: "official_answer" },
  { who: "Dr Ama Boateng", role: "Organiser", when: "12m", text: "Adding this to the closing plenary so everyone hears it, and we will email the sandbox link to all attendees.", tag: "action_item" },
  { who: "Kofi Danso", role: "Attendee", when: "6m", text: "Will the sandbox accept our own test bundles or only the provided dataset?" },
];

const MATERIALS = [
  { name: "Opening plenary slides.pdf", size: "4.2 MB", session: "Day 1 plenary", access: "Everyone", limit: 3, used: 412, release: "Released", watermark: true },
  { name: "FHIR modelling workbook.pdf", size: "1.8 MB", session: "Hands-on lab", access: "Workshop pass", limit: 3, used: 58, release: "Released", watermark: true },
  { name: "Summit programme.pdf", size: "620 KB", session: "—", access: "Everyone", limit: 5, used: 503, release: "Released", watermark: false },
  { name: "Consent guidance draft.docx", size: "310 KB", session: "Clinic track", access: "Everyone", limit: 2, used: 0, release: "Opens 24 Sep, 17:00", watermark: true },
  { name: "Referral demo recording.mp4", size: "740 MB", session: "Day 2 keynote", access: "Attended Day 2", limit: 2, used: 0, release: "Opens after the summit", watermark: false },
];

const EXPORT_JOBS = [
  { name: "Registrations, every field", rows: 587, state: "ready", when: "9 minutes ago", expires: "in 6 days" },
  { name: "Settlement statement", rows: 1, state: "ready", when: "Yesterday", expires: "in 5 days" },
  { name: "Session attendance", rows: 2_140, state: "building", pct: 64 },
  { name: "Catering and access needs", rows: 83, state: "ready", when: "2 days ago", expires: "in 4 days" },
];

const EXPORT_CATALOGUE = [
  "Registrations", "Attendee directory", "Guest list for print", "Payments", "Refunds",
  "Settlement statement", "Payouts", "Accounting ledger", "Invoices as a ZIP", "Programme",
  "Speakers and faculty", "Session attendance", "Check-in log", "Certificates as a ZIP",
  "Quiz results", "Poll responses", "Forum and summaries", "Download attempts",
  "Service requests", "Catering and access", "Badge print files", "Feedback", "Audit log",
];

const SPONSORS = [
  { name: "MTN Ghana", tier: "Headline", booth: "E-01", done: 5, total: 6 },
  { name: "Ecobank", tier: "Headline", booth: "E-02", done: 6, total: 6 },
  { name: "Oracle Health", tier: "Supporting", booth: "E-07", done: 3, total: 5 },
  { name: "Nyaho Medical", tier: "Supporting", booth: "E-09", done: 4, total: 5 },
  { name: "Ghana Medical Association", tier: "Partner", booth: "—", done: 2, total: 3 },
];

const TEAM = [
  { name: "Selase Kove-Seyram", role: "Owner", email: "selase@purpledot.io", twofa: true, last: "Now" },
  { name: "Dr Ama Boateng", role: "Event manager", email: "a.boateng@ghs.gov.gh", twofa: true, last: "12m ago" },
  { name: "Mensah Agyeman", role: "Finance", email: "m.agyeman@purpledot.io", twofa: true, last: "Yesterday" },
  { name: "Akosua Darko", role: "Check-in staff", email: "akosua@purpledot.io", twofa: false, last: "9m ago" },
  { name: "Yaw Boadu", role: "Service staff", email: "yaw@purpledot.io", twofa: false, last: "3m ago" },
];

const WEBHOOKS = [
  { url: "https://forge.purpledot.io/hooks/miconvener", events: 14, health: 100, last: "2m ago" },
  { url: "https://ugmc-cpd.gov.gh/api/events", events: 4, health: 96, last: "18m ago" },
  { url: "https://hooks.zapier.com/…/8f3k22", events: 2, health: 71, last: "1h ago" },
];

const DELIVERIES = [
  { evt: "registration.confirmed", code: 200, when: "09:54:12", ms: 142 },
  { evt: "checkin.completed", code: 200, when: "09:54:02", ms: 118 },
  { evt: "payment.succeeded", code: 200, when: "09:53:41", ms: 204 },
  { evt: "registration.created", code: 500, when: "09:52:18", ms: 30_000, retry: 2 },
  { evt: "checkin.completed", code: 200, when: "09:51:55", ms: 131 },
];

const MY_AGENDA = [
  { time: "08:30", title: "Opening plenary: a national health record by 2030", room: "Grand Ballroom", added: true },
  { time: "09:40", title: "What the Ministry actually funds", room: "Grand Ballroom", added: true },
  { time: "11:00", title: "Lunch and exhibition", room: "Terrace", added: true },
  { time: "11:15", title: "Clinic: writing your first integration", room: "Volta Room", added: false, full: true },
  { time: "12:00", title: "Consent, records and the Data Protection Act", room: "Ashanti Room", added: true },
];

const NAV = [
  { key: "events", label: "All events", icon: Calendar },
  { key: "setup", label: "Event setup", icon: Settings },
  { key: "venue", label: "Venue and seating", icon: MapPin },
  { key: "messages", label: "Messages", icon: Send },
  { key: "forum", label: "Forum", icon: MessageSquare },
  { key: "materials", label: "Materials", icon: FileText },
  { key: "reports", label: "Reports", icon: BarChart3 },
  { key: "sponsors", label: "Sponsors", icon: Building2 },
  { key: "team", label: "Team and access", icon: Users },
  { key: "developers", label: "Developers", icon: Terminal },
];

/* ================================================================== */
/*  Primitives                                                         */
/* ================================================================== */

const money = (n) => `GHS ${n.toLocaleString("en-GH")}`;

const initials = (name) =>
  name.replace(/^(Dr|Prof\.?|Hon\.)\s+/i, "").split(" ").filter(Boolean).slice(0, 2).map((w) => w[0]).join("");

function Stat({ label, value, sub, accent }) {
  return (
    <div className="stat">
      <div className="stat-label">{label}</div>
      <div className={accent ? "stat-value accent" : "stat-value"}>{value}</div>
      {sub && <div className="stat-sub">{sub}</div>}
    </div>
  );
}

function Tag({ children, tone = "neutral" }) {
  return <span className={`tag tone-${tone}`}>{children}</span>;
}

function Button({ children, kind = "ghost", icon: Icon, ...rest }) {
  return (
    <button className={`btn btn-${kind}`} {...rest}>
      {Icon && <Icon size={13} strokeWidth={1.75} />}
      {children}
    </button>
  );
}

function SectionHead({ title, note, children }) {
  return (
    <div className="sec-head">
      <div>
        <h2>{title}</h2>
        {note && <p className="sec-note">{note}</p>}
      </div>
      <div className="sec-actions">{children}</div>
    </div>
  );
}

function Field({ label, hint, children }) {
  return (
    <div className="field">
      <label>{label}</label>
      {children}
      {hint && <p className="hint">{hint}</p>}
    </div>
  );
}

function Toggle({ on, onChange, label, hint }) {
  return (
    <button className="switchrow" onClick={() => onChange(!on)} type="button">
      <span className={on ? "switch on" : "switch"}>
        <span className="knob" />
      </span>
      <span className="switchtext">
        <b>{label}</b>
        {hint && <span>{hint}</span>}
      </span>
    </button>
  );
}

function Avatar({ name, size = 30 }) {
  return (
    <span className="avatar" style={{ width: size, height: size, fontSize: size * 0.34 }}>
      {initials(name)}
    </span>
  );
}

function Segmented({ value, onChange, options }) {
  return (
    <div className="segmented">
      {options.map(([k, l]) => (
        <button key={k} type="button" className={value === k ? "seg on" : "seg"} onClick={() => onChange(k)}>
          {l}
        </button>
      ))}
    </div>
  );
}

function ThemeToggle({ theme, onTheme }) {
  return (
    <button
      className="themetoggle"
      onClick={() => onTheme(theme === "dark" ? "light" : "dark")}
      aria-label={theme === "dark" ? "Switch to light" : "Switch to dark"}
    >
      {theme === "dark" ? <Sun size={14} strokeWidth={1.6} /> : <Moon size={14} strokeWidth={1.6} />}
    </button>
  );
}

function FakeQR({ size = 120 }) {
  return (
    <div className="qrbig" style={{ width: size, height: size }} aria-hidden="true">
      {Array.from({ length: 144 }).map((_, i) => (
        <i key={i} className={(i * 11) % 4 === 0 || i % 7 === 0 || (i * 3) % 5 === 1 ? "on" : ""} />
      ))}
    </div>
  );
}

/* ================================================================== */
/*  All events                                                         */
/* ================================================================== */

function EventsHome() {
  const [creating, setCreating] = useState(false);
  const [type, setType] = useState("in_person");
  const [mode, setMode] = useState("payment");
  const [vis, setVis] = useState("public");

  const state = {
    live: ["Running now", "accent"],
    published: ["On sale", "good"],
    draft: ["Draft", "neutral"],
    ended: ["Finished", "neutral"],
  };

  return (
    <>
      <SectionHead title="All events" note="Everything your organisation has run or is about to.">
        <Button icon={Download}>Export across events</Button>
        <Button kind="solid" icon={Plus} onClick={() => setCreating(true)}>
          Create event
        </Button>
      </SectionHead>

      {creating && (
        <div className="panel form">
          <div className="panel-head">
            <b>Create an event</b>
            <button className="iconbtn" onClick={() => setCreating(false)} aria-label="Close">
              <X size={14} strokeWidth={1.75} />
            </button>
          </div>
          <div className="formgrid">
            <div className="formcol">
              <Field label="What is it called" hint="You can change this until you publish.">
                <input placeholder="Nursing Informatics Day" />
              </Field>
              <Field label="Web address" hint="miconvener.com/ghs/nursing-informatics-2026">
                <div className="inputgroup">
                  <span className="prefix mono">/ghs/</span>
                  <input className="mono" placeholder="nursing-informatics-2026" />
                </div>
              </Field>
              <Field label="How people attend">
                <Segmented
                  value={type}
                  onChange={setType}
                  options={[["in_person", "In person"], ["virtual", "Online"], ["hybrid", "Both"]]}
                />
              </Field>
              <div className="tworow">
                <Field label="Starts">
                  <input type="date" defaultValue="2026-11-03" />
                </Field>
                <Field label="Ends">
                  <input type="date" defaultValue="2026-11-03" />
                </Field>
              </div>
              <Field label="Timezone" hint="The programme is stored in this zone and shown to each attendee in theirs.">
                <select defaultValue="GMT">
                  <option>GMT — Accra</option>
                  <option>WAT — Lagos</option>
                  <option>EAT — Nairobi</option>
                  <option>BST — London</option>
                </select>
              </Field>
            </div>
            <div className="formcol">
              <Field label="How people get in">
                <div className="radiocards">
                  {[
                    ["auto", "Anyone can register", "Confirmed the moment they submit."],
                    ["approval", "I approve each one", "They wait until you say yes."],
                    ["payment", "They pay to confirm", "Confirmed when the money clears."],
                    ["both", "Approve, then pay", "You approve, then send a pay link."],
                    ["invite", "Invitation only", "Needs a code or a personal link."],
                  ].map(([k, t, d]) => (
                    <button key={k} className={mode === k ? "radiocard on" : "radiocard"} onClick={() => setMode(k)}>
                      <span className="radiodot" />
                      <span>
                        <b>{t}</b>
                        <em>{d}</em>
                      </span>
                    </button>
                  ))}
                </div>
              </Field>
              <div className="tworow">
                <Field label="Capacity">
                  <input className="mono" placeholder="200" />
                </Field>
                <Field label="Who can find it">
                  <Segmented
                    value={vis}
                    onChange={setVis}
                    options={[["public", "Anyone"], ["private", "Link only"], ["members", "Members"]]}
                  />
                </Field>
              </div>
              <Field label="Copy settings from" hint="Brings tickets, badge design, email wording and team access.">
                <select defaultValue="">
                  <option value="">Start from scratch</option>
                  <option>Accra Health Informatics Summit</option>
                  <option>District Records Training</option>
                </select>
              </Field>
            </div>
          </div>
          <div className="formfoot">
            <span className="hint">Created as a draft. Nothing is public until you publish it.</span>
            <div className="sec-actions">
              <Button onClick={() => setCreating(false)}>Cancel</Button>
              <Button kind="solid">Create draft</Button>
            </div>
          </div>
        </div>
      )}

      <div className="stats">
        <Stat label="Events this year" value="12" />
        <Stat label="Running now" value="1" accent />
        <Stat label="People registered" value="4,318" sub="Across all events" />
        <Stat label="Collected this year" value={money(1_284_600)} />
      </div>

      <div className="panel flush">
        <table className="table">
          <thead>
            <tr>
              <th>Event</th>
              <th>When</th>
              <th>Registered</th>
              <th>Collected</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {EVENTS.map((e) => (
              <tr key={e.id}>
                <td>
                  <b>{e.name}</b>
                  <span className="dim evdays"> {e.days === 1 ? "One day" : `${e.days} days`}</span>
                </td>
                <td className="dim">{e.dates}</td>
                <td>
                  <div className="cellfill">
                    <span className="mono">{e.reg}</span>
                    <div className="fillbar">
                      <div style={{ width: `${(e.reg / e.cap) * 100}%` }} />
                    </div>
                  </div>
                </td>
                <td className="mono">{e.rev ? money(e.rev) : "—"}</td>
                <td>
                  <Tag tone={state[e.status][1]}>{state[e.status][0]}</Tag>
                </td>
                <td className="rowact">
                  <Button icon={ChevronRight}>Open</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Event setup                                                        */
/* ================================================================== */

function EventSetup() {
  const [tab, setTab] = useState("basics");
  const [features, setFeatures] = useState({
    forum: true, live: true, materials: true, badges: true, seating: true, certificates: false, ai: true, waitlist: true,
  });
  const set = (k) => (v) => setFeatures((f) => ({ ...f, [k]: v }));

  return (
    <>
      <SectionHead title="Event setup" note="Accra Health Informatics Summit — published and running.">
        <Button icon={Eye}>Preview public page</Button>
        <Button kind="solid">Save changes</Button>
      </SectionHead>

      <div className="subtabs">
        {[
          ["basics", "Basics"],
          ["registration", "Registration"],
          ["features", "What is switched on"],
          ["visibility", "Visibility"],
        ].map(([k, l]) => (
          <button key={k} className={tab === k ? "subtab on" : "subtab"} onClick={() => setTab(k)}>
            {l}
          </button>
        ))}
      </div>

      {tab === "basics" && (
        <div className="panel form">
          <div className="formgrid">
            <div className="formcol">
              <Field label="Name">
                <input defaultValue="Accra Health Informatics Summit" />
              </Field>
              <Field label="Short description" hint="Used in search results and when the link is shared.">
                <textarea rows={3} defaultValue="Three days for the people who build, buy and run health systems in Ghana." />
              </Field>
              <Field label="Cover image" hint="1600 × 640 or larger. Shown at the top of the public page.">
                <div className="filerow">
                  <div className="filethumb" />
                  <div>
                    <b>summit-cover-2026.jpg</b>
                    <span className="hint">1920 × 768, 340 KB</span>
                  </div>
                  <Button icon={Upload}>Replace</Button>
                </div>
              </Field>
              <Field label="Languages" hint="Attendees see their own language where a translation exists.">
                <div className="picker">
                  {["English", "Twi", "Ewe", "Ga", "French"].map((l, i) => (
                    <button key={l} className={i === 0 ? "chip on" : "chip"}>{l}</button>
                  ))}
                </div>
              </Field>
            </div>
            <div className="formcol">
              <Field label="Venue">
                <input defaultValue="Kempinski Gold Coast City" />
              </Field>
              <Field label="Address">
                <textarea rows={2} defaultValue="Gamel Abdul Nasser Avenue, Ridge, Accra" />
              </Field>
              <div className="tworow">
                <Field label="First day">
                  <input type="date" defaultValue="2026-09-22" />
                </Field>
                <Field label="Last day">
                  <input type="date" defaultValue="2026-09-24" />
                </Field>
              </div>
              <Field label="Capacity" hint="587 registered. Lowering this below that number is not allowed.">
                <input className="mono" defaultValue="640" />
              </Field>
              <Field label="Contact for attendees">
                <input defaultValue="summit@ghs.gov.gh" />
              </Field>
            </div>
          </div>
          <div className="formfoot">
            <span className="hint">Changes to dates or venue notify everyone who has registered.</span>
            <div className="sec-actions">
              <Button>Discard</Button>
              <Button kind="solid">Save changes</Button>
            </div>
          </div>
        </div>
      )}

      {tab === "registration" && (
        <div className="panel form">
          <div className="formgrid">
            <div className="formcol">
              <Field label="Registration form" hint="Built in Forge Data Studio. MiConvener stores the answers.">
                <div className="filerow">
                  <FileText size={16} strokeWidth={1.5} />
                  <div>
                    <b>Summit registration 2026</b>
                    <span className="hint">11 questions, last edited 4 Aug</span>
                  </div>
                  <Button>Edit form</Button>
                </div>
              </Field>
              <Field label="Questions everyone answers">
                <ul className="qlist">
                  {[
                    ["Full name", "Required"],
                    ["Organisation", "Required"],
                    ["Professional role", "Required, choose one"],
                    ["Meal preference", "Creates a group you can message"],
                    ["Access needs", "Optional, seen only by the team"],
                    ["Consent to be photographed", "Terms, signature captured"],
                  ].map(([q, m]) => (
                    <li key={q}>
                      <b>{q}</b>
                      <span>{m}</span>
                    </li>
                  ))}
                </ul>
              </Field>
            </div>
            <div className="formcol">
              <Toggle on={features.waitlist} onChange={set("waitlist")} label="Keep a waitlist when full" hint="Places offered automatically when someone cancels, in order." />
              <Field label="How long a place is held during checkout" hint="Long enough for mobile money to clear, short enough not to block others.">
                <select defaultValue="15">
                  <option value="10">10 minutes</option>
                  <option value="15">15 minutes</option>
                  <option value="30">30 minutes</option>
                </select>
              </Field>
              <Field label="Cancellations and transfers">
                <div className="fields">
                  <Toggle on={true} onChange={() => {}} label="Attendees can transfer their ticket" hint="Up to 48 hours before doors open." />
                  <Toggle on={true} onChange={() => {}} label="Attendees can cancel themselves" hint="Refunded under your refund rules." />
                </div>
              </Field>
              <Field label="Refund rule">
                <select defaultValue="tiered">
                  <option value="none">No refunds</option>
                  <option value="full">Full refund any time</option>
                  <option value="tiered">Full until 8 Sep, half after</option>
                </select>
              </Field>
            </div>
          </div>
        </div>
      )}

      {tab === "features" && (
        <div className="panel">
          <div className="featgrid">
            <Toggle on={features.forum} onChange={set("forum")} label="Forum and questions" hint="Attendees ask, your team answers, threads sit under each session." />
            <Toggle on={features.live} onChange={set("live")} label="Live polls and quizzes" hint="Run from the stage, answers appear on the screen." />
            <Toggle on={features.materials} onChange={set("materials")} label="Materials to download" hint="Slides and handouts, with a limit on attempts." />
            <Toggle on={features.badges} onChange={set("badges")} label="Printed badges" hint="Design once, print in batches or at the desk." />
            <Toggle on={features.seating} onChange={set("seating")} label="Assigned seats" hint="Seat numbers on badges, service requests by seat." />
            <Toggle on={features.certificates} onChange={set("certificates")} label="Attendance certificates" hint="Issued from session scans. Needed for CPD credit." />
            <Toggle on={features.ai} onChange={set("ai")} label="AI summaries of the forum" hint="Uses your own model settings. Nothing leaves your provider." />
            <Toggle on={false} onChange={() => {}} label="Abstract submissions" hint="Authors submit, reviewers score, accepted work becomes sessions." />
          </div>
          {features.ai && (
            <div className="ai-strip">
              <Sparkles size={14} strokeWidth={1.6} />
              <span>Summaries run on Claude Sonnet through your own key. Turn on a local model in organisation settings if forum content must not leave your servers.</span>
              <Button>Model settings</Button>
            </div>
          )}
        </div>
      )}

      {tab === "visibility" && (
        <div className="panel form">
          <div className="formgrid">
            <div className="formcol">
              <Field label="Who can find this event">
                <div className="radiocards">
                  {[
                    ["public", "Anyone", "Listed publicly and found by search engines."],
                    ["private", "Only people with the link", "Nothing is listed, nothing is indexed."],
                    ["members", "Members of your organisation", "They sign in first."],
                  ].map(([k, t, d], i) => (
                    <button key={k} className={i === 0 ? "radiocard on" : "radiocard"}>
                      <span className="radiodot" />
                      <span>
                        <b>{t}</b>
                        <em>{d}</em>
                      </span>
                    </button>
                  ))}
                </div>
              </Field>
              <Field label="Custom domain" hint="Point a CNAME at cname.miconvener.com to use your own address.">
                <input className="mono" defaultValue="summit.ghs.gov.gh" />
              </Field>
            </div>
            <div className="formcol">
              <Field label="How it looks when shared" hint="Title, description and image used by WhatsApp, X and LinkedIn.">
                <div className="sharecard">
                  <div className="sharethumb" />
                  <div className="sharemeta">
                    <b>Accra Health Informatics Summit</b>
                    <span>Three days for the people who build, buy and run health systems in Ghana.</span>
                    <em className="mono">summit.ghs.gov.gh</em>
                  </div>
                </div>
              </Field>
              <div className="danger">
                <div>
                  <b>Cancel this event</b>
                  <span>Everyone is told, all tickets are refunded in full, and the page stays up saying it was cancelled.</span>
                </div>
                <Button>Cancel event</Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

/* ================================================================== */
/*  Venue and seating                                                  */
/* ================================================================== */

function Venue() {
  const [room, setRoom] = useState("ballroom");
  const [picked, setPicked] = useState(null);

  return (
    <>
      <SectionHead title="Venue and seating" note="Assign seats when you confirm a registration, or let people choose.">
        <Button icon={Download}>Export seating chart</Button>
        <Button kind="solid">Auto-assign remaining</Button>
      </SectionHead>

      <div className="prog-bar">
        <div className="daybar">
          {[
            ["ballroom", "Grand Ballroom", "420 seats"],
            ["volta", "Volta Room", "60 at benches"],
            ["ashanti", "Ashanti Room", "90 seats"],
            ["terrace", "Terrace", "Standing"],
          ].map(([k, n, s]) => (
            <button key={k} className={room === k ? "daytab on" : "daytab"} onClick={() => setRoom(k)}>
              <span>{n}</span>
              <b>{s}</b>
            </button>
          ))}
        </div>
      </div>

      <div className="seatwrap">
        <div className="panel seatpanel">
          <div className="stage">Stage</div>
          <div className="seatmap">
            {SEATS.rows.map((r) => (
              <div key={r} className="seatrow">
                <span className="rowlabel mono">{r}</span>
                {Array.from({ length: SEATS.perRow }).map((_, i) => {
                  const st = SEAT_STATE(r, i);
                  const id = `${r}-${String(i + 1).padStart(3, "0")}`;
                  return (
                    <button
                      key={i}
                      className={`seat ${st}${picked === id ? " picked" : ""}`}
                      onClick={() => setPicked(id)}
                      title={id}
                      aria-label={`Seat ${id}, ${st}`}
                    />
                  );
                })}
              </div>
            ))}
          </div>
          <div className="legend">
            {[
              ["free", "Free"],
              ["taken", "Assigned"],
              ["held", "Held for a group"],
              ["accessible", "Step-free"],
            ].map(([k, l]) => (
              <span key={k} className="legenditem">
                <i className={`seat ${k}`} />
                {l}
              </span>
            ))}
          </div>
        </div>

        <div className="panel side">
          {picked ? (
            <>
              <div className="panel-head">
                <b className="mono">Seat {picked}</b>
              </div>
              <div className="seatdetail">
                <div className="cellwho">
                  <Avatar name="Yaa Serwaa Mensah" size={34} />
                  <div>
                    <b>Yaa Serwaa Mensah</b>
                    <span className="dim">Ridge Hospital</span>
                  </div>
                </div>
                <ul className="kv">
                  <li>
                    <span>Entry code</span>
                    <b className="mono">AHIS26-8F3K</b>
                  </li>
                  <li>
                    <span>Ticket</span>
                    <b>Standard</b>
                  </li>
                  <li>
                    <span>Meals</span>
                    <b>Vegetarian</b>
                  </li>
                  <li>
                    <span>Arrived</span>
                    <b className="mono">09:12</b>
                  </li>
                </ul>
                <div className="sec-actions">
                  <Button>Move seat</Button>
                  <Button>Unassign</Button>
                </div>
              </div>
            </>
          ) : (
            <>
              <div className="panel-head">
                <b>Assignment rules</b>
              </div>
              <div className="fields">
                <Toggle on={true} onChange={() => {}} label="Keep groups together" hint="People on one order sit side by side." />
                <Toggle on={true} onChange={() => {}} label="Step-free seats first" hint="Anyone who declared an access need gets row A." />
                <Toggle on={false} onChange={() => {}} label="Let attendees choose" hint="They pick from the live map after confirmation." />
                <Field label="Speakers and guests">
                  <select defaultValue="front">
                    <option value="front">Front two rows, centre</option>
                    <option value="side">Side aisle, easy stage access</option>
                    <option value="none">No reservation</option>
                  </select>
                </Field>
              </div>
              <p className="hint">Click a seat to see who is in it.</p>
            </>
          )}
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>Requests from the floor</b>
          <span className="panel-note">Attendees raise these from their phone</span>
        </div>
        <ul className="queue">
          <li>
            <span className="mono qtime">6m</span>
            <div>
              <b>Water, table 14</b>
              <span>Grand Ballroom, seat C-114, Comfort Adjei</span>
            </div>
            <Button>Assign to me</Button>
          </li>
          <li>
            <span className="mono qtime">4m</span>
            <div>
              <b>Projector not showing laptop</b>
              <span>Volta Room, raised by a speaker</span>
            </div>
            <Button>Assign to me</Button>
          </li>
          <li>
            <span className="mono qtime">2m</span>
            <div>
              <b>Step-free route to the stage</b>
              <span>Grand Ballroom, described as "front left, near the pillar"</span>
            </div>
            <Button>Assign to me</Button>
          </li>
        </ul>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Messages                                                           */
/* ================================================================== */

function Messages() {
  const [composing, setComposing] = useState(false);
  const [channels, setChannels] = useState({ email: true, sms: false, whatsapp: false });

  return (
    <>
      <SectionHead title="Messages" note="Write once, send to a group you have already filtered.">
        <Button>Templates</Button>
        <Button kind="solid" icon={Plus} onClick={() => setComposing(true)}>
          Write a message
        </Button>
      </SectionHead>

      {composing && (
        <div className="panel form">
          <div className="panel-head">
            <b>New message</b>
            <button className="iconbtn" onClick={() => setComposing(false)} aria-label="Close">
              <X size={14} strokeWidth={1.75} />
            </button>
          </div>
          <div className="formgrid">
            <div className="formcol">
              <Field label="Who gets it">
                <select defaultValue="veg">
                  <option value="all">Everyone confirmed — 541 people</option>
                  <option value="veg">Vegetarian meals — 74 people</option>
                  <option value="unpaid">Unpaid — 46 people</option>
                  <option value="fac">Faculty — 22 people</option>
                  <option value="d2">Day 2 workshop — 60 people</option>
                </select>
              </Field>
              <div className="audiencenote">
                <Users size={13} strokeWidth={1.6} />
                74 people. 71 have agreed to email, 3 have not — they will be skipped.
              </div>
              <Field label="How to send it">
                <div className="picker">
                  {[
                    ["email", "Email"],
                    ["sms", "SMS"],
                    ["whatsapp", "WhatsApp"],
                  ].map(([k, l]) => (
                    <button
                      key={k}
                      className={channels[k] ? "chip on" : "chip"}
                      onClick={() => setChannels((c) => ({ ...c, [k]: !c[k] }))}
                    >
                      {l}
                    </button>
                  ))}
                </div>
              </Field>
              <Field label="Subject">
                <input defaultValue="Your table is set — meal preferences confirmed" />
              </Field>
              <Field label="Message" hint="Type { to insert their name, entry code, seat or session.">
                <textarea rows={7} defaultValue={"Hello {first_name},\n\nWe have your meal preference down as vegetarian. Your table is {seat} in the Grand Ballroom and lunch is served from 11:00 on the terrace.\n\nShow this code at the door: {entry_code}"} />
              </Field>
            </div>
            <div className="formcol">
              <Field label="When">
                <Segmented value="later" onChange={() => {}} options={[["now", "Send now"], ["later", "Schedule"]]} />
              </Field>
              <div className="tworow">
                <Field label="Date">
                  <input type="date" defaultValue="2026-09-22" />
                </Field>
                <Field label="Time">
                  <input type="time" defaultValue="06:00" />
                </Field>
              </div>
              <Field label="Preview">
                <div className="mailpreview">
                  <p className="mail-sub">Your table is set — meal preferences confirmed</p>
                  <p className="prose">
                    Hello Comfort, we have your meal preference down as vegetarian. Your table is
                    C-118 in the Grand Ballroom and lunch is served from 11:00 on the terrace.
                  </p>
                  <p className="mono codeline">AHIS26-5ZC6</p>
                </div>
              </Field>
              <p className="hint">
                Replies come back into the event inbox and stay attached to that person's
                registration.
              </p>
            </div>
          </div>
          <div className="formfoot">
            <span className="hint">Nobody without consent is contacted, whatever the group contains.</span>
            <div className="sec-actions">
              <Button onClick={() => setComposing(false)}>Cancel</Button>
              <Button>Send a test to myself</Button>
              <Button kind="solid">Schedule</Button>
            </div>
          </div>
        </div>
      )}

      <div className="panel flush">
        <table className="table">
          <thead>
            <tr>
              <th>Subject</th>
              <th>Who</th>
              <th>When</th>
              <th>Opened</th>
              <th>Clicked</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {BLASTS.map((b) => (
              <tr key={b.id}>
                <td>
                  <b>{b.subject}</b>
                </td>
                <td className="dim">{b.audience}</td>
                <td className="dim">{b.when}</td>
                <td>
                  {b.state === "sent" ? (
                    <div className="cellfill">
                      <span className="mono">{b.open}%</span>
                      <div className="fillbar">
                        <div style={{ width: `${b.open}%` }} />
                      </div>
                    </div>
                  ) : (
                    <span className="dim">—</span>
                  )}
                </td>
                <td className="mono">{b.state === "sent" ? `${b.click}%` : "—"}</td>
                <td className="rowact">
                  {b.state === "draft" ? <Button>Finish</Button> : b.state === "scheduled" ? <Button>Edit</Button> : <Button>Open</Button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>Messages that send themselves</b>
          <span className="panel-note">Triggered by what people do, not by you</span>
        </div>
        <ul className="autolist">
          {AUTOMATIONS.map(([trigger, what, on]) => (
            <li key={trigger}>
              <span className={on ? "switch on" : "switch"}>
                <span className="knob" />
              </span>
              <div>
                <b>{trigger}</b>
                <span>{what}</span>
              </div>
              <Button>Edit</Button>
            </li>
          ))}
        </ul>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Forum                                                              */
/* ================================================================== */

function Forum() {
  const [open, setOpen] = useState(THREADS[0]);
  const [range, setRange] = useState("today");
  const [summarising, setSummarising] = useState(false);
  const [summary, setSummary] = useState(true);

  const tagLabel = {
    official_answer: ["Answered by a speaker", "good"],
    important: ["Important", "accent"],
    action_item: ["We will do this", "warn"],
    faq: ["Common question", "neutral"],
  };

  const run = () => {
    setSummarising(true);
    setSummary(false);
    setTimeout(() => {
      setSummarising(false);
      setSummary(true);
    }, 1400);
  };

  return (
    <>
      <SectionHead title="Forum" note="Questions from the floor, answers from your team, all in one place.">
        <Button>Moderation settings</Button>
        <Button icon={Download}>Export threads</Button>
      </SectionHead>

      <div className="forumwrap">
        <div className="panel flush threadlist">
          <div className="panel-head">
            <b>Threads</b>
            <span className="panel-note mono">41 today</span>
          </div>
          <ul className="threads">
            {THREADS.map((t) => (
              <li
                key={t.id}
                className={open.id === t.id ? "thread on" : "thread"}
                onClick={() => setOpen(t)}
              >
                <div className="threadtop">
                  <b>{t.title}</b>
                  {!t.answered && <span className="unans" title="No answer yet" />}
                </div>
                <div className="threadmeta">
                  <span>{t.author}</span>
                  <span className="dim">{t.session}</span>
                  <span className="mono dim">{t.replies} replies</span>
                  <span className="mono dim">{t.when}</span>
                </div>
                {t.tags.length > 0 && (
                  <div className="threadtags">
                    {t.tags.map((g) => (
                      <Tag key={g} tone={tagLabel[g][1]}>
                        {tagLabel[g][0]}
                      </Tag>
                    ))}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </div>

        <div className="threadview">
          <div className="panel">
            <div className="panel-head">
              <b>{open.title}</b>
              <div className="sec-actions">
                <button className="iconbtn plain" title="Pin to the top">
                  <Pin size={13} strokeWidth={1.6} />
                </button>
                <button className="iconbtn plain" title="Hide from attendees">
                  <Eye size={13} strokeWidth={1.6} />
                </button>
              </div>
            </div>
            <ul className="replies">
              {REPLIES.map((r, i) => (
                <li key={i} className={r.tag ? "reply tagged" : "reply"}>
                  <Avatar name={r.who} size={28} />
                  <div>
                    <div className="replyhead">
                      <b>{r.who}</b>
                      <span className="dim">{r.role}</span>
                      <span className="mono dim">{r.when}</span>
                      {r.tag && <Tag tone={tagLabel[r.tag][1]}>{tagLabel[r.tag][0]}</Tag>}
                    </div>
                    <p>{r.text}</p>
                    {!r.tag && (
                      <div className="replyacts">
                        <button>Mark as the answer</button>
                        <button>Flag as important</button>
                        <button>Turn into an action</button>
                      </div>
                    )}
                  </div>
                </li>
              ))}
            </ul>
            <div className="replybox">
              <textarea rows={2} placeholder="Answer as the organising team" />
              <Button kind="solid">Post reply</Button>
            </div>
          </div>
        </div>

        <div className="panel side aiside">
          <div className="panel-head">
            <b>Catch up</b>
            <Sparkles size={14} strokeWidth={1.6} className="accent-text" />
          </div>
          <Segmented
            value={range}
            onChange={(v) => {
              setRange(v);
              run();
            }}
            options={[["today", "Today"], ["yesterday", "Yesterday"], ["range", "Pick dates"]]}
          />

          {summarising && (
            <div className="summarising">
              <span className="pulse" />
              Reading 41 posts from today
            </div>
          )}

          {summary && !summarising && (
            <div className="summary">
              <p className="prose">
                Most of the morning was about the referral demo. Kwame confirmed a sandbox opens on
                25 September and Ama committed to announcing it at the closing plenary. Two threads
                about district participation in the registry pilot are still unresolved.
              </p>
              <h4>What came up</h4>
              <ul className="bullets">
                <li>Sandbox access after the summit, asked four separate times</li>
                <li>Which FHIR version the labs teach, now answered</li>
                <li>Whether district hospitals can join the pilot, no answer yet</li>
                <li>Parking capacity, resolved by the venue team</li>
              </ul>
              <h4>Things you agreed to do</h4>
              <ul className="bullets">
                <li>Announce the sandbox at the closing plenary — Dr Ama Boateng</li>
                <li>Email the sandbox link to all attendees — organising team</li>
              </ul>
              <h4>Still waiting on you</h4>
              <ul className="bullets warn">
                <li>Can district hospitals join the registry pilot? Asked 2 hours ago</li>
              </ul>
              <div className="ailabel">
                <Sparkles size={11} strokeWidth={1.6} />
                Written by AI from 41 posts. Check anything you plan to say from the stage.
              </div>
              <div className="sec-actions">
                <Button icon={Copy}>Copy</Button>
                <Button icon={Download}>Save as PDF</Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Materials                                                          */
/* ================================================================== */

function Materials() {
  return (
    <>
      <SectionHead title="Materials" note="Slides and handouts, released when you choose, with a limit on downloads.">
        <Button icon={Download}>Export download log</Button>
        <Button kind="solid" icon={Upload}>
          Upload material
        </Button>
      </SectionHead>

      <div className="stats">
        <Stat label="Files" value={MATERIALS.length} />
        <Stat label="Downloads so far" value="973" accent />
        <Stat label="Waiting to release" value="2" sub="One opens after the summit" />
        <Stat label="Hit their limit" value="14" sub="People who used every attempt" />
      </div>

      <div className="panel flush">
        <table className="table">
          <thead>
            <tr>
              <th>File</th>
              <th>Session</th>
              <th>Who can get it</th>
              <th>Attempts each</th>
              <th>Downloads</th>
              <th>Release</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {MATERIALS.map((m) => (
              <tr key={m.name}>
                <td>
                  <div className="cellwho">
                    <FileText size={15} strokeWidth={1.5} className="dim" />
                    <div>
                      <b>{m.name}</b>
                      <span className="dim filesize">{m.size}</span>
                    </div>
                  </div>
                </td>
                <td className="dim">{m.session}</td>
                <td className="dim">{m.access}</td>
                <td className="mono">{m.limit}</td>
                <td className="mono">{m.used || "—"}</td>
                <td>
                  {m.release === "Released" ? (
                    <Tag tone="good">Released</Tag>
                  ) : (
                    <Tag tone="warn">{m.release}</Tag>
                  )}
                </td>
                <td className="rowact">
                  <Button>Edit</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="split">
        <div className="panel">
          <div className="panel-head">
            <b>People who ran out of attempts</b>
            <span className="panel-note">Give them more without changing everyone's limit</span>
          </div>
          <ul className="queue">
            {[
              ["Ibrahim Sulemana", "Opening plenary slides.pdf", "3 of 3 used, last try 11 minutes ago"],
              ["Grace Amponsah", "FHIR modelling workbook.pdf", "3 of 3 used, downloads failed twice"],
              ["Kofi Danso", "Opening plenary slides.pdf", "3 of 3 used"],
            ].map(([who, file, note]) => (
              <li key={who + file}>
                <Avatar name={who} size={26} />
                <div>
                  <b>{who}</b>
                  <span>
                    {file} — {note}
                  </span>
                </div>
                <Button>Give 2 more</Button>
              </li>
            ))}
          </ul>
          <div className="sec-actions" style={{ marginTop: 12 }}>
            <Button>Give everyone 2 more attempts</Button>
          </div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <b>Upload</b>
          </div>
          <div className="dropzone">
            <Upload size={20} strokeWidth={1.25} />
            <b>Drop a file here</b>
            <span>PDF, PowerPoint, Word, video. Up to 2 GB.</span>
          </div>
          <div className="fields" style={{ marginTop: 16 }}>
            <Field label="Attach to">
              <select defaultValue="">
                <option value="">The whole event</option>
                <option>Day 1 plenary</option>
                <option>Hands-on lab</option>
              </select>
            </Field>
            <Field label="Who can download it">
              <select defaultValue="all">
                <option value="all">Everyone confirmed</option>
                <option>Workshop pass holders</option>
                <option>People who attended the session</option>
              </select>
            </Field>
            <div className="tworow">
              <Field label="Attempts each">
                <input className="mono" defaultValue="3" />
              </Field>
              <Field label="Release">
                <select defaultValue="now">
                  <option value="now">Straight away</option>
                  <option>When the session starts</option>
                  <option>After the event ends</option>
                </select>
              </Field>
            </div>
            <Toggle on={true} onChange={() => {}} label="Stamp each copy with the reader's name" hint="Discourages sharing. PDFs only." />
          </div>
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Reports                                                            */
/* ================================================================== */

function Reports() {
  const [entity, setEntity] = useState("registrations");
  const [cols, setCols] = useState(["Name", "Entry code", "Ticket", "Meal preference", "Checked in"]);
  const available = ["Name", "Entry code", "Organisation", "Ticket", "Seat", "Paid", "Meal preference", "Access needs", "Checked in", "Sessions attended", "Registered on"];

  return (
    <>
      <SectionHead title="Reports" note="Anything in here can leave as a spreadsheet.">
        <Button>Scheduled reports</Button>
      </SectionHead>

      <div className="panel flush">
        <div className="panel-head">
          <b>Recent</b>
          <span className="panel-note">Links expire a week after they finish</span>
        </div>
        <ul className="joblist">
          {EXPORT_JOBS.map((j) => (
            <li key={j.name}>
              <div>
                <b>{j.name}</b>
                <span className="dim">
                  <span className="mono">{j.rows.toLocaleString()}</span> rows
                  {j.state === "ready" ? ` · built ${j.when} · expires ${j.expires}` : ""}
                </span>
              </div>
              {j.state === "building" ? (
                <div className="jobprog">
                  <div className="fillbar">
                    <div style={{ width: `${j.pct}%` }} />
                  </div>
                  <span className="mono dim">{j.pct}%</span>
                </div>
              ) : (
                <Button icon={Download}>Download</Button>
              )}
            </li>
          ))}
        </ul>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>Build a report</b>
          <span className="panel-note">Save it and it appears in the list above every time you run it</span>
        </div>
        <div className="builder">
          <div className="builderrow">
            <Field label="About">
              <select value={entity} onChange={(e) => setEntity(e.target.value)}>
                <option value="registrations">Registrations</option>
                <option value="payments">Payments</option>
                <option value="attendance">Session attendance</option>
                <option value="forum">Forum activity</option>
              </select>
            </Field>
            <Field label="Only include">
              <select defaultValue="all">
                <option value="all">Everyone</option>
                <option>Confirmed only</option>
                <option>Vegetarian meals</option>
                <option>Did not arrive</option>
              </select>
            </Field>
            <Field label="Group by">
              <select defaultValue="none">
                <option value="none">Nothing</option>
                <option>Ticket type</option>
                <option>Organisation</option>
                <option>Day</option>
              </select>
            </Field>
            <Field label="Format">
              <select defaultValue="xlsx">
                <option value="xlsx">Excel</option>
                <option value="csv">CSV</option>
                <option value="pdf">PDF</option>
              </select>
            </Field>
          </div>
          <Field label="Columns" hint="Drag to reorder. Custom form questions appear here too.">
            <div className="picker">
              {available.map((c) => (
                <button
                  key={c}
                  className={cols.includes(c) ? "chip on" : "chip"}
                  onClick={() =>
                    setCols((p) => (p.includes(c) ? p.filter((x) => x !== c) : [...p, c]))
                  }
                >
                  {c}
                </button>
              ))}
            </div>
          </Field>
          <div className="builderfoot">
            <span className="hint">
              <span className="mono">{cols.length}</span> columns, about{" "}
              <span className="mono">587</span> rows. Payment columns are hidden from anyone without
              finance access.
            </span>
            <div className="sec-actions">
              <Button>Save this report</Button>
              <Button kind="solid" icon={Download}>
                Build it now
              </Button>
            </div>
          </div>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>Everything you can export</b>
        </div>
        <div className="catalogue">
          {EXPORT_CATALOGUE.map((x) => (
            <button key={x} className="export">
              <Download size={12} strokeWidth={1.75} />
              {x}
            </button>
          ))}
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Sponsors                                                           */
/* ================================================================== */

function Sponsors() {
  const tiers = ["Headline", "Supporting", "Partner"];
  return (
    <>
      <SectionHead title="Sponsors" note="Who is paying, what you promised them, and what is still outstanding.">
        <Button icon={Download}>Export deliverables</Button>
        <Button kind="solid" icon={Plus}>
          Add sponsor
        </Button>
      </SectionHead>

      <div className="stats">
        <Stat label="Sponsors" value={SPONSORS.length} />
        <Stat label="Sponsorship income" value={money(285_000)} accent />
        <Stat label="Promises kept" value="20 of 25" />
        <Stat label="Booths allocated" value="4 of 12" sub="Terrace exhibition" />
      </div>

      {tiers.map((tier) => (
        <div key={tier} className="panel">
          <div className="panel-head">
            <b>{tier}</b>
            <span className="panel-note">{SPONSORS.filter((s) => s.tier === tier).length} sponsors</span>
          </div>
          <div className="sponsorgrid">
            {SPONSORS.filter((s) => s.tier === tier).map((s) => (
              <div key={s.name} className="sponsorcard">
                <div className="logoslot">{s.name.slice(0, 2).toUpperCase()}</div>
                <div className="sponsormain">
                  <b>{s.name}</b>
                  <span className="dim">Booth {s.booth}</span>
                  <div className="cellfill">
                    <span className="mono dim">
                      {s.done}/{s.total} delivered
                    </span>
                    <div className="fillbar">
                      <div style={{ width: `${(s.done / s.total) * 100}%` }} />
                    </div>
                  </div>
                </div>
                <Button>Open</Button>
              </div>
            ))}
          </div>
        </div>
      ))}

      <div className="panel">
        <div className="panel-head">
          <b>Outstanding promises</b>
          <span className="panel-note">Five things still owed before the summit closes</span>
        </div>
        <ul className="queue">
          {[
            ["Oracle Health", "Logo on the closing plenary slide", "Needs the file from them"],
            ["Oracle Health", "Two speaking minutes on Day 2", "Not yet scheduled"],
            ["Nyaho Medical", "Insert in the delegate bag", "Arriving tomorrow morning"],
            ["Ghana Medical Association", "Mention in the opening address", "Script not updated"],
            ["MTN Ghana", "Wi-Fi splash page branding", "Waiting on the venue"],
          ].map(([who, what, note]) => (
            <li key={who + what}>
              <span className="dot warnbg">
                <Clock size={11} strokeWidth={2} />
              </span>
              <div>
                <b>{what}</b>
                <span>
                  {who} — {note}
                </span>
              </div>
              <Button>Mark done</Button>
            </li>
          ))}
        </ul>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Team and access                                                    */
/* ================================================================== */

function Team() {
  return (
    <>
      <SectionHead title="Team and access" note="Who can see what, and who signed off on it.">
        <Button kind="solid" icon={Plus}>
          Invite someone
        </Button>
      </SectionHead>

      <div className="panel flush">
        <table className="table">
          <thead>
            <tr>
              <th>Person</th>
              <th>Email</th>
              <th>Can do</th>
              <th>Two-step</th>
              <th>Last seen</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {TEAM.map((m) => (
              <tr key={m.email}>
                <td>
                  <div className="cellwho">
                    <Avatar name={m.name} size={26} />
                    <b>{m.name}</b>
                  </div>
                </td>
                <td className="dim">{m.email}</td>
                <td>
                  <Tag tone={m.role === "Owner" ? "accent" : "neutral"}>{m.role}</Tag>
                </td>
                <td>
                  {m.twofa ? (
                    <span className="ok">
                      <Check size={12} strokeWidth={2} /> On
                    </span>
                  ) : (
                    <span className="pending">Off</span>
                  )}
                </td>
                <td className="dim">{m.last}</td>
                <td className="rowact">
                  <Button>Change</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="scan-fail">
          <AlertTriangle size={13} strokeWidth={1.75} />
          Two-step sign-in is required for anyone who can touch money. Finance and owners already
          have it on.
        </div>
      </div>

      <div className="split">
        <div className="panel">
          <div className="panel-head">
            <b>What each role can do</b>
          </div>
          <ul className="rolelist">
            {[
              ["Owner", "Everything, including payouts and deleting the organisation"],
              ["Event manager", "Build and run events. Cannot see bank details or move money"],
              ["Finance", "Payments, refunds, payouts and statements. Cannot edit the programme"],
              ["Check-in staff", "Scan people in. Sees names and codes, nothing financial"],
              ["Service staff", "The floor request queue only"],
              ["Speaker", "Their own sessions, slides and questions"],
            ].map(([r, d]) => (
              <li key={r}>
                <b>{r}</b>
                <span>{d}</span>
              </li>
            ))}
          </ul>
        </div>

        <div className="panel">
          <div className="panel-head">
            <b>Business verification</b>
          </div>
          <ul className="kv big">
            <li>
              <span>Registered name</span>
              <b>Purpledot Limited</b>
            </li>
            <li>
              <span>Registration number</span>
              <b className="mono">CS••••••4192</b>
            </li>
            <li>
              <span>Tax number</span>
              <b className="mono">C00••••••21</b>
            </li>
            <li>
              <span>Status</span>
              <Tag tone="good">Verified 14 March 2026</Tag>
            </li>
            <li>
              <span>Next check</span>
              <b>14 March 2027</b>
            </li>
          </ul>
          <div className="kyc">
            <ShieldCheck size={13} strokeWidth={1.75} />
            Payouts stay open while this is current. We will ask you a month before it lapses.
          </div>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>What your team has been doing</b>
          <span className="panel-note">Kept for seven years, cannot be edited</span>
        </div>
        <ul className="auditlist">
          {[
            ["09:41", "Mensah Agyeman", "Scheduled a payout of GHS 191,160 to Absa •••• 4417"],
            ["09:22", "Dr Ama Boateng", "Moved the Day 1 case study from 12:15 to 12:15 in Grand Ballroom"],
            ["08:57", "Selase Kove-Seyram", "Gave everyone 2 more download attempts on the plenary slides"],
            ["08:31", "Akosua Darko", "Turned away AHIS26-4TL8 at the main entrance, payment outstanding"],
            ["Yesterday", "Mensah Agyeman", "Refunded GHS 850 to Nana Adjei, reason: duplicate order"],
          ].map(([t, who, what]) => (
            <li key={t + who}>
              <span className="mono audittime">{t}</span>
              <b>{who}</b>
              <span>{what}</span>
            </li>
          ))}
        </ul>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Developers                                                         */
/* ================================================================== */

function Developers() {
  const [sandbox, setSandbox] = useState(false);
  return (
    <>
      <SectionHead title="Developers" note="Everything the console does, your own systems can do too.">
        <Button>Read the docs</Button>
        <Button icon={Copy}>Copy OpenAPI file</Button>
      </SectionHead>

      <div className="panel">
        <div className="panel-head">
          <b>Environment</b>
        </div>
        <Toggle
          on={sandbox}
          onChange={setSandbox}
          label="Work against test data"
          hint="Test cards and test mobile money numbers. Nothing here touches real money or emails real people."
        />
      </div>

      <div className="panel flush">
        <div className="panel-head">
          <b>Keys</b>
          <span className="panel-note">Secret keys are shown once, when you make them</span>
        </div>
        <ul className="keylist">
          <li>
            <div>
              <b>Forge Data Studio</b>
              <span className="mono dim">mc_live_pk_8f3k22••••••••</span>
            </div>
            <span className="dim scopes">registrations, events</span>
            <Tag tone="good">In use</Tag>
            <Button>Roll</Button>
          </li>
          <li>
            <div>
              <b>UGMC CPD sync</b>
              <span className="mono dim">mc_live_sk_2m7q41••••••••</span>
            </div>
            <span className="dim scopes">attendance, certificates</span>
            <Tag tone="good">In use</Tag>
            <Button>Roll</Button>
          </li>
          <li>
            <div>
              <b>Old Zapier key</b>
              <span className="mono dim">mc_live_sk_9xr4••••••••</span>
            </div>
            <span className="dim scopes">events read only</span>
            <Tag tone="warn">Unused for 90 days</Tag>
            <Button>Revoke</Button>
          </li>
          <li className="addrow">
            <Plus size={15} strokeWidth={1.75} />
            <div>
              <b>Make a new key</b>
              <span>Pick only the permissions the system actually needs.</span>
            </div>
          </li>
        </ul>
      </div>

      <div className="split">
        <div className="panel flush">
          <div className="panel-head">
            <b>Where we send updates</b>
            <span className="panel-note">Retried for three days before we give up</span>
          </div>
          <ul className="hooklist">
            {WEBHOOKS.map((w) => (
              <li key={w.url}>
                <div>
                  <b className="mono hookurl">{w.url}</b>
                  <span className="dim">
                    {w.events} kinds of update · last {w.last}
                  </span>
                </div>
                <div className="cellfill narrow">
                  <span className={w.health < 90 ? "mono warntext" : "mono dim"}>{w.health}%</span>
                  <div className="fillbar">
                    <div className={w.health < 90 ? "warnfill" : ""} style={{ width: `${w.health}%` }} />
                  </div>
                </div>
              </li>
            ))}
            <li className="addrow">
              <Plus size={15} strokeWidth={1.75} />
              <div>
                <b>Add an address</b>
                <span>We sign every request so you can check it came from us.</span>
              </div>
            </li>
          </ul>
        </div>

        <div className="panel flush">
          <div className="panel-head">
            <b>Recent deliveries</b>
            <Button>Send a test</Button>
          </div>
          <ul className="dellist">
            {DELIVERIES.map((d, i) => (
              <li key={i}>
                <span className={d.code === 200 ? "code ok" : "code bad"}>{d.code}</span>
                <b className="mono">{d.evt}</b>
                <span className="mono dim">{d.when}</span>
                <span className="mono dim">{d.ms >= 1000 ? `${d.ms / 1000}s` : `${d.ms}ms`}</span>
                {d.retry ? <Button>Send again</Button> : <span />}
              </li>
            ))}
          </ul>
          <div className="scan-fail">
            <AlertTriangle size={13} strokeWidth={1.75} />
            One registration update timed out twice. It is queued for another try in 14 minutes.
          </div>
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Attendee portal                                                    */
/* ================================================================== */

function AttendeePortal({ onBack, theme, onTheme }) {
  const [tab, setTab] = useState("ticket");
  const [agenda, setAgenda] = useState(MY_AGENDA);
  const [asked, setAsked] = useState(false);
  const [helpSent, setHelpSent] = useState(false);

  const toggleSession = (title) =>
    setAgenda((a) => a.map((s) => (s.title === title ? { ...s, added: !s.added } : s)));

  return (
    <div className={`portal theme-${theme}`}>
      <header className="portal-bar">
        <div>
          <span className="wordmark">Accra Health Informatics Summit</span>
          <span className="portal-sub">Day 1 of 3</span>
        </div>
        <div className="pub-bar-right">
          <ThemeToggle theme={theme} onTheme={onTheme} />
          <button className="linkish" onClick={onBack}>
            Back to console
          </button>
        </div>
      </header>

      <nav className="pub-tabs portal-tabs">
        {[
          ["ticket", "My ticket"],
          ["agenda", "My day"],
          ["materials", "Downloads"],
          ["ask", "Ask"],
          ["messages", "Messages"],
        ].map(([k, l]) => (
          <button key={k} className={tab === k ? "pubtab on" : "pubtab"} onClick={() => setTab(k)}>
            {l}
          </button>
        ))}
      </nav>

      <main className="portal-body">
        {tab === "ticket" && (
          <div className="ticketcard">
            <FakeQR size={168} />
            <div className="tc-meta">
              <h2>Yaa Serwaa Mensah</h2>
              <p className="dim">Ridge Hospital</p>
              <ul className="kv big">
                <li>
                  <span>Entry code</span>
                  <b className="mono">AHIS26-8F3K</b>
                </li>
                <li>
                  <span>Ticket</span>
                  <b>Standard</b>
                </li>
                <li>
                  <span>Seat</span>
                  <b className="mono">C-114</b>
                </li>
                <li>
                  <span>Meals</span>
                  <b>Vegetarian</b>
                </li>
              </ul>
              <div className="sec-actions">
                <Button icon={Download}>Add to wallet</Button>
                <Button>Transfer to someone else</Button>
              </div>
              <p className="hint">
                Show this at the door. It works without a signal — take a screenshot if you are
                worried about reception at the venue.
              </p>
            </div>
          </div>
        )}

        {tab === "agenda" && (
          <>
            <p className="lede">Tuesday 22 September. Times are shown in Accra time.</p>
            <ul className="myagenda">
              {agenda.map((s) => (
                <li key={s.title} className={s.added ? "mine" : ""}>
                  <span className="mono time">{s.time}</span>
                  <div>
                    <b>{s.title}</b>
                    <span className="dim">{s.room}</span>
                  </div>
                  {s.full && !s.added ? (
                    <Tag tone="warn">Full</Tag>
                  ) : (
                    <button className="addbtn" onClick={() => toggleSession(s.title)}>
                      {s.added ? <Check size={13} strokeWidth={2} /> : <Plus size={13} strokeWidth={2} />}
                      {s.added ? "In my day" : "Add"}
                    </button>
                  )}
                </li>
              ))}
            </ul>
          </>
        )}

        {tab === "materials" && (
          <>
            <p className="lede">Files from the sessions you can access.</p>
            <ul className="dllist">
              {[
                ["Opening plenary slides.pdf", "4.2 MB", 1, 3, true],
                ["Summit programme.pdf", "620 KB", 0, 5, true],
                ["FHIR modelling workbook.pdf", "1.8 MB", 3, 3, true],
                ["Consent guidance draft.docx", "310 KB", 0, 2, false],
              ].map(([n, s, used, limit, open]) => (
                <li key={n}>
                  <FileText size={16} strokeWidth={1.5} className="dim" />
                  <div>
                    <b>{n}</b>
                    <span className="dim">
                      {s} · {open ? `${limit - used} of ${limit} downloads left` : "Opens 24 September, 17:00"}
                    </span>
                  </div>
                  {!open ? (
                    <Tag tone="warn">Not yet</Tag>
                  ) : used >= limit ? (
                    <Button>Ask for more</Button>
                  ) : (
                    <Button icon={Download}>Download</Button>
                  )}
                </li>
              ))}
            </ul>
            <p className="hint">
              Previewing in the browser does not use an attempt. If a download fails, ask the desk
              and they will add attempts.
            </p>
          </>
        )}

        {tab === "ask" && (
          <div className="asktwo">
            <div className="panel">
              <div className="panel-head">
                <b>Ask a question</b>
                <span className="panel-note">Answered by the team or the speaker</span>
              </div>
              {asked ? (
                <div className="sentbox">
                  <Check size={16} strokeWidth={2} />
                  <div>
                    <b>Asked</b>
                    <span>You will get a notification when someone answers.</span>
                  </div>
                </div>
              ) : (
                <>
                  <Field label="About which session">
                    <select defaultValue="">
                      <option value="">The summit generally</option>
                      <option>Opening plenary</option>
                      <option>What the Ministry actually funds</option>
                    </select>
                  </Field>
                  <Field label="Your question">
                    <textarea rows={4} placeholder="Keep it short so it can be read from the stage." />
                  </Field>
                  <Toggle on={false} onChange={() => {}} label="Ask without my name showing" hint="Organisers can still see it is you." />
                  <div className="sec-actions" style={{ marginTop: 12 }}>
                    <Button kind="solid" onClick={() => setAsked(true)}>
                      Ask
                    </Button>
                  </div>
                </>
              )}
            </div>

            <div className="panel">
              <div className="panel-head">
                <b>Need something at your seat</b>
                <span className="panel-note">Goes straight to the floor team</span>
              </div>
              {helpSent ? (
                <div className="sentbox">
                  <Check size={16} strokeWidth={2} />
                  <div>
                    <b>Someone is coming</b>
                    <span>Raised at 09:56 for seat C-114. Usually under five minutes.</span>
                  </div>
                </div>
              ) : (
                <>
                  <Field label="What do you need">
                    <div className="picker">
                      {["Water", "Help finding my seat", "Technical", "Step-free access", "First aid", "Something else"].map(
                        (x, i) => (
                          <button key={x} className={i === 0 ? "chip on" : "chip"}>
                            {x}
                          </button>
                        )
                      )}
                    </div>
                  </Field>
                  <Field label="Where you are" hint="Your seat is filled in already. Change it if you have moved.">
                    <input className="mono" defaultValue="C-114, Grand Ballroom" />
                  </Field>
                  <Field label="Anything else">
                    <textarea rows={2} placeholder="Optional" />
                  </Field>
                  <div className="sec-actions" style={{ marginTop: 12 }}>
                    <Button kind="solid" onClick={() => setHelpSent(true)}>
                      Ask for help
                    </Button>
                  </div>
                </>
              )}
            </div>
          </div>
        )}

        {tab === "messages" && (
          <>
            <p className="lede">Everything the organisers have sent you.</p>
            <ul className="msgthread">
              {[
                ["21 Sep", "Day 2 workshop rooms have changed", "The interoperability lab moves to the Volta Room. Same time."],
                ["19 Sep", "Your entry code and what to bring", "Bring a laptop for the labs. Parking is free, show your code at the boom."],
                ["14 Aug", "You are registered", "Your place at the summit is confirmed. Entry code AHIS26-8F3K."],
              ].map(([d, s, b]) => (
                <li key={s}>
                  <span className="mono msgdate">{d}</span>
                  <div>
                    <b>{s}</b>
                    <p>{b}</p>
                  </div>
                </li>
              ))}
            </ul>
            <div className="panel" style={{ marginTop: 22 }}>
              <div className="panel-head">
                <b>Reply to the organisers</b>
              </div>
              <textarea rows={3} placeholder="They see this against your registration." />
              <div className="sec-actions" style={{ marginTop: 10 }}>
                <Button kind="solid">Send</Button>
              </div>
            </div>
          </>
        )}
      </main>
    </div>
  );
}

/* ================================================================== */
/*  Speaker portal                                                     */
/* ================================================================== */

function SpeakerPortal({ onBack, theme, onTheme }) {
  const [confirmed, setConfirmed] = useState(false);

  return (
    <div className={`portal theme-${theme}`}>
      <header className="portal-bar">
        <div>
          <span className="wordmark">Speaker area</span>
          <span className="portal-sub">Accra Health Informatics Summit</span>
        </div>
        <div className="pub-bar-right">
          <ThemeToggle theme={theme} onTheme={onTheme} />
          <button className="linkish" onClick={onBack}>
            Back to console
          </button>
        </div>
      </header>

      <main className="portal-body">
        {!confirmed && (
          <div className="confirmbar">
            <div>
              <b>Are you still able to speak?</b>
              <span>
                We have you down for three sessions across 22 and 23 September. Let us know by 5
                September so we can print the programme.
              </span>
            </div>
            <div className="sec-actions">
              <Button>I cannot make it</Button>
              <Button kind="solid" onClick={() => setConfirmed(true)}>
                Yes, I'll be there
              </Button>
            </div>
          </div>
        )}
        {confirmed && (
          <div className="sentbox wide">
            <Check size={16} strokeWidth={2} />
            <div>
              <b>Thank you, you are confirmed</b>
              <span>You appear on the public programme now.</span>
            </div>
          </div>
        )}

        <div className="split">
          <div className="panel flush">
            <div className="panel-head">
              <b>Your sessions</b>
            </div>
            <ul className="myses">
              {[
                ["Tue 22 Sep, 08:45", "Hands-on: FHIR resource modelling", "Volta Room", "60 signed up, full"],
                ["Tue 22 Sep, 11:15", "Clinic: writing your first integration", "Volta Room", "44 signed up"],
                ["Wed 23 Sep, 12:00", "Live demo: cross-facility referral", "Grand Ballroom", "265 expected"],
              ].map(([when, title, room, note]) => (
                <li key={title}>
                  <span className="mono time">{when}</span>
                  <div>
                    <b>{title}</b>
                    <span className="dim">
                      {room} — {note}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
            <div className="scan-fail">
              <AlertTriangle size={13} strokeWidth={1.75} />
              Two of your sessions overlap at 11:15. The organisers have been told.
            </div>
          </div>

          <div className="panel">
            <div className="panel-head">
              <b>Your slides</b>
              <span className="panel-note">Due 18 September</span>
            </div>
            <ul className="dllist tight">
              <li>
                <FileText size={16} strokeWidth={1.5} className="dim" />
                <div>
                  <b>FHIR modelling lab.pdf</b>
                  <span className="dim">Uploaded 16 Sep, 4.2 MB</span>
                </div>
                <button className="iconbtn" aria-label="Remove">
                  <Trash2 size={13} strokeWidth={1.6} />
                </button>
              </li>
            </ul>
            <div className="dropzone small">
              <Upload size={18} strokeWidth={1.25} />
              <b>Add slides for your other two sessions</b>
              <span>PDF or PowerPoint, up to 200 MB</span>
            </div>
          </div>
        </div>

        <div className="split">
          <div className="panel form">
            <div className="panel-head">
              <b>How you are introduced</b>
            </div>
            <div className="formcol">
              <div className="photodrop">
                <div className="photoslot">
                  <Avatar name="Kwame Asare" size={74} />
                </div>
                <div>
                  <b>Headshot</b>
                  <p className="hint">Printed on your badge and shown on the public page.</p>
                  <Button icon={Upload}>Replace</Button>
                </div>
              </div>
              <Field label="Name as it should appear">
                <input defaultValue="Kwame Asare" />
              </Field>
              <Field label="Bio" hint="Read out before your session. Keep it to what matters here.">
                <textarea
                  rows={5}
                  defaultValue="Kwame builds the integration layer that connects Korle Bu's lab, pharmacy and radiology systems. He has taught FHIR to roughly four hundred engineers across West Africa."
                />
              </Field>
              <Field label="Anything to declare" hint="Required for accredited sessions.">
                <textarea rows={2} placeholder="Funding, shareholdings, advisory roles. Write none if there are none." />
              </Field>
              <div className="sec-actions">
                <Button kind="solid">Save</Button>
              </div>
            </div>
          </div>

          <div className="panel">
            <div className="panel-head">
              <b>Questions people asked you</b>
              <span className="panel-note">From your sessions</span>
            </div>
            <ul className="qa">
              <li>
                <div>
                  <b>Which FHIR version are the labs teaching?</b>
                  <span>Kofi Danso, 1 hour ago — you answered</span>
                </div>
                <Tag tone="good">Answered</Tag>
              </li>
              <li>
                <div>
                  <b>Will the sandbox accept our own test bundles?</b>
                  <span>Kofi Danso, 6 minutes ago</span>
                </div>
                <Button>Answer</Button>
              </li>
              <li>
                <div>
                  <b>Can we get the workbook before the lab starts?</b>
                  <span>Grace Amponsah, 22 minutes ago</span>
                </div>
                <Button>Answer</Button>
              </li>
            </ul>
          </div>
        </div>
      </main>
    </div>
  );
}

/* ================================================================== */
/*  Checkout                                                           */
/* ================================================================== */

function Checkout({ onBack, theme, onTheme }) {
  const [step, setStep] = useState(1);
  const [pay, setPay] = useState("momo");
  const [qty, setQty] = useState(1);
  const price = 850;
  const fee = Math.round(price * qty * 0.019) + 2;

  return (
    <div className={`portal theme-${theme}`}>
      <header className="portal-bar">
        <div>
          <span className="wordmark">Accra Health Informatics Summit</span>
          <span className="portal-sub">22–24 September 2026</span>
        </div>
        <div className="pub-bar-right">
          <ThemeToggle theme={theme} onTheme={onTheme} />
          <button className="linkish" onClick={onBack}>
            Back to console
          </button>
        </div>
      </header>

      <div className="steps">
        {["Ticket", "Your details", "Payment", "Done"].map((s, i) => (
          <div key={s} className={`step${step === i + 1 ? " on" : ""}${step > i + 1 ? " past" : ""}`}>
            <span className="stepnum mono">{step > i + 1 ? <Check size={11} strokeWidth={2.5} /> : i + 1}</span>
            {s}
          </div>
        ))}
      </div>

      <main className="checkout">
        <div className="checkmain">
          {step === 1 && (
            <>
              <h2>Choose a ticket</h2>
              <ul className="pubtickets">
                <li className="pubticket">
                  <div>
                    <b>Standard</b>
                    <span>All plenaries, exhibition floor, lunch and materials.</span>
                  </div>
                  <div className="qtypick">
                    <button onClick={() => setQty(Math.max(1, qty - 1))}>−</button>
                    <span className="mono">{qty}</span>
                    <button onClick={() => setQty(Math.min(5, qty + 1))}>+</button>
                  </div>
                </li>
                <li className="pubticket sold">
                  <div>
                    <b>Workshop pass</b>
                    <span>Everything in Standard plus both hands-on labs.</span>
                  </div>
                  <em className="mono">Full</em>
                </li>
              </ul>
              <p className="hint">
                Your place is held for 15 minutes once you continue. That is long enough for mobile
                money to clear.
              </p>
            </>
          )}

          {step === 2 && (
            <>
              <h2>Who is coming</h2>
              <div className="fields">
                <div className="tworow">
                  <Field label="First name">
                    <input defaultValue="Yaa Serwaa" />
                  </Field>
                  <Field label="Last name">
                    <input defaultValue="Mensah" />
                  </Field>
                </div>
                <Field label="Email" hint="Your entry code goes here.">
                  <input defaultValue="y.mensah@ridgehospital.gov.gh" />
                </Field>
                <Field label="Phone" hint="Used for mobile money and day-of texts.">
                  <input className="mono" defaultValue="024 000 0012" />
                </Field>
                <Field label="Organisation">
                  <input defaultValue="Ridge Hospital" />
                </Field>
                <Field label="Your role">
                  <select defaultValue="clin">
                    <option value="clin">Clinician with a systems remit</option>
                    <option>Hospital IT or informatics</option>
                    <option>Engineer or vendor</option>
                    <option>Government or regulator</option>
                  </select>
                </Field>
                <Field label="Meal preference">
                  <div className="picker">
                    {["No preference", "Vegetarian", "Halal", "Gluten free"].map((x, i) => (
                      <button key={x} className={i === 1 ? "chip on" : "chip"}>
                        {x}
                      </button>
                    ))}
                  </div>
                </Field>
                <Field label="Anything we should know" hint="Access needs, seating, anything else. Seen only by the team.">
                  <textarea rows={2} placeholder="Optional" />
                </Field>
                <Toggle on={true} onChange={() => {}} label="I agree to be photographed at the summit" hint="Photos are used in the report afterwards." />
              </div>
            </>
          )}

          {step === 3 && (
            <>
              <h2>How you would like to pay</h2>
              <div className="paypick">
                {[
                  ["momo", Smartphone, "MTN Mobile Money", "You will get a prompt on your phone"],
                  ["telecel", Smartphone, "Telecel Cash", "You will get a prompt on your phone"],
                  ["airtel", Smartphone, "AirtelTigo Money", "You will get a prompt on your phone"],
                  ["card", CreditCard, "Card", "Visa or Mastercard"],
                ].map(([k, Icon, name, note]) => (
                  <button key={k} className={pay === k ? "payopt on" : "payopt"} onClick={() => setPay(k)}>
                    <span className="radiodot" />
                    <Icon size={17} strokeWidth={1.5} />
                    <span>
                      <b>{name}</b>
                      <em>{note}</em>
                    </span>
                  </button>
                ))}
              </div>
              {pay !== "card" ? (
                <Field label="Number to charge" hint="Approve the prompt on your phone to finish.">
                  <input className="mono" defaultValue="024 000 0012" />
                </Field>
              ) : (
                <p className="hint">
                  You will be taken to our payment provider to enter your card. We never see or store
                  card numbers.
                </p>
              )}
            </>
          )}

          {step === 4 && (
            <div className="donebox">
              <FakeQR size={148} />
              <h2>You are in</h2>
              <p className="prose">
                We have emailed your entry code to y.mensah@ridgehospital.gov.gh. Show the code at
                the door on 22 September.
              </p>
              <div className="codebig mono">AHIS26-8F3K</div>
              <div className="sec-actions">
                <Button icon={Download}>Add to calendar</Button>
                <Button icon={Download}>Save ticket</Button>
              </div>
            </div>
          )}
        </div>

        {step < 4 && (
          <aside className="checkside">
            <div className="panel">
              <div className="panel-head">
                <b>Your order</b>
              </div>
              <ul className="kv">
                <li>
                  <span>
                    Standard × <span className="mono">{qty}</span>
                  </span>
                  <b className="mono">{(price * qty).toLocaleString()}</b>
                </li>
                <li>
                  <span>Processing</span>
                  <b className="mono">{fee.toLocaleString()}</b>
                </li>
                <li className="total">
                  <span>Total</span>
                  <b className="mono">GHS {(price * qty + fee).toLocaleString()}</b>
                </li>
              </ul>
              <div className="holdnote">
                <Clock size={12} strokeWidth={1.75} />
                Held for <span className="mono">14:32</span>
              </div>
              <div className="sec-actions stack">
                <Button kind="solid" onClick={() => setStep(Math.min(4, step + 1))}>
                  {step === 3 ? "Pay now" : "Continue"}
                </Button>
                {step > 1 && <Button onClick={() => setStep(step - 1)}>Go back</Button>}
              </div>
            </div>
          </aside>
        )}
      </main>
    </div>
  );
}

/* ================================================================== */
/*  App                                                                */
/* ================================================================== */

export default function MiConvenerTwo() {
  const [surface, setSurface] = useState("console");
  const [view, setView] = useState("events");
  const [theme, setTheme] = useState("dark");

  const views = {
    events: EventsHome,
    setup: EventSetup,
    venue: Venue,
    messages: Messages,
    forum: Forum,
    materials: Materials,
    reports: Reports,
    sponsors: Sponsors,
    team: Team,
    developers: Developers,
  };
  const Current = views[view];
  const back = () => setSurface("console");

  if (surface === "attendee") return <><style>{CSS}</style><AttendeePortal onBack={back} theme={theme} onTheme={setTheme} /></>;
  if (surface === "speaker") return <><style>{CSS}</style><SpeakerPortal onBack={back} theme={theme} onTheme={setTheme} /></>;
  if (surface === "checkout") return <><style>{CSS}</style><Checkout onBack={back} theme={theme} onTheme={setTheme} /></>;

  return (
    <>
      <style>{CSS}</style>
      <div className={`app theme-${theme}`}>
        <nav className="rail">
          <div className="rail-brand">
            <span className="wordmark">MiConvener</span>
            <span className="rail-ev">Ghana Health Service</span>
          </div>
          <ul>
            {NAV.map((n) => {
              const Icon = n.icon;
              return (
                <li key={n.key}>
                  <button className={view === n.key ? "navbtn on" : "navbtn"} onClick={() => setView(n.key)}>
                    <Icon size={15} strokeWidth={1.6} />
                    {n.label}
                  </button>
                </li>
              );
            })}
          </ul>
          <div className="railfoot">
            <span className="railfoot-label">Other views</span>
            <button className="navbtn" onClick={() => setSurface("attendee")}>
              <QrCode size={14} strokeWidth={1.6} />
              Attendee portal
            </button>
            <button className="navbtn" onClick={() => setSurface("speaker")}>
              <Mic size={14} strokeWidth={1.6} />
              Speaker area
            </button>
            <button className="navbtn" onClick={() => setSurface("checkout")}>
              <Ticket size={14} strokeWidth={1.6} />
              Checkout
            </button>
          </div>
        </nav>

        <main className="main">
          <div className="strip">
            <div className="strip-left">
              <Globe size={13} strokeWidth={1.6} className="dim" />
              <b>Ghana Health Service</b>
              <span className="dim">5 events</span>
            </div>
            <div className="strip-right">
              <span>
                <em className="mono">1</em> running now
              </span>
              <ThemeToggle theme={theme} onTheme={setTheme} />
            </div>
          </div>
          <div className="content">
            <Current />
          </div>
        </main>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Styles                                                             */
/* ================================================================== */

const CSS = `
body, body *, body *::before, body *::after{box-sizing:border-box;border-radius:0}
html,body,#root{height:100%}
body{margin:0}

.theme-dark{
  --ink:#08090A; --surface:#0C0E10; --raised:#111417;
  --line:#1C2226; --line-soft:#161B1E;
  --fg:#E8ECEE; --fg-dim:#8A959B; --fg-faint:#5C666B;
  --accent:#00D8C4; --accent-soft:rgba(0,216,196,.10); --accent-ink:#05201D;
  --warn:#C9922F; --alert:#C4574B; --field:#0A0C0D;
}
.theme-light{
  --ink:#FFFFFF; --surface:#FBFBFC; --raised:#F1F3F4;
  --line:#DFE3E5; --line-soft:#EBEEEF;
  --fg:#111618; --fg-dim:#5A656A; --fg-faint:#8B979C;
  --accent:#00897C; --accent-soft:rgba(0,137,124,.09); --accent-ink:#FFFFFF;
  --warn:#8A6112; --alert:#A93F33; --field:#FFFFFF;
}

.app,.portal{
  font-family:'Geist','Geist Sans',ui-sans-serif,system-ui,-apple-system,'Segoe UI','Helvetica Neue',sans-serif;
  background:var(--ink);color:var(--fg);font-size:13.5px;line-height:1.5;
  -webkit-font-smoothing:antialiased;min-height:100vh;
}
.mono{font-family:'Geist Mono',ui-monospace,'SF Mono',Menlo,monospace;font-variant-numeric:tabular-nums}
.dim{color:var(--fg-dim)}
.accent-text{color:var(--accent)}
.warntext{color:var(--warn)}
h1,h2,h3,h4{margin:0;font-weight:500;letter-spacing:-.02em}
p{margin:0}
button{font:inherit;color:inherit;background:none;border:none;cursor:pointer}
input,textarea,select{font:inherit;color:inherit;background:var(--field);border:1px solid var(--line);outline:none;width:100%;padding:7px 10px}
input:focus,textarea:focus,select:focus{border-color:var(--accent)}
input::placeholder,textarea::placeholder{color:var(--fg-faint)}
textarea{resize:vertical;line-height:1.5}
ul{margin:0;padding:0;list-style:none}
:focus-visible{outline:1px solid var(--accent);outline-offset:2px}

/* shell */
.app{display:grid;grid-template-columns:212px 1fr}
.rail{border-right:1px solid var(--line);display:flex;flex-direction:column;background:var(--surface)}
.rail-brand{padding:20px 18px 18px;border-bottom:1px solid var(--line)}
.wordmark{display:block;font-size:14px;font-weight:600;letter-spacing:-.03em}
.rail-ev{display:block;margin-top:5px;font-size:11.5px;color:var(--fg-dim)}
.rail ul{padding:8px 0}
.navbtn{display:flex;align-items:center;gap:10px;width:100%;padding:7px 18px;color:var(--fg-dim);font-size:13px;text-align:left;transition:.12s}
.navbtn:hover{color:var(--fg);background:var(--raised)}
.navbtn.on{color:var(--fg);background:var(--raised);box-shadow:inset 2px 0 0 var(--accent)}
.railfoot{margin-top:auto;border-top:1px solid var(--line);padding:12px 0 14px}
.railfoot-label{display:block;padding:0 18px 7px;font-size:11px;color:var(--fg-faint)}
.main{display:flex;flex-direction:column;min-width:0}
.content{padding:26px 30px 60px;max-width:1240px}
.strip{display:flex;align-items:center;justify-content:space-between;gap:26px;height:44px;padding:0 30px;border-bottom:1px solid var(--line);background:var(--surface);font-size:12.5px}
.strip-left{display:flex;align-items:center;gap:9px}
.strip-right{display:flex;align-items:center;gap:18px;color:var(--fg-dim)}
.strip-right em{font-style:normal;color:var(--fg)}
.themetoggle{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border:1px solid var(--line);color:var(--fg-dim)}
.themetoggle:hover{color:var(--accent);border-color:var(--accent)}
.pulse{width:6px;height:6px;background:var(--accent);animation:pulse 2.4s ease-in-out infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
@media (prefers-reduced-motion:reduce){.pulse{animation:none}}

/* headings + controls */
.sec-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:20px}
.sec-head h2{font-size:22px}
.sec-note{margin-top:5px;color:var(--fg-dim);font-size:13px;max-width:62ch}
.sec-actions{display:flex;gap:8px;flex-wrap:wrap}
.sec-actions.stack{flex-direction:column}
.sec-actions.stack .btn{width:100%;justify-content:center;padding:9px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:1px solid var(--line);color:var(--fg-dim);font-size:12.5px;white-space:nowrap;transition:.12s}
.btn:hover{border-color:var(--fg-faint);color:var(--fg)}
.btn-solid{background:var(--accent);border-color:var(--accent);color:var(--accent-ink);font-weight:500}
.btn-solid:hover{opacity:.86;color:var(--accent-ink)}
.iconbtn{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border:1px solid var(--line);color:var(--fg-faint)}
.iconbtn:hover{color:var(--alert);border-color:var(--alert)}
.iconbtn.plain:hover{color:var(--accent);border-color:var(--accent)}
.tag{display:inline-block;padding:1px 7px;border:1px solid var(--line);font-size:11px;color:var(--fg-dim);white-space:nowrap}
.tone-good{border-color:var(--accent);color:var(--accent)}
.tone-accent{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}
.tone-warn{border-color:var(--warn);color:var(--warn)}
.chip{display:inline-flex;align-items:center;gap:8px;padding:5px 11px;border:1px solid var(--line);color:var(--fg-dim);font-size:12.5px;text-align:left}
.chip:hover{border-color:var(--fg-faint);color:var(--fg)}
.chip.on{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}
.picker{display:flex;flex-wrap:wrap;gap:6px}
.segmented{display:flex;border:1px solid var(--line)}
.seg{flex:1;padding:6px 10px;font-size:12.5px;color:var(--fg-dim);border-right:1px solid var(--line)}
.seg:last-child{border-right:none}
.seg.on{background:var(--accent-soft);color:var(--accent)}
.switchrow{display:flex;align-items:flex-start;gap:11px;text-align:left;padding:2px 0}
.switch{position:relative;width:30px;height:16px;border:1px solid var(--line);background:var(--field);flex-shrink:0;margin-top:1px;transition:.14s}
.switch .knob{position:absolute;top:2px;left:2px;width:10px;height:10px;background:var(--fg-faint);transition:.14s}
.switch.on{border-color:var(--accent);background:var(--accent-soft)}
.switch.on .knob{left:16px;background:var(--accent)}
.switchtext b{display:block;font-weight:400;font-size:12.5px}
.switchtext span{display:block;font-size:11px;color:var(--fg-faint);margin-top:2px;line-height:1.45}
.avatar{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);background:var(--raised);color:var(--fg-dim);flex-shrink:0}

/* stats + panels */
.stats{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid var(--line);margin-bottom:22px;background:var(--surface)}
.stat{padding:16px 18px;border-right:1px solid var(--line)}
.stat:last-child{border-right:none}
.stat-label{font-size:12px;color:var(--fg-dim)}
.stat-value{margin-top:7px;font-size:25px;letter-spacing:-.03em;font-family:'Geist Mono',ui-monospace,monospace;font-variant-numeric:tabular-nums}
.stat-value.accent{color:var(--accent)}
.stat-sub{margin-top:5px;font-size:11.5px;color:var(--fg-faint)}
.panel{border:1px solid var(--line);padding:16px 18px;margin-bottom:22px;background:var(--surface)}
.panel.flush{padding:0}
.panel.flush .panel-head{padding:14px 18px;border-bottom:1px solid var(--line);margin:0}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px}
.panel-head b{font-weight:500}
.panel-note{font-size:11.5px;color:var(--fg-faint)}
.split{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.panel.side{margin:0}
.hint{margin-top:5px;font-size:11px;color:var(--fg-faint);line-height:1.45}
.prose{font-size:13px;line-height:1.65;color:var(--fg-dim);max-width:66ch}
.lede{font-size:15px;line-height:1.55;color:var(--fg-dim);max-width:60ch;margin-bottom:20px}

/* forms */
.panel.form{padding:0}
.panel.form .panel-head{padding:14px 18px;border-bottom:1px solid var(--line);margin:0}
.formgrid{display:grid;grid-template-columns:1fr 1fr}
.formcol{padding:18px;display:flex;flex-direction:column;gap:14px}
.formcol+.formcol{border-left:1px solid var(--line)}
.field label{display:block;font-size:11.5px;color:var(--fg-dim);margin-bottom:5px}
.tworow{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fields{display:flex;flex-direction:column;gap:14px}
.inputgroup{display:flex;border:1px solid var(--line);background:var(--field)}
.inputgroup .prefix{padding:7px 9px;font-size:12px;color:var(--fg-faint);border-right:1px solid var(--line)}
.inputgroup input{border:none;background:none}
.formfoot{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 18px;border-top:1px solid var(--line);flex-wrap:wrap}
.formfoot .hint{margin:0;max-width:52ch}
.radiocards{display:flex;flex-direction:column;gap:1px;background:var(--line);border:1px solid var(--line)}
.radiocard{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:var(--ink);text-align:left}
.radiocard b{display:block;font-weight:400;font-size:12.5px}
.radiocard em{display:block;font-style:normal;font-size:11px;color:var(--fg-faint);margin-top:2px}
.radiodot{width:11px;height:11px;border:1px solid var(--fg-faint);flex-shrink:0;margin-top:3px}
.radiocard.on .radiodot,.payopt.on .radiodot{border-color:var(--accent);background:var(--accent)}
.radiocard.on b{color:var(--accent)}
.photodrop{display:flex;gap:14px;align-items:flex-start;padding-bottom:14px;border-bottom:1px solid var(--line-soft)}
.photoslot{display:flex;align-items:center;justify-content:center;width:76px;height:76px;border:1px solid var(--line);background:var(--field);color:var(--fg-faint);flex-shrink:0}
.photodrop b{font-weight:500;font-size:12.5px}
.photodrop .hint{margin:4px 0 9px}
.filerow{display:flex;align-items:center;gap:12px;padding:11px 12px;border:1px solid var(--line);background:var(--field);color:var(--fg-dim)}
.filerow div{flex:1}
.filerow b{display:block;font-weight:400;color:var(--fg);font-size:12.5px}
.filerow .hint{margin-top:2px}
.filethumb{width:52px;height:32px;border:1px solid var(--line);background:linear-gradient(120deg,var(--accent-soft),transparent)}
.subtabs{display:flex;border-bottom:1px solid var(--line);margin-bottom:22px}
.subtab{padding:9px 15px;font-size:12.5px;color:var(--fg-dim);margin-bottom:-1px;border-bottom:1px solid transparent}
.subtab.on{color:var(--accent);border-bottom-color:var(--accent)}
.featgrid{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px}
.qlist li{display:flex;justify-content:space-between;gap:14px;padding:8px 0;border-bottom:1px solid var(--line-soft);font-size:12.5px}
.qlist li:last-child{border-bottom:none}
.qlist b{font-weight:400}
.qlist span{color:var(--fg-faint);font-size:11.5px;text-align:right}
.sharecard{display:flex;gap:12px;border:1px solid var(--line);padding:11px;background:var(--field)}
.sharethumb{width:74px;height:74px;border:1px solid var(--line);background:linear-gradient(120deg,var(--accent-soft),transparent);flex-shrink:0}
.sharemeta b{display:block;font-weight:400;font-size:13px}
.sharemeta span{display:block;font-size:11.5px;color:var(--fg-dim);margin-top:3px;line-height:1.45}
.sharemeta em{display:block;font-style:normal;font-size:11px;color:var(--fg-faint);margin-top:6px}
.danger{display:flex;align-items:center;gap:16px;padding:13px 14px;border:1px solid var(--alert);margin-top:auto}
.danger b{display:block;font-weight:500;font-size:12.5px;color:var(--alert)}
.danger span{display:block;font-size:11.5px;color:var(--fg-dim);margin-top:3px;line-height:1.45}

/* tables */
.table{width:100%;border-collapse:collapse;font-size:12.5px}
.table th{padding:10px 14px;text-align:left;font-weight:400;font-size:11.5px;color:var(--fg-faint);border-bottom:1px solid var(--line)}
.table td{padding:11px 14px;border-bottom:1px solid var(--line-soft);vertical-align:middle}
.table tbody tr:last-child td{border-bottom:none}
.table tbody tr:hover{background:var(--raised)}
.table b{font-weight:400}
.cellwho{display:flex;align-items:center;gap:9px}
.cellwho div{display:flex;flex-direction:column}
.rowact{text-align:right}
.evdays,.filesize{font-size:11px;margin-left:6px}
.cellfill{display:flex;align-items:center;gap:8px;max-width:150px}
.cellfill.narrow{max-width:96px}
.fillbar{flex:1;height:3px;background:var(--line);min-width:40px}
.fillbar div{height:100%;background:var(--accent);opacity:.7}
.fillbar .warnfill{background:var(--warn)}
.ok{display:inline-flex;align-items:center;gap:5px;color:var(--accent)}
.pending{color:var(--warn)}

/* queue + kv */
.queue li{display:flex;align-items:center;gap:13px;padding:11px 0;border-top:1px solid var(--line-soft)}
.queue li:first-child{border-top:none}
.queue li div{flex:1;min-width:0}
.queue b{display:block;font-weight:400}
.queue li span:not(.qtime):not(.dot){display:block;font-size:11.5px;color:var(--fg-faint)}
.qtime{color:var(--warn);width:26px;font-size:12px;flex-shrink:0}
.dot{display:flex;align-items:center;justify-content:center;width:18px;height:18px;flex-shrink:0}
.dot.warnbg{background:var(--accent-soft);color:var(--warn)}
.kv li{display:flex;justify-content:space-between;gap:14px;padding:7px 0;border-bottom:1px solid var(--line-soft);font-size:12.5px}
.kv li:last-child{border-bottom:none}
.kv span{color:var(--fg-dim)}
.kv b{font-weight:400}
.kv.big li{padding:10px 0}
.kv .total{border-top:1px solid var(--line);border-bottom:none;padding-top:11px;font-size:14px}
.scan-fail{display:flex;align-items:center;gap:9px;padding:11px 18px;border-top:1px solid var(--line);color:var(--warn);font-size:12px}
.kyc{display:flex;align-items:center;gap:9px;padding:12px 0 0;margin-top:12px;border-top:1px solid var(--line);font-size:12px;color:var(--accent)}
.ai-strip{display:flex;align-items:center;gap:12px;margin-top:16px;padding:12px 14px;border:1px solid var(--line);background:var(--accent-soft);color:var(--accent)}
.ai-strip span{flex:1;font-size:12.5px;color:var(--fg-dim)}
.mailpreview{border:1px solid var(--line);padding:15px;background:var(--field)}
.mail-sub{font-size:13.5px;font-weight:500;margin-bottom:8px}
.codeline{margin-top:12px;font-size:13px;color:var(--accent)}
.audiencenote{display:flex;align-items:center;gap:8px;padding:9px 11px;border:1px solid var(--line);font-size:12px;color:var(--fg-dim)}
.autolist li{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--line-soft)}
.autolist li:first-child{border-top:none}
.autolist li div{flex:1}
.autolist b{display:block;font-weight:400;font-size:12.5px}
.autolist span{display:block;font-size:11.5px;color:var(--fg-faint);margin-top:2px}

/* seating */
.seatwrap{display:grid;grid-template-columns:1fr 280px;gap:22px;margin-bottom:22px}
.seatpanel{margin:0;overflow-x:auto}
.stage{text-align:center;padding:7px;border:1px solid var(--line);color:var(--fg-faint);font-size:11.5px;margin-bottom:20px}
.seatmap{display:flex;flex-direction:column;gap:5px;min-width:420px}
.seatrow{display:flex;align-items:center;gap:5px}
.rowlabel{width:16px;font-size:10.5px;color:var(--fg-faint)}
.seat{width:18px;height:18px;border:1px solid var(--line);background:var(--field);padding:0;flex-shrink:0}
.seat.taken{background:var(--fg-faint);border-color:var(--fg-faint)}
.seat.held{background:var(--accent-soft);border-color:var(--accent)}
.seat.accessible{border-color:var(--warn);background:transparent}
.seat.picked{outline:2px solid var(--accent);outline-offset:1px}
.seat:hover{border-color:var(--accent)}
.legend{display:flex;flex-wrap:wrap;gap:16px;margin-top:20px;padding-top:14px;border-top:1px solid var(--line);font-size:11.5px;color:var(--fg-dim)}
.legenditem{display:flex;align-items:center;gap:7px}
.legenditem i{display:inline-block;width:12px;height:12px}
.seatdetail{display:flex;flex-direction:column;gap:14px}

/* forum */
.forumwrap{display:grid;grid-template-columns:300px 1fr 300px;gap:22px;align-items:start}
.threadlist{margin:0}
.thread{padding:12px 16px;border-bottom:1px solid var(--line-soft);cursor:pointer}
.thread:hover{background:var(--raised)}
.thread.on{background:var(--raised);box-shadow:inset 2px 0 0 var(--accent)}
.threadtop{display:flex;gap:8px;align-items:flex-start}
.threadtop b{font-weight:400;font-size:12.5px;line-height:1.4}
.unans{width:5px;height:5px;background:var(--warn);flex-shrink:0;margin-top:6px}
.threadmeta{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;font-size:11px;color:var(--fg-dim)}
.threadtags{display:flex;gap:5px;margin-top:7px;flex-wrap:wrap}
.threadview .panel{margin:0}
.replies li{display:flex;gap:11px;padding:14px 0;border-top:1px solid var(--line-soft)}
.replies li:first-child{border-top:none;padding-top:4px}
.replies li div{flex:1}
.replyhead{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:5px;font-size:11.5px}
.replyhead b{font-weight:500;font-size:12.5px}
.replies p{font-size:13px;line-height:1.6;color:var(--fg-dim)}
.reply.tagged{background:var(--accent-soft);padding-left:11px;padding-right:11px}
.replyacts{display:flex;gap:14px;margin-top:8px}
.replyacts button{font-size:11.5px;color:var(--fg-faint);border-bottom:1px solid transparent}
.replyacts button:hover{color:var(--accent);border-bottom-color:var(--accent)}
.replybox{display:flex;gap:9px;align-items:flex-end;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}
.aiside{position:sticky;top:20px}
.summarising{display:flex;align-items:center;gap:9px;padding:16px 0;font-size:12.5px;color:var(--fg-dim)}
.summary{margin-top:14px}
.summary h4{font-size:11.5px;color:var(--fg-faint);margin:16px 0 7px;font-weight:400}
.bullets li{position:relative;padding:5px 0 5px 13px;font-size:12.5px;color:var(--fg-dim);line-height:1.5}
.bullets li::before{content:'';position:absolute;left:0;top:12px;width:5px;height:1px;background:var(--accent)}
.bullets.warn li::before{background:var(--warn)}
.ailabel{display:flex;align-items:flex-start;gap:7px;margin:16px 0 12px;padding-top:12px;border-top:1px solid var(--line-soft);font-size:11px;color:var(--fg-faint);line-height:1.45}

/* materials + reports */
.dropzone{display:flex;flex-direction:column;align-items:center;gap:5px;padding:28px;border:1px dashed var(--line);color:var(--fg-faint);text-align:center}
.dropzone.small{padding:20px;margin-top:14px}
.dropzone b{font-weight:400;font-size:12.5px;color:var(--fg-dim)}
.dropzone span{font-size:11px}
.joblist li{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 18px;border-bottom:1px solid var(--line-soft)}
.joblist li:last-child{border-bottom:none}
.joblist b{display:block;font-weight:400}
.joblist .dim{display:block;font-size:11.5px;margin-top:2px}
.jobprog{display:flex;align-items:center;gap:9px;width:120px}
.builder{display:flex;flex-direction:column;gap:16px}
.builderrow{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.builderfoot{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-top:14px;border-top:1px solid var(--line);flex-wrap:wrap}
.builderfoot .hint{margin:0}
.catalogue{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--line)}
.export{display:flex;align-items:center;gap:8px;padding:11px 12px;background:var(--surface);color:var(--fg-dim);font-size:12px;text-align:left}
.export:hover{background:var(--raised);color:var(--fg)}

/* sponsors */
.sponsorgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.sponsorcard{display:flex;align-items:center;gap:13px;padding:13px;border:1px solid var(--line)}
.logoslot{display:flex;align-items:center;justify-content:center;width:46px;height:46px;border:1px solid var(--line);background:var(--field);font-size:13px;color:var(--fg-dim);letter-spacing:.02em;flex-shrink:0}
.sponsormain{flex:1;min-width:0}
.sponsormain b{display:block;font-weight:400;font-size:13px}
.sponsormain .dim{display:block;font-size:11.5px;margin:2px 0 7px}

/* team + developers */
.rolelist li{padding:10px 0;border-bottom:1px solid var(--line-soft)}
.rolelist li:last-child{border-bottom:none}
.rolelist b{display:block;font-weight:500;font-size:12.5px}
.rolelist span{display:block;font-size:11.5px;color:var(--fg-dim);margin-top:2px}
.auditlist li{display:grid;grid-template-columns:70px 150px 1fr;gap:12px;padding:9px 0;border-bottom:1px solid var(--line-soft);font-size:12px}
.auditlist li:last-child{border-bottom:none}
.audittime{color:var(--fg-faint)}
.auditlist b{font-weight:400}
.auditlist span:last-child{color:var(--fg-dim)}
.keylist li,.hooklist li{display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid var(--line-soft)}
.keylist li:last-child,.hooklist li:last-child{border-bottom:none}
.keylist li div,.hooklist li div{flex:1;min-width:0}
.keylist b,.hooklist b{display:block;font-weight:400;font-size:12.5px}
.keylist .dim,.hooklist .dim{display:block;font-size:11px;margin-top:2px}
.hookurl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.scopes{font-size:11px}
.addrow{color:var(--fg-faint);cursor:pointer}
.addrow:hover{background:var(--raised)}
.addrow b{color:var(--fg-dim)}
.dellist li{display:grid;grid-template-columns:44px 1fr 68px 56px auto;gap:10px;align-items:center;padding:10px 18px;border-bottom:1px solid var(--line-soft);font-size:12px}
.dellist li:last-child{border-bottom:none}
.code{text-align:center;padding:1px 0;border:1px solid var(--line);font-size:11px}
.code.ok{color:var(--accent);border-color:var(--accent)}
.code.bad{color:var(--alert);border-color:var(--alert)}

/* portals */
.portal-bar{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:14px 30px;border-bottom:1px solid var(--line);background:var(--surface)}
.portal-sub{display:block;font-size:11.5px;color:var(--fg-dim);margin-top:3px}
.pub-bar-right{display:flex;align-items:center;gap:16px}
.linkish{font-size:12.5px;color:var(--fg-dim);border-bottom:1px solid var(--line)}
.linkish:hover{color:var(--accent);border-color:var(--accent)}
.pub-tabs{display:flex;padding:0 30px;border-bottom:1px solid var(--line);overflow-x:auto}
.pubtab{padding:12px 15px;font-size:13px;color:var(--fg-dim);margin-bottom:-1px;border-bottom:1px solid transparent;white-space:nowrap}
.pubtab:first-child{padding-left:0}
.pubtab.on{color:var(--accent);border-bottom-color:var(--accent)}
.portal-body{max-width:900px;margin:0 auto;padding:34px 30px 80px}
.qrbig{display:grid;grid-template-columns:repeat(12,1fr);background:#fff;padding:6px;gap:1px;flex-shrink:0}
.qrbig i{background:#fff}
.qrbig i.on{background:#000}
.ticketcard{display:flex;gap:30px;border:1px solid var(--line);padding:26px;background:var(--surface);flex-wrap:wrap}
.tc-meta{flex:1;min-width:240px}
.tc-meta h2{font-size:22px}
.tc-meta .kv{margin:18px 0}
.myagenda li{display:flex;align-items:center;gap:16px;padding:13px 0;border-top:1px solid var(--line-soft)}
.myagenda li:first-child{border-top:none}
.myagenda li div{flex:1}
.myagenda b{display:block;font-weight:400;font-size:13.5px}
.myagenda .dim{display:block;font-size:11.5px;margin-top:2px}
.myagenda .time{width:46px;font-size:12px;color:var(--fg-dim);flex-shrink:0}
.myagenda li.mine{box-shadow:inset 2px 0 0 var(--accent);padding-left:12px}
.addbtn{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid var(--line);font-size:12px;color:var(--fg-dim)}
.myagenda li.mine .addbtn{color:var(--accent);border-color:var(--accent)}
.dllist li{display:flex;align-items:center;gap:12px;padding:13px 0;border-top:1px solid var(--line-soft)}
.dllist li:first-child{border-top:none}
.dllist.tight li{padding:9px 0}
.dllist li div{flex:1}
.dllist b{display:block;font-weight:400;font-size:13px}
.dllist .dim{display:block;font-size:11.5px;margin-top:2px}
.asktwo{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.sentbox{display:flex;align-items:center;gap:12px;padding:16px;border:1px solid var(--accent);background:var(--accent-soft);color:var(--accent)}
.sentbox.wide{margin-bottom:22px}
.sentbox b{display:block;font-weight:500;font-size:13px}
.sentbox span{display:block;font-size:12px;color:var(--fg-dim);margin-top:2px}
.msgthread li{display:flex;gap:16px;padding:16px 0;border-top:1px solid var(--line-soft)}
.msgthread li:first-child{border-top:none}
.msgdate{width:60px;font-size:11.5px;color:var(--fg-faint);flex-shrink:0}
.msgthread b{display:block;font-weight:500;font-size:13.5px}
.msgthread p{font-size:12.5px;color:var(--fg-dim);margin-top:5px;line-height:1.6}
.confirmbar{display:flex;align-items:center;gap:20px;padding:16px 18px;border:1px solid var(--accent);background:var(--accent-soft);margin-bottom:22px;flex-wrap:wrap}
.confirmbar div{flex:1;min-width:240px}
.confirmbar b{display:block;font-weight:500;font-size:14px}
.confirmbar span{display:block;font-size:12.5px;color:var(--fg-dim);margin-top:4px;line-height:1.5}
.myses li{display:flex;gap:16px;padding:13px 18px;border-bottom:1px solid var(--line-soft)}
.myses li:last-child{border-bottom:none}
.myses .time{width:120px;font-size:11.5px;color:var(--fg-dim);flex-shrink:0}
.myses b{display:block;font-weight:400;font-size:13px}
.myses .dim{display:block;font-size:11.5px;margin-top:2px}
.qa li{display:flex;align-items:center;gap:16px;padding:12px 0;border-top:1px solid var(--line-soft)}
.qa li:first-child{border-top:none}
.qa div{flex:1}
.qa b{display:block;font-weight:400}
.qa span{font-size:11.5px;color:var(--fg-faint)}

/* checkout */
.steps{display:flex;gap:0;max-width:900px;margin:0 auto;padding:20px 30px 0}
.step{display:flex;align-items:center;gap:8px;padding:0 18px 14px 0;font-size:12.5px;color:var(--fg-faint)}
.step.on{color:var(--fg)}
.step.past{color:var(--accent)}
.stepnum{display:flex;align-items:center;justify-content:center;width:19px;height:19px;border:1px solid var(--line);font-size:11px}
.step.on .stepnum{border-color:var(--accent);color:var(--accent)}
.step.past .stepnum{border-color:var(--accent);background:var(--accent);color:var(--accent-ink)}
.checkout{display:grid;grid-template-columns:1fr 280px;gap:34px;max-width:900px;margin:0 auto;padding:14px 30px 80px}
.checkmain h2{font-size:19px;margin-bottom:18px}
.pubtickets{border-top:1px solid var(--line);margin-bottom:14px}
.pubticket{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:15px 0;border-bottom:1px solid var(--line-soft)}
.pubticket b{display:block;font-weight:500;font-size:13.5px}
.pubticket span{display:block;font-size:12.5px;color:var(--fg-dim);margin-top:3px}
.pubticket em{font-style:normal;font-size:14px}
.pubticket.sold{opacity:.45}
.qtypick{display:flex;align-items:center;border:1px solid var(--line)}
.qtypick button{width:30px;height:30px;color:var(--fg-dim)}
.qtypick button:hover{color:var(--accent)}
.qtypick span{width:30px;text-align:center;font-size:13px;border-left:1px solid var(--line);border-right:1px solid var(--line);line-height:30px}
.paypick{display:flex;flex-direction:column;gap:1px;background:var(--line);border:1px solid var(--line);margin-bottom:18px}
.payopt{display:flex;align-items:center;gap:12px;padding:13px 14px;background:var(--ink);text-align:left;color:var(--fg-dim)}
.payopt b{display:block;font-weight:400;font-size:13px;color:var(--fg)}
.payopt em{display:block;font-style:normal;font-size:11.5px;color:var(--fg-faint);margin-top:2px}
.payopt.on b{color:var(--accent)}
.checkside .panel{margin:0;position:sticky;top:20px}
.holdnote{display:flex;align-items:center;gap:7px;padding:10px 0;margin:8px 0 14px;border-top:1px solid var(--line-soft);font-size:11.5px;color:var(--warn)}
.donebox{display:flex;flex-direction:column;align-items:flex-start;gap:16px}
.donebox h2{font-size:26px;margin:0}
.codebig{font-size:22px;color:var(--accent);letter-spacing:.02em}

/* responsive */
@media (max-width:1180px){
  .forumwrap{grid-template-columns:1fr 1fr}
  .aiside{grid-column:1 / -1;position:static}
}
@media (max-width:1080px){
  .split,.formgrid,.seatwrap,.asktwo,.sponsorgrid,.featgrid{grid-template-columns:1fr}
  .formcol+.formcol{border-left:none;border-top:1px solid var(--line)}
  .catalogue,.builderrow{grid-template-columns:repeat(2,1fr)}
  .checkout{grid-template-columns:1fr}
  .checkside .panel{position:static}
  .forumwrap{grid-template-columns:1fr}
}
@media (max-width:820px){
  .app{grid-template-columns:1fr}
  .rail{flex-direction:row;align-items:center;overflow-x:auto;border-right:none;border-bottom:1px solid var(--line)}
  .rail-brand{border-bottom:none;border-right:1px solid var(--line);padding:12px 16px;white-space:nowrap}
  .rail-ev{display:none}
  .rail ul{display:flex;padding:0}
  .navbtn{padding:14px;white-space:nowrap}
  .navbtn.on{box-shadow:inset 0 -2px 0 var(--accent)}
  .railfoot{margin-top:0;border-top:none;border-left:1px solid var(--line);display:flex;padding:0}
  .railfoot-label{display:none}
  .stats{grid-template-columns:repeat(2,1fr)}
  .stat:nth-child(2n){border-right:none}
  .stat:nth-child(-n+2){border-bottom:1px solid var(--line)}
  .content{padding:20px 18px 50px}
  .portal-bar,.pub-tabs,.portal-body,.steps,.checkout{padding-left:18px;padding-right:18px}
  .catalogue,.builderrow,.tworow{grid-template-columns:1fr}
  .auditlist li{grid-template-columns:1fr;gap:3px}
  .dellist li{grid-template-columns:44px 1fr;gap:8px}
}
`;
