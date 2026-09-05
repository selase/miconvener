import React, { useState, useEffect, useMemo } from "react";
import {
  LayoutGrid,
  CalendarRange,
  Mic,
  Ticket,
  Users,
  MailPlus,
  ScanLine,
  Radio,
  CreditCard,
  Wallet,
  Download,
  Search,
  Plus,
  Check,
  X,
  ChevronLeft,
  ChevronRight,
  AlertTriangle,
  Smartphone,
  Building2,
  Clock,
  Sun,
  Moon,
  Image,
  Globe,
  Lock,
  Upload,
  Trash2,
  Link2,
} from "lucide-react";

/* ================================================================== */
/*  Demo content                                                       */
/* ================================================================== */

const EVENT = {
  name: "Accra Health Informatics Summit",
  dates: "22–24 September 2026",
  venue: "Kempinski Gold Coast City, Accra",
  capacity: 640,
  registered: 587,
  confirmed: 541,
  checkedIn: 388,
  gross: 412_400,
  net: 371_160,
  visibility: "public",
};

const DAYS = [
  { id: 1, label: "Day 1", date: "Tue 22 Sep", theme: "Foundations and national policy" },
  { id: 2, label: "Day 2", date: "Wed 23 Sep", theme: "Interoperability in practice" },
  { id: 3, label: "Day 3", date: "Thu 24 Sep", theme: "Clinical AI and closing plenary" },
];

const TRACKS = [
  { id: "a", name: "Main auditorium", room: "Grand Ballroom" },
  { id: "b", name: "Workshop hall", room: "Volta Room" },
  { id: "c", name: "Clinic track", room: "Ashanti Room" },
];

const SESSIONS = {
  1: [
    { id: "s1", track: "a", start: 0, end: 30, title: "Registration and coffee", type: "break" },
    { id: "s2", track: "a", start: 30, end: 90, title: "Opening plenary: a national health record by 2030", type: "keynote", speaker: "Dr Ama Boateng", seats: 420, taken: 397 },
    { id: "s3", track: "b", start: 45, end: 165, title: "Hands-on: FHIR resource modelling", type: "workshop", speaker: "Kwame Asare", seats: 60, taken: 60 },
    { id: "s4", track: "c", start: 60, end: 150, title: "Ward-level data capture without tablets", type: "panel", speaker: "Panel of four", seats: 90, taken: 71 },
    { id: "s5", track: "a", start: 100, end: 160, title: "What the Ministry actually funds", type: "plenary", speaker: "Hon. E. Mensah", seats: 420, taken: 340 },
    { id: "s6", track: "a", start: 180, end: 240, title: "Lunch and exhibition", type: "break" },
    { id: "s7", track: "b", start: 195, end: 300, title: "Clinic: writing your first integration", type: "workshop", speaker: "Kwame Asare", seats: 60, taken: 44, clash: true },
    { id: "s8", track: "c", start: 240, end: 330, title: "Consent, records and the Data Protection Act", type: "panel", speaker: "Efua Nyarko", seats: 90, taken: 63 },
    { id: "s9", track: "a", start: 255, end: 345, title: "Case study: three district hospitals, one registry", type: "keynote", speaker: "Dr Ama Boateng", seats: 420, taken: 288, clash: true },
  ],
  2: [
    { id: "t1", track: "a", start: 0, end: 60, title: "Morning briefing", type: "plenary", speaker: "Programme chair", seats: 420, taken: 310 },
    { id: "t2", track: "b", start: 30, end: 180, title: "Interoperability lab", type: "workshop", speaker: "Selorm Attoh", seats: 60, taken: 58 },
    { id: "t3", track: "c", start: 60, end: 150, title: "Procurement that does not lock you in", type: "panel", speaker: "Panel of three", seats: 90, taken: 52 },
    { id: "t4", track: "a", start: 180, end: 240, title: "Lunch", type: "break" },
    { id: "t5", track: "a", start: 240, end: 330, title: "Live demo: cross-facility referral", type: "keynote", speaker: "Kwame Asare", seats: 420, taken: 265 },
  ],
  3: [
    { id: "u1", track: "a", start: 30, end: 120, title: "Clinical AI: what is actually deployed", type: "keynote", speaker: "Dr Naa Okaikor", seats: 420, taken: 355 },
    { id: "u2", track: "b", start: 60, end: 180, title: "Evaluating a model before it touches a patient", type: "workshop", speaker: "Efua Nyarko", seats: 60, taken: 49 },
    { id: "u3", track: "a", start: 180, end: 240, title: "Lunch", type: "break" },
    { id: "u4", track: "a", start: 240, end: 320, title: "Closing plenary and commitments", type: "plenary", speaker: "All chairs", seats: 420, taken: 302 },
  ],
};

const SPEAKERS = [
  {
    id: 1,
    name: "Dr Ama Boateng",
    title: "Director, Health Informatics",
    org: "Ghana Health Service",
    email: "a.boateng@ghs.gov.gh",
    country: "Ghana",
    status: "confirmed",
    slides: true,
    disclosure: true,
    bio: "Ama has led the national patient identifier programme since 2021, after a decade running informatics at Korle Bu. She writes and speaks on what it takes to move a health system from paper to records that actually follow the patient.",
    sessions: ["Opening plenary: a national health record by 2030", "Case study: three district hospitals, one registry"],
    links: ["ghs.gov.gh/informatics"],
  },
  {
    id: 2,
    name: "Kwame Asare",
    title: "Principal engineer",
    org: "Korle Bu Teaching Hospital",
    email: "k.asare@kbth.gov.gh",
    country: "Ghana",
    status: "confirmed",
    slides: true,
    disclosure: true,
    bio: "Kwame builds the integration layer that connects Korle Bu's lab, pharmacy and radiology systems. He runs the summit's hands-on labs and has taught FHIR to roughly four hundred engineers across West Africa.",
    sessions: ["Hands-on: FHIR resource modelling", "Clinic: writing your first integration", "Live demo: cross-facility referral"],
    links: ["kwameasare.dev"],
  },
  {
    id: 3,
    name: "Efua Nyarko",
    title: "Data protection counsel",
    org: "Independent, Accra",
    email: "efua@nyarkolegal.gh",
    country: "Ghana",
    status: "confirmed",
    slides: false,
    disclosure: false,
    bio: "Efua advises hospitals and health startups on consent, retention and cross-border transfer under the Data Protection Act. She sat on the working group that drafted the health sector guidance.",
    sessions: ["Consent, records and the Data Protection Act", "Evaluating a model before it touches a patient"],
    links: [],
  },
  {
    id: 4,
    name: "Dr Naa Okaikor",
    title: "Clinical lead",
    org: "University of Ghana Medical Centre",
    email: "n.okaikor@ugmc.edu.gh",
    country: "Ghana",
    status: "confirmed",
    slides: true,
    disclosure: true,
    bio: "Naa runs the triage AI pilot at UGMC and is unusually candid about what it gets wrong. Her closing keynote covers three deployments, two of which were stopped.",
    sessions: ["Clinical AI: what is actually deployed"],
    links: [],
  },
  {
    id: 5,
    name: "Selorm Attoh",
    title: "Platform architect",
    org: "Purpledot Limited",
    email: "selorm@purpledot.io",
    country: "Ghana",
    status: "invited",
    slides: false,
    disclosure: false,
    bio: "Selorm designs the data platforms behind several Ghanaian health and education products. Invited to run the Day 2 interoperability lab.",
    sessions: ["Interoperability lab"],
    links: ["purpledot.io"],
  },
  {
    id: 6,
    name: "Hon. E. Mensah",
    title: "Deputy Minister",
    org: "Ministry of Health",
    email: "office@moh.gov.gh",
    country: "Ghana",
    status: "confirmed",
    slides: false,
    disclosure: false,
    bio: "Speaking on the Ministry's funding priorities for digital health through to 2028.",
    sessions: ["What the Ministry actually funds"],
    links: [],
  },
];

const TICKET_TYPES = [
  { id: 1, name: "Standard", price: 850, quota: 480, sold: 431, opens: "12 Jun", closes: "20 Sep", visibility: "public", approval: false, blurb: "All plenaries, exhibition floor, lunch and materials." },
  { id: 2, name: "Workshop pass", price: 1200, quota: 120, sold: 118, opens: "12 Jun", closes: "18 Sep", visibility: "public", approval: false, blurb: "Everything in Standard plus both hands-on labs." },
  { id: 3, name: "Student", price: 250, quota: 60, sold: 60, opens: "12 Jun", closes: "01 Sep", visibility: "public", approval: true, blurb: "Proof of enrolment reviewed before confirmation." },
  { id: 4, name: "Faculty", price: 0, quota: 40, sold: 22, opens: "01 May", closes: "22 Sep", visibility: "invite", approval: false, blurb: "Complimentary. Issued from the speaker invitations." },
  { id: 5, name: "Sponsor delegate", price: 0, quota: 30, sold: 18, opens: "01 May", closes: "22 Sep", visibility: "hidden", approval: false, blurb: "Allocated against each sponsorship package." },
];

const GUESTS = [
  { name: "Prof. Kojo Amankwah", email: "k.amankwah@ug.edu.gh", group: "Faculty", state: "accepted", plus: 1, sent: "14 Aug" },
  { name: "Adjoa Frimpong", email: "adjoa@citinewsroom.com", group: "Press", state: "opened", plus: 0, sent: "16 Aug" },
  { name: "Dr Yaw Oppong", email: "y.oppong@who.int", group: "Partner", state: "accepted", plus: 2, sent: "14 Aug" },
  { name: "Nana Adwoa Sarpong", email: "nas@mtn.com.gh", group: "Sponsor", state: "no_reply", plus: 3, sent: "16 Aug" },
  { name: "Emmanuel Tetteh", email: "e.tetteh@moh.gov.gh", group: "Government", state: "accepted", plus: 1, sent: "12 Aug" },
  { name: "Linda Ofori", email: "linda@joyonline.com", group: "Press", state: "declined", plus: 0, sent: "16 Aug" },
  { name: "Dr Samuel Ankrah", email: "s.ankrah@nyaho.com", group: "Partner", state: "opened", plus: 1, sent: "18 Aug" },
];

const REGISTRATIONS = [
  { tag: "AHIS26-8F3K", name: "Yaa Serwaa Mensah", org: "Ridge Hospital", ticket: "Standard", status: "confirmed", seat: "C-114", paid: 850, segs: ["vegetarian"] },
  { tag: "AHIS26-2M7Q", name: "Kofi Danso", org: "Ghana Health Service", ticket: "Standard", status: "confirmed", seat: "C-115", paid: 850, segs: [] },
  { tag: "AHIS26-9XR4", name: "Abena Owusu", org: "UGMC", ticket: "Faculty", status: "checked_in", seat: "A-012", paid: 0, segs: ["speaker", "vegetarian"] },
  { tag: "AHIS26-4TL8", name: "Ibrahim Sulemana", org: "Tamale Teaching Hospital", ticket: "Standard", status: "awaiting_payment", seat: "—", paid: 0, segs: [] },
  { tag: "AHIS26-6PN1", name: "Grace Amponsah", org: "Nyaho Medical Centre", ticket: "Workshop pass", status: "confirmed", seat: "B-041", paid: 1200, segs: ["day2-workshop"] },
  { tag: "AHIS26-7VD3", name: "Selorm Attoh", org: "Purpledot", ticket: "Faculty", status: "checked_in", seat: "A-004", paid: 0, segs: ["speaker"] },
  { tag: "AHIS26-1HB9", name: "Nana Kwabena Osei", org: "Ministry of Health", ticket: "Standard", status: "waitlisted", seat: "—", paid: 0, segs: [] },
  { tag: "AHIS26-5ZC6", name: "Comfort Adjei", org: "Korle Bu", ticket: "Standard", status: "confirmed", seat: "C-118", paid: 850, segs: ["vegetarian", "accessibility"] },
];

const SEGMENTS = [
  { key: "all", label: "Everyone", count: 587 },
  { key: "vegetarian", label: "Vegetarian meals", count: 74 },
  { key: "speaker", label: "Faculty", count: 22 },
  { key: "awaiting_payment", label: "Unpaid", count: 46 },
  { key: "day2-workshop", label: "Day 2 workshop", count: 60 },
  { key: "accessibility", label: "Access needs", count: 9 },
];

const SCANS = [
  { tag: "AHIS26-9XR4", name: "Abena Owusu", gate: "Main entrance", t: "09:14:22", ok: true },
  { tag: "AHIS26-7VD3", name: "Selorm Attoh", gate: "Faculty desk", t: "09:13:58", ok: true },
  { tag: "AHIS26-3JW2", name: "Michael Tetteh", gate: "Main entrance", t: "09:13:41", ok: true },
  { tag: "AHIS26-4TL8", name: "Ibrahim Sulemana", gate: "Main entrance", t: "09:13:07", ok: false },
  { tag: "AHIS26-2M7Q", name: "Kofi Danso", gate: "Main entrance", t: "09:12:50", ok: true },
  { tag: "AHIS26-8F3K", name: "Yaa Serwaa Mensah", gate: "Volta Room", t: "09:12:11", ok: true },
];

const POLL_RESPONSES = [
  "A referral pathway that survives a power cut",
  "Honest numbers on what the national record actually costs",
  "Someone to tell me how they handled consent at scale",
  "Fewer pilots, more production systems",
  "Practical FHIR profiles I can copy on Monday",
  "How district hospitals share data without fibre",
  "A procurement template that is not vendor-written",
  "Evidence that clinical AI helps outside teaching hospitals",
];

const ARRIVALS = [4, 9, 18, 31, 52, 78, 96, 88, 61, 40, 27, 19, 22, 34, 29, 16, 11, 7];

const COVER_BARS = [
  22, 41, 68, 35, 88, 54, 30, 76, 92, 47, 25, 63, 81, 39, 58, 96, 44, 27, 70, 85,
  33, 61, 48, 90, 36, 74, 52, 29, 66, 83, 41, 57, 94, 38, 71, 46, 26, 62, 79, 50,
];

const NAV = [
  { key: "overview", label: "Overview", icon: LayoutGrid },
  { key: "programme", label: "Programme", icon: CalendarRange },
  { key: "speakers", label: "Speakers", icon: Mic },
  { key: "tickets", label: "Tickets", icon: Ticket },
  { key: "registrations", label: "Registrations", icon: Users },
  { key: "guests", label: "Guest list", icon: MailPlus },
  { key: "checkin", label: "Check-in", icon: ScanLine },
  { key: "engagement", label: "Live", icon: Radio },
  { key: "badges", label: "Badges", icon: CreditCard },
  { key: "finance", label: "Money", icon: Wallet },
];

/* ================================================================== */
/*  Helpers                                                            */
/* ================================================================== */

const money = (n) => `GHS ${n.toLocaleString("en-GH")}`;

const clock = (mins) => {
  const h = Math.floor(mins / 60) + 8;
  const m = mins % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
};

const initials = (name) =>
  name
    .replace(/^(Dr|Prof\.?|Hon\.)\s+/i, "")
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0])
    .join("");

/* ================================================================== */
/*  Primitives                                                         */
/* ================================================================== */

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

function Field({ label, hint, children, wide }) {
  return (
    <div className={wide ? "field wide" : "field"}>
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

function Avatar({ name, size = 40 }) {
  return (
    <span className="avatar" style={{ width: size, height: size, fontSize: size * 0.34 }}>
      {initials(name)}
    </span>
  );
}

function CoverArt({ height = 300 }) {
  return (
    <div className="cover" style={{ height }} aria-hidden="true">
      <div className="cover-bars">
        {COVER_BARS.map((h, i) => (
          <span key={i} style={{ height: `${h}%` }} />
        ))}
      </div>
    </div>
  );
}

/* ================================================================== */
/*  Public event page                                                  */
/* ================================================================== */

function PublicPage({ onEnterConsole, theme, onTheme }) {
  const [tab, setTab] = useState("about");
  const [day, setDay] = useState(1);
  const [ticket, setTicket] = useState(1);

  const TABS = [
    ["about", "About"],
    ["programme", "Programme"],
    ["speakers", "Speakers"],
    ["tickets", "Tickets"],
    ["venue", "Venue"],
  ];

  return (
    <div className={`public theme-${theme}`}>
      <header className="pub-bar">
        <span className="wordmark">MiConvener</span>
        <div className="pub-bar-right">
          <ThemeToggle theme={theme} onTheme={onTheme} />
          <button className="linkish" onClick={onEnterConsole}>
            Organiser console
          </button>
        </div>
      </header>

      <section className="pub-hero">
        <CoverArt height={280} />
        <div className="pub-hero-body">
          <div className="pub-vis">
            {EVENT.visibility === "public" ? (
              <>
                <Globe size={12} strokeWidth={1.75} /> Open to anyone
              </>
            ) : (
              <>
                <Lock size={12} strokeWidth={1.75} /> Private, link only
              </>
            )}
          </div>
          <h1 className="pub-title">
            Accra Health
            <br />
            Informatics Summit
          </h1>
          <p className="pub-host">Hosted by Ghana Health Service and Purpledot</p>
          <div className="pub-facts">
            <div>
              <span>When</span>
              <b>{EVENT.dates}</b>
            </div>
            <div>
              <span>Where</span>
              <b>{EVENT.venue}</b>
            </div>
            <div>
              <span>Seats left</span>
              <b className="mono">{EVENT.capacity - EVENT.registered}</b>
            </div>
            <div>
              <span>From</span>
              <b className="mono">GHS 850</b>
            </div>
          </div>
        </div>
      </section>

      <nav className="pub-tabs">
        {TABS.map(([k, l]) => (
          <button key={k} className={tab === k ? "pubtab on" : "pubtab"} onClick={() => setTab(k)}>
            {l}
          </button>
        ))}
      </nav>

      <section className="pub-body">
        <div className="pub-col-main">
          {tab === "about" && (
            <>
              <p className="lede">
                Three days for the people who build, buy and run health systems in Ghana. Fewer
                slides about the future, more working sessions on what runs in a district hospital
                tonight.
              </p>
              <div className="pub-block">
                <h2>What you should expect</h2>
                <p className="prose">
                  Two plenary days bracket a full day of hands-on labs. Every workshop is capped at
                  sixty people so you get a machine, a dataset and someone who has done it before
                  standing behind you. Lunch and the exhibition floor are included in every ticket.
                </p>
                <p className="prose">
                  Sessions are recorded and released to attendees a week afterwards. The two clinic
                  tracks are not recorded, by request of the hospitals presenting their data.
                </p>
              </div>
              <div className="pub-block">
                <h2>Who comes</h2>
                <div className="whorow">
                  {[
                    ["Hospital IT and informatics leads", 214],
                    ["Clinicians with a systems remit", 138],
                    ["Engineers and vendors", 121],
                    ["Government and regulators", 64],
                    ["Researchers and students", 50],
                  ].map(([l, n]) => (
                    <div key={l} className="who">
                      <b className="mono">{n}</b>
                      <span>{l}</span>
                    </div>
                  ))}
                </div>
              </div>
            </>
          )}

          {tab === "programme" && (
            <div className="pub-block first">
              <div className="daybar">
                {DAYS.map((d) => (
                  <button
                    key={d.id}
                    className={d.id === day ? "daytab on" : "daytab"}
                    onClick={() => setDay(d.id)}
                  >
                    <span>{d.label}</span>
                    <b>{d.date}</b>
                  </button>
                ))}
              </div>
              <p className="daytheme">{DAYS.find((d) => d.id === day).theme}</p>
              <ul className="agenda">
                {SESSIONS[day]
                  .slice()
                  .sort((a, b) => a.start - b.start)
                  .map((s) => (
                    <li key={s.id} className={s.type === "break" ? "agenda-row muted" : "agenda-row"}>
                      <span className="mono time">{clock(s.start)}</span>
                      <div className="agenda-main">
                        <b>{s.title}</b>
                        {s.speaker && <span className="agenda-who">{s.speaker}</span>}
                      </div>
                      <span className="agenda-room">{TRACKS.find((t) => t.id === s.track).room}</span>
                    </li>
                  ))}
              </ul>
            </div>
          )}

          {tab === "speakers" && (
            <div className="pub-block first">
              <div className="pubspeakers">
                {SPEAKERS.filter((s) => s.status === "confirmed").map((s) => (
                  <article key={s.id} className="pubspk">
                    <Avatar name={s.name} size={64} />
                    <div>
                      <h3>{s.name}</h3>
                      <p className="pubspk-role">
                        {s.title}, {s.org}
                      </p>
                      <p className="prose">{s.bio}</p>
                      <ul className="pubspk-sess">
                        {s.sessions.map((x) => (
                          <li key={x}>{x}</li>
                        ))}
                      </ul>
                    </div>
                  </article>
                ))}
              </div>
            </div>
          )}

          {tab === "tickets" && (
            <div className="pub-block first">
              <ul className="pubtickets">
                {TICKET_TYPES.filter((t) => t.visibility === "public").map((t) => {
                  const full = t.sold >= t.quota;
                  return (
                    <li key={t.id} className={full ? "pubticket sold" : "pubticket"}>
                      <div>
                        <b>{t.name}</b>
                        <span>{t.blurb}</span>
                        {t.approval && <Tag tone="warn">Reviewed before confirmation</Tag>}
                      </div>
                      <div className="pubticket-right">
                        <em className="mono">{full ? "Full" : money(t.price)}</em>
                        {!full && <span className="mono dim">{t.quota - t.sold} left</span>}
                      </div>
                    </li>
                  );
                })}
              </ul>
              <p className="prose">
                Tickets are transferable up to 48 hours before the summit opens. Refunds in full
                until 8 September, half thereafter.
              </p>
            </div>
          )}

          {tab === "venue" && (
            <div className="pub-block first">
              <h2>Kempinski Gold Coast City</h2>
              <p className="prose">
                Gamel Abdul Nasser Avenue, Ridge, Accra. Parking is free for attendees; show your
                entry code at the boom. The Grand Ballroom is on the ground floor, the Volta and
                Ashanti rooms one level up, both lift accessible.
              </p>
              <div className="venuegrid">
                {[
                  ["Grand Ballroom", "Plenaries, 420 seats, numbered"],
                  ["Volta Room", "Workshops, 60 seats at benches"],
                  ["Ashanti Room", "Clinic track, 90 seats"],
                  ["Terrace", "Exhibition and lunch"],
                ].map(([n, d]) => (
                  <div key={n} className="venuecell">
                    <b>{n}</b>
                    <span>{d}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <aside className="pub-col-side">
          <div className="ticketbox">
            <h3>Register</h3>
            <div className="tickets">
              {TICKET_TYPES.filter((t) => t.visibility === "public").map((t) => {
                const full = t.sold >= t.quota;
                return (
                  <button
                    key={t.id}
                    className={`ticket${ticket === t.id && !full ? " on" : ""}${full ? " sold" : ""}`}
                    onClick={() => !full && setTicket(t.id)}
                    disabled={full}
                  >
                    <div>
                      <b>{t.name}</b>
                      <span>{t.blurb}</span>
                    </div>
                    <em className="mono">{full ? "Full" : t.price.toLocaleString()}</em>
                  </button>
                );
              })}
            </div>
            <Button kind="solid">Continue to payment</Button>
            <p className="paynote">
              Card, MTN MoMo, Telecel Cash and AirtelTigo Money accepted. Your entry code arrives by
              email the moment payment clears.
            </p>
          </div>
        </aside>
      </section>
    </div>
  );
}

/* ================================================================== */
/*  Console chrome                                                     */
/* ================================================================== */

function ThemeToggle({ theme, onTheme }) {
  return (
    <button
      className="themetoggle"
      onClick={() => onTheme(theme === "dark" ? "light" : "dark")}
      aria-label={theme === "dark" ? "Switch to light" : "Switch to dark"}
      title={theme === "dark" ? "Switch to light" : "Switch to dark"}
    >
      {theme === "dark" ? <Sun size={14} strokeWidth={1.6} /> : <Moon size={14} strokeWidth={1.6} />}
    </button>
  );
}

function TopStrip({ now, theme, onTheme }) {
  return (
    <div className="strip">
      <div className="strip-left">
        <span className="pulse" />
        <b>Day 1 running</b>
        <span className="mono strip-clock">{now}</span>
      </div>
      <div className="strip-mid">
        <span className="strip-label">On now</span>
        <b>What the Ministry actually funds</b>
        <span className="strip-room">Grand Ballroom</span>
      </div>
      <div className="strip-right">
        <span>
          <em className="mono">{EVENT.checkedIn}</em> in venue
        </span>
        <span>
          <em className="mono">3</em> requests open
        </span>
        <ThemeToggle theme={theme} onTheme={onTheme} />
      </div>
    </div>
  );
}

/* ================================================================== */
/*  Overview                                                           */
/* ================================================================== */

function Overview() {
  const max = Math.max(...ARRIVALS);
  return (
    <>
      <SectionHead title="Overview" note="Everything happening at the summit right now.">
        <Button icon={Download}>Export day report</Button>
      </SectionHead>

      <div className="stats">
        <Stat label="Registered" value={EVENT.registered} sub={`of ${EVENT.capacity} capacity`} />
        <Stat label="Confirmed" value={EVENT.confirmed} sub="46 awaiting payment" />
        <Stat label="Checked in today" value={EVENT.checkedIn} accent sub="72% of confirmed" />
        <Stat label="Collected" value={money(EVENT.gross)} sub={`${money(EVENT.net)} settles to you`} />
      </div>

      <div className="split">
        <div className="panel">
          <div className="panel-head">
            <b>Arrivals through the gates</b>
            <span className="panel-note">08:00 to 11:00, five-minute buckets</span>
          </div>
          <div className="bars">
            {ARRIVALS.map((v, i) => (
              <div key={i} className="bar-wrap" title={`${v} arrivals`}>
                <div className="bar" style={{ height: `${(v / max) * 100}%` }} />
              </div>
            ))}
          </div>
          <div className="bars-axis mono">
            <span>08:00</span>
            <span>09:30</span>
            <span>11:00</span>
          </div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <b>Needs a person</b>
            <span className="panel-note">Sorted by how long it has waited</span>
          </div>
          <ul className="queue">
            <li>
              <span className="mono qtime">6m</span>
              <div>
                <b>Water at table 14</b>
                <span>Grand Ballroom, raised by Comfort Adjei</span>
              </div>
              <Tag tone="warn">Open</Tag>
            </li>
            <li>
              <span className="mono qtime">4m</span>
              <div>
                <b>Projector not showing laptop</b>
                <span>Volta Room, raised by Kwame Asare</span>
              </div>
              <Tag tone="alert">Technical</Tag>
            </li>
            <li>
              <span className="mono qtime">2m</span>
              <div>
                <b>Wheelchair access to stage</b>
                <span>Grand Ballroom, raised by front desk</span>
              </div>
              <Tag tone="warn">Open</Tag>
            </li>
            <li className="done">
              <span className="mono qtime">—</span>
              <div>
                <b>Extra chairs, back row</b>
                <span>Resolved by Mensah A. at 09:02</span>
              </div>
              <Tag>Closed</Tag>
            </li>
          </ul>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>Questions waiting on an answer</b>
          <span className="panel-note">From the summit forum, oldest first</span>
        </div>
        <ul className="qa">
          <li>
            <div>
              <b>Will the referral demo be available to try after the session?</b>
              <span>Asked by Grace Amponsah, 24 minutes ago, in Day 1 plenary</span>
            </div>
            <Button>Answer</Button>
          </li>
          <li>
            <div>
              <b>Is there a vegetarian option at the gala dinner?</b>
              <span>Asked anonymously, 41 minutes ago, in General</span>
            </div>
            <Button>Answer</Button>
          </li>
        </ul>
        <div className="ai-strip">
          <b>Catch up on today</b>
          <span>
            Forty-one posts since 08:00. Most of it is about the referral demo and whether Ministry
            funding covers district rollout.
          </span>
          <Button>Summarise today</Button>
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Programme                                                          */
/* ================================================================== */

function Programme() {
  const [day, setDay] = useState(1);
  const PX = 1.35;
  const DAY_MINUTES = 390;
  const rows = useMemo(() => SESSIONS[day], [day]);
  const clashes = rows.filter((r) => r.clash).length;

  return (
    <>
      <SectionHead
        title="Programme"
        note={`${DAYS.find((d) => d.id === day).date} — ${DAYS.find((d) => d.id === day).theme}`}
      >
        <Button icon={Plus}>Add session</Button>
        <Button icon={Download}>Export programme</Button>
      </SectionHead>

      <div className="prog-bar">
        <div className="daybar">
          {DAYS.map((d) => (
            <button
              key={d.id}
              className={d.id === day ? "daytab on" : "daytab"}
              onClick={() => setDay(d.id)}
            >
              <span>{d.label}</span>
              <b>{d.date}</b>
            </button>
          ))}
        </div>
        {clashes > 0 && (
          <div className="clash-flag">
            <AlertTriangle size={13} strokeWidth={1.75} />
            Kwame Asare is in two rooms at 11:15. Move one session or reassign the speaker.
          </div>
        )}
      </div>

      <div className="grid-wrap">
        <div className="grid-head">
          <div className="grid-gutter" />
          {TRACKS.map((t) => (
            <div key={t.id} className="grid-col-head">
              <b>{t.name}</b>
              <span>{t.room}</span>
            </div>
          ))}
        </div>

        <div className="grid-body" style={{ height: DAY_MINUTES * PX }}>
          <div className="grid-gutter">
            {Array.from({ length: DAY_MINUTES / 30 + 1 }).map((_, i) => (
              <div key={i} className="tick" style={{ top: i * 30 * PX }}>
                <span className="mono">{clock(i * 30)}</span>
              </div>
            ))}
          </div>

          {TRACKS.map((t) => (
            <div key={t.id} className="grid-col">
              {Array.from({ length: DAY_MINUTES / 30 + 1 }).map((_, i) => (
                <div key={i} className="rule" style={{ top: i * 30 * PX }} />
              ))}
              {rows
                .filter((s) => s.track === t.id)
                .map((s) => {
                  const full = s.seats && s.taken >= s.seats;
                  return (
                    <div
                      key={s.id}
                      className={`block block-${s.type}${s.clash ? " block-clash" : ""}`}
                      style={{ top: s.start * PX, height: (s.end - s.start) * PX - 2 }}
                    >
                      <div className="block-time mono">
                        {clock(s.start)}–{clock(s.end)}
                      </div>
                      <div className="block-title">{s.title}</div>
                      {s.speaker && <div className="block-who">{s.speaker}</div>}
                      {s.seats && (
                        <div className={full ? "block-fill full" : "block-fill"}>
                          <span className="mono">
                            {s.taken}/{s.seats}
                          </span>
                          <div className="fillbar">
                            <div style={{ width: `${(s.taken / s.seats) * 100}%` }} />
                          </div>
                        </div>
                      )}
                    </div>
                  );
                })}
            </div>
          ))}

          {day === 1 && (
            <div className="nowline" style={{ top: 115 * PX }}>
              <span className="mono">09:55</span>
            </div>
          )}
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Speakers                                                           */
/* ================================================================== */

function SpeakerForm({ onClose }) {
  const [bio, setBio] = useState("");
  const [noConflict, setNoConflict] = useState(true);
  const [status, setStatus] = useState("invited");
  const [picked, setPicked] = useState(["Interoperability lab"]);
  const allSessions = Object.values(SESSIONS)
    .flat()
    .filter((s) => s.type !== "break")
    .map((s) => s.title);

  const toggle = (t) =>
    setPicked((p) => (p.includes(t) ? p.filter((x) => x !== t) : [...p, t]));

  return (
    <div className="panel form">
      <div className="panel-head">
        <b>Add a speaker</b>
        <button className="iconbtn" onClick={onClose} aria-label="Close">
          <X size={14} strokeWidth={1.75} />
        </button>
      </div>

      <div className="formgrid">
        <div className="formcol">
          <div className="photodrop">
            <div className="photoslot">
              <Image size={20} strokeWidth={1.25} />
            </div>
            <div>
              <b>Headshot</b>
              <p className="hint">
                Square, at least 800 × 800. Shown on the public page and on their badge.
              </p>
              <Button icon={Upload}>Choose file</Button>
            </div>
          </div>

          <Field label="Full name">
            <input placeholder="Dr Ama Boateng" />
          </Field>
          <div className="tworow">
            <Field label="Job title">
              <input placeholder="Director, Health Informatics" />
            </Field>
            <Field label="Organisation">
              <input placeholder="Ghana Health Service" />
            </Field>
          </div>
          <div className="tworow">
            <Field label="Email" hint="Where the invitation and slide reminders go.">
              <input placeholder="name@example.com" />
            </Field>
            <Field label="Country">
              <select defaultValue="Ghana">
                <option>Ghana</option>
                <option>Nigeria</option>
                <option>Kenya</option>
                <option>South Africa</option>
                <option>United Kingdom</option>
              </select>
            </Field>
          </div>
          <Field label="Links" hint="One per line. Website, LinkedIn, anything public.">
            <textarea rows={2} placeholder="ghs.gov.gh/informatics" />
          </Field>
        </div>

        <div className="formcol">
          <Field
            label="Bio"
            hint={`${bio.length} of 600 characters. Written in the third person, printed as given.`}
          >
            <textarea
              rows={7}
              maxLength={600}
              value={bio}
              onChange={(e) => setBio(e.target.value)}
              placeholder="Two or three sentences on what they do and why the room should listen."
            />
          </Field>

          <Field label="Sessions" hint="They will appear on the public programme against each one.">
            <div className="picker">
              {allSessions.map((t) => (
                <button
                  key={t}
                  type="button"
                  className={picked.includes(t) ? "chip on" : "chip"}
                  onClick={() => toggle(t)}
                >
                  {t}
                </button>
              ))}
            </div>
          </Field>

          <Field label="Status">
            <div className="segmented">
              {[
                ["invited", "Invited"],
                ["confirmed", "Confirmed"],
                ["declined", "Declined"],
              ].map(([k, l]) => (
                <button
                  key={k}
                  type="button"
                  className={status === k ? "seg on" : "seg"}
                  onClick={() => setStatus(k)}
                >
                  {l}
                </button>
              ))}
            </div>
          </Field>

          <Toggle
            on={noConflict}
            onChange={setNoConflict}
            label="No conflicts of interest to declare"
            hint="Turn this off to record a disclosure statement, needed for accredited sessions."
          />
        </div>
      </div>

      <div className="formfoot">
        <span className="hint">
          Saving sends nothing. Use Send invitation when you are ready for them to confirm.
        </span>
        <div className="sec-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button>Save and send invitation</Button>
          <Button kind="solid">Save speaker</Button>
        </div>
      </div>
    </div>
  );
}

function Speakers() {
  const [adding, setAdding] = useState(false);
  const missing = SPEAKERS.filter((s) => !s.slides).length;

  return (
    <>
      <SectionHead title="Speakers" note="Your faculty, their bios and what they still owe you.">
        <Button icon={Download}>Export faculty</Button>
        <Button kind="solid" icon={Plus} onClick={() => setAdding(true)}>
          Add speaker
        </Button>
      </SectionHead>

      {adding && <SpeakerForm onClose={() => setAdding(false)} />}

      <div className="stats">
        <Stat label="On the programme" value={SPEAKERS.length} />
        <Stat label="Confirmed" value={SPEAKERS.filter((s) => s.status === "confirmed").length} accent />
        <Stat label="Slides outstanding" value={missing} sub="Deadline was Friday" />
        <Stat label="Disclosures signed" value={`${SPEAKERS.filter((s) => s.disclosure).length} of ${SPEAKERS.length}`} />
      </div>

      <div className="spkgrid">
        {SPEAKERS.map((s) => (
          <article key={s.id} className="spkcard">
            <header>
              <Avatar name={s.name} size={44} />
              <div className="spkid">
                <b>{s.name}</b>
                <span>
                  {s.title}, {s.org}
                </span>
              </div>
              <Tag tone={s.status === "confirmed" ? "good" : "warn"}>
                {s.status === "confirmed" ? "Confirmed" : "Invited"}
              </Tag>
            </header>
            <p className="spkbio">{s.bio}</p>
            <ul className="spksess">
              {s.sessions.map((x) => (
                <li key={x}>{x}</li>
            ))}
            </ul>
            <footer>
              <span className={s.slides ? "ok" : "pending"}>
                {s.slides ? <Check size={12} strokeWidth={2} /> : <Clock size={12} strokeWidth={1.75} />}
                {s.slides ? "Slides in" : "No slides yet"}
              </span>
              {s.links.length > 0 && (
                <span className="spklink">
                  <Link2 size={12} strokeWidth={1.6} />
                  {s.links[0]}
                </span>
              )}
              <div className="spkacts">
                <Button>Edit</Button>
                {!s.slides && <Button>Chase slides</Button>}
              </div>
            </footer>
          </article>
        ))}
      </div>
    </>
  );
}

/* ================================================================== */
/*  Tickets                                                            */
/* ================================================================== */

function TicketForm({ onClose }) {
  const [free, setFree] = useState(false);
  const [approval, setApproval] = useState(false);
  const [vis, setVis] = useState("public");

  return (
    <div className="panel form">
      <div className="panel-head">
        <b>Create a ticket</b>
        <button className="iconbtn" onClick={onClose} aria-label="Close">
          <X size={14} strokeWidth={1.75} />
        </button>
      </div>

      <div className="formgrid">
        <div className="formcol">
          <Field label="Ticket name" hint="What the buyer sees on the event page.">
            <input placeholder="Workshop pass" />
          </Field>
          <Field label="What it includes">
            <textarea rows={3} placeholder="Everything in Standard plus both hands-on labs." />
          </Field>
          <div className="tworow">
            <Field label="Price">
              <div className="inputgroup">
                <span className="prefix">GHS</span>
                <input className="mono" placeholder="1,200" disabled={free} />
              </div>
            </Field>
            <Field label="How many">
              <input className="mono" placeholder="120" />
            </Field>
          </div>
          <Toggle on={free} onChange={setFree} label="Free ticket" hint="No payment step at checkout." />
          <div className="tworow">
            <Field label="Sales open">
              <input type="date" defaultValue="2026-06-12" />
            </Field>
            <Field label="Sales close">
              <input type="date" defaultValue="2026-09-18" />
            </Field>
          </div>
        </div>

        <div className="formcol">
          <Field label="Who can see it">
            <div className="segmented">
              {[
                ["public", "Everyone"],
                ["hidden", "Hidden"],
                ["invite", "Invite only"],
              ].map(([k, l]) => (
                <button key={k} type="button" className={vis === k ? "seg on" : "seg"} onClick={() => setVis(k)}>
                  {l}
                </button>
              ))}
            </div>
          </Field>
          {vis === "invite" && (
            <Field label="Access code" hint="Buyers enter this to unlock the ticket.">
              <input className="mono" defaultValue="FACULTY26" />
            </Field>
          )}

          <div className="tworow">
            <Field label="Minimum per order">
              <input className="mono" defaultValue="1" />
            </Field>
            <Field label="Maximum per order">
              <input className="mono" defaultValue="5" />
            </Field>
          </div>

          <Toggle
            on={approval}
            onChange={setApproval}
            label="Review each buyer before confirming"
            hint="Useful for student rates and anything needing proof."
          />
          <Toggle on={true} onChange={() => {}} label="Buyer can transfer this ticket" hint="Up to 48 hours before doors open." />

          <Field label="Who pays the processing fee">
            <select defaultValue="buyer">
              <option value="buyer">Add it to the buyer's total</option>
              <option value="organiser">Take it out of my settlement</option>
            </select>
          </Field>

          <Field label="Sessions this ticket opens">
            <div className="picker">
              {["All plenaries", "Day 2 workshops", "Clinic track", "Gala dinner"].map((x, i) => (
                <button key={x} type="button" className={i < 2 ? "chip on" : "chip"}>
                  {x}
                </button>
              ))}
            </div>
          </Field>
        </div>
      </div>

      <div className="formfoot">
        <span className="hint">Nothing goes on sale until you publish the ticket.</span>
        <div className="sec-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button>Save as draft</Button>
          <Button kind="solid">Publish ticket</Button>
        </div>
      </div>
    </div>
  );
}

function Tickets() {
  const [adding, setAdding] = useState(false);
  const revenue = TICKET_TYPES.reduce((a, t) => a + t.price * t.sold, 0);

  return (
    <>
      <SectionHead title="Tickets" note="What is on sale, what it earns and what is left.">
        <Button icon={Download}>Export sales</Button>
        <Button kind="solid" icon={Plus} onClick={() => setAdding(true)}>
          Create ticket
        </Button>
      </SectionHead>

      {adding && <TicketForm onClose={() => setAdding(false)} />}

      <div className="stats">
        <Stat label="Ticket types" value={TICKET_TYPES.length} />
        <Stat label="Sold" value={TICKET_TYPES.reduce((a, t) => a + t.sold, 0)} accent />
        <Stat label="Left to sell" value={TICKET_TYPES.reduce((a, t) => a + (t.quota - t.sold), 0)} />
        <Stat label="Ticket revenue" value={money(revenue)} sub="Before fees" />
      </div>

      <div className="ticketlist">
        {TICKET_TYPES.map((t) => {
          const pct = Math.round((t.sold / t.quota) * 100);
          const full = t.sold >= t.quota;
          return (
            <div key={t.id} className="ticketrow">
              <div className="tr-main">
                <div className="tr-name">
                  <b>{t.name}</b>
                  {t.visibility === "hidden" && <Tag>Hidden</Tag>}
                  {t.visibility === "invite" && <Tag>Invite only</Tag>}
                  {t.approval && <Tag tone="warn">Reviewed</Tag>}
                  {full && <Tag tone="accent">Sold out</Tag>}
                </div>
                <p>{t.blurb}</p>
                <div className="tr-fill">
                  <div className="fillbar wide">
                    <div style={{ width: `${pct}%` }} />
                  </div>
                  <span className="mono dim">
                    {t.sold} of {t.quota}
                  </span>
                </div>
              </div>
              <div className="tr-price mono">{t.price === 0 ? "Free" : money(t.price)}</div>
              <div className="tr-dates">
                <span className="mono">{t.opens}</span>
                <span className="dim">to</span>
                <span className="mono">{t.closes}</span>
              </div>
              <div className="tr-acts">
                <Button>Edit</Button>
                <button className="iconbtn" aria-label="Delete ticket">
                  <Trash2 size={13} strokeWidth={1.6} />
                </button>
              </div>
            </div>
          );
        })}
      </div>
    </>
  );
}

/* ================================================================== */
/*  Guest list                                                         */
/* ================================================================== */

function Guests() {
  const [group, setGroup] = useState("all");
  const groups = ["all", "Faculty", "Press", "Partner", "Sponsor", "Government"];
  const rows = GUESTS.filter((g) => group === "all" || g.group === group);

  const state = {
    accepted: ["Coming", "good"],
    opened: ["Opened, no reply", "warn"],
    declined: ["Can't make it", "neutral"],
    no_reply: ["Not opened", "neutral"],
  };

  return (
    <>
      <SectionHead
        title="Guest list"
        note="People you invited directly. They skip the queue and do not pay."
      >
        <Button icon={Upload}>Import a list</Button>
        <Button kind="solid" icon={MailPlus}>
          Invite guests
        </Button>
      </SectionHead>

      <div className="stats">
        <Stat label="Invited" value={GUESTS.length} />
        <Stat label="Coming" value={GUESTS.filter((g) => g.state === "accepted").length} accent />
        <Stat label="Opened, no reply" value={GUESTS.filter((g) => g.state === "opened").length} sub="Worth a nudge" />
        <Stat label="Seats held" value={GUESTS.reduce((a, g) => a + 1 + g.plus, 0)} sub="Including guests of guests" />
      </div>

      <div className="filters">
        {groups.map((g) => (
          <button key={g} className={group === g ? "chip on" : "chip"} onClick={() => setGroup(g)}>
            {g === "all" ? "Every guest" : g}
            <em className="mono">{g === "all" ? GUESTS.length : GUESTS.filter((x) => x.group === g).length}</em>
          </button>
        ))}
      </div>

      <div className="panel flush">
        <table className="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Group</th>
              <th>Can bring</th>
              <th>Invited</th>
              <th>Reply</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((g) => (
              <tr key={g.email}>
                <td>
                  <div className="cellwho">
                    <Avatar name={g.name} size={26} />
                    <b>{g.name}</b>
                  </div>
                </td>
                <td className="dim">{g.email}</td>
                <td className="dim">{g.group}</td>
                <td className="mono">{g.plus === 0 ? "—" : `+${g.plus}`}</td>
                <td className="mono dim">{g.sent}</td>
                <td>
                  <Tag tone={state[g.state][1]}>{state[g.state][0]}</Tag>
                </td>
                <td className="rowact">
                  {g.state === "accepted" ? <Button>Send entry code</Button> : <Button>Remind</Button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>What the invitation says</b>
          <span className="panel-note">Sent from summit@ghs.gov.gh</span>
        </div>
        <div className="mailpreview">
          <p className="mail-sub">You are invited to the Accra Health Informatics Summit</p>
          <p className="prose">
            Kwame, we would like you at the summit on 22 to 24 September at the Kempinski in Accra.
            Your place is held and there is nothing to pay. Let us know by 5 September so we can
            print your badge.
          </p>
          <div className="mailacts">
            <span className="fakebtn">Yes, I'll be there</span>
            <span className="fakebtn ghost">Sorry, I can't</span>
          </div>
        </div>
        <div className="sec-actions" style={{ marginTop: 14 }}>
          <Button>Edit wording</Button>
          <Button>Send a test to myself</Button>
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Registrations                                                      */
/* ================================================================== */

function Registrations() {
  const [seg, setSeg] = useState("all");
  const [q, setQ] = useState("");

  const rows = REGISTRATIONS.filter((r) => {
    const inSeg =
      seg === "all" ||
      r.segs.includes(seg) ||
      (seg === "awaiting_payment" && r.status === "awaiting_payment");
    const inQ =
      !q ||
      r.name.toLowerCase().includes(q.toLowerCase()) ||
      r.tag.toLowerCase().includes(q.toLowerCase());
    return inSeg && inQ;
  });

  const label = {
    confirmed: ["Confirmed", "good"],
    checked_in: ["In venue", "accent"],
    awaiting_payment: ["Unpaid", "warn"],
    waitlisted: ["Waitlist", "neutral"],
  };

  return (
    <>
      <SectionHead title="Registrations" note="Filter to a group, then message or export just that group.">
        <Button icon={Download}>Export to Excel</Button>
        <Button kind="solid">Message this group</Button>
      </SectionHead>

      <div className="filters">
        {SEGMENTS.map((s) => (
          <button key={s.key} className={seg === s.key ? "chip on" : "chip"} onClick={() => setSeg(s.key)}>
            {s.label}
            <em className="mono">{s.count}</em>
          </button>
        ))}
      </div>

      <div className="searchrow">
        <Search size={14} strokeWidth={1.75} />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search by name or entry code" />
        <span className="dim mono">{rows.length} shown</span>
      </div>

      <div className="panel flush">
        <table className="table">
          <thead>
            <tr>
              <th>Entry code</th>
              <th>Name</th>
              <th>Organisation</th>
              <th>Ticket</th>
              <th>Seat</th>
              <th>Paid</th>
              <th>Status</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.tag}>
                <td className="mono accent-text">{r.tag}</td>
                <td>
                  <b>{r.name}</b>
                  {r.segs.length > 0 && (
                    <span className="rowsegs">
                      {r.segs.map((s) => (
                        <Tag key={s}>{SEGMENTS.find((x) => x.key === s)?.label || s}</Tag>
                      ))}
                    </span>
                  )}
                </td>
                <td className="dim">{r.org}</td>
                <td className="dim">{r.ticket}</td>
                <td className="mono">{r.seat}</td>
                <td className="mono">{r.paid ? money(r.paid) : "—"}</td>
                <td>
                  <Tag tone={label[r.status][1]}>{label[r.status][0]}</Tag>
                </td>
                <td className="rowact">
                  {r.status === "awaiting_payment" ? (
                    <Button>Send pay link</Button>
                  ) : r.status === "waitlisted" ? (
                    <Button>Offer place</Button>
                  ) : (
                    <Button>Open</Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && (
          <div className="empty">Nobody matches that. Clear the search or pick a different group.</div>
        )}
      </div>
    </>
  );
}

/* ================================================================== */
/*  Check-in                                                           */
/* ================================================================== */

function CheckIn() {
  const [code, setCode] = useState("");
  return (
    <>
      <SectionHead title="Check-in" note="Scanning works without a network. Queued scans sync when you reconnect.">
        <Button icon={Download}>Export check-in log</Button>
      </SectionHead>

      <div className="checkin">
        <div className="scanner">
          <div className="scan-frame">
            <div className="scan-corner tl" />
            <div className="scan-corner tr" />
            <div className="scan-corner bl" />
            <div className="scan-corner br" />
            <div className="scan-sweep" />
            <ScanLine size={40} strokeWidth={1} className="scan-icon" />
          </div>
          <p className="scan-note">Point the camera at the entry code on the phone or badge.</p>
          <div className="manual">
            <label>Or type the entry code</label>
            <div className="manual-row">
              <input
                className="mono"
                value={code}
                onChange={(e) => setCode(e.target.value.toUpperCase())}
                placeholder="AHIS26-XXXX"
              />
              <Button kind="solid">Check in</Button>
            </div>
          </div>
        </div>

        <div className="panel flush">
          <div className="panel-head">
            <b>Last scans</b>
            <span className="panel-note">Main entrance, faculty desk and Volta Room</span>
          </div>
          <ul className="scans">
            {SCANS.map((s, i) => (
              <li key={i} className={s.ok ? "" : "bad"}>
                <span className={s.ok ? "dot ok" : "dot no"}>
                  {s.ok ? <Check size={11} strokeWidth={2.5} /> : <X size={11} strokeWidth={2.5} />}
                </span>
                <div>
                  <b>{s.name}</b>
                  <span className="mono dim">{s.tag}</span>
                </div>
                <span className="scan-gate">{s.gate}</span>
                <span className="mono scan-time">{s.t}</span>
              </li>
            ))}
          </ul>
          <div className="scan-fail">
            <AlertTriangle size={13} strokeWidth={1.75} />
            One entry turned away: payment outstanding. Take payment at the desk to let them in.
          </div>
        </div>
      </div>

      <div className="stats">
        <Stat label="In venue now" value={EVENT.checkedIn} accent />
        <Stat label="Peak throughput" value="96 / 5 min" sub="08:40 at the main entrance" />
        <Stat label="Not yet arrived" value={EVENT.confirmed - EVENT.checkedIn} sub="Confirmed but still out" />
        <Stat label="Walk-ins today" value="17" sub={`${money(14_450)} taken at the door`} />
      </div>
    </>
  );
}

/* ================================================================== */
/*  Live                                                               */
/* ================================================================== */

function Engagement() {
  const [shown, setShown] = useState(4);
  useEffect(() => {
    if (shown >= POLL_RESPONSES.length) return;
    const t = setTimeout(() => setShown((s) => s + 1), 2200);
    return () => clearTimeout(t);
  }, [shown]);

  return (
    <>
      <SectionHead title="Live" note="Running in the Grand Ballroom. Responses appear here as they arrive.">
        <Button>Moderation queue</Button>
        <Button kind="solid">Show on screen</Button>
      </SectionHead>

      <div className="split">
        <div className="panel">
          <div className="panel-head">
            <b>Open question</b>
            <span className="panel-note mono">{shown} responses</span>
          </div>
          <div className="ask">What do you most want to leave this summit with?</div>
          <ul className="stream">
            {POLL_RESPONSES.slice(0, shown).map((r, i) => (
              <li key={i}>
                <span className="quote-rule" />
                {r}
              </li>
            ))}
          </ul>
          <div className="stream-foot">
            <Button icon={ChevronLeft}>Previous</Button>
            <span className="dim">Question 2 of 5</span>
            <Button icon={ChevronRight}>Next question</Button>
          </div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <b>Quiz: interoperability basics</b>
            <span className="panel-note mono">214 answered</span>
          </div>
          <div className="ask small">Which resource carries a patient's allergy record in FHIR?</div>
          <ul className="answers">
            {[
              ["AllergyIntolerance", 71, true],
              ["Condition", 18, false],
              ["Observation", 8, false],
              ["Flag", 3, false],
            ].map(([opt, pct, right]) => (
              <li key={opt} className={right ? "right" : ""}>
                <div className="ans-row">
                  <b>{opt}</b>
                  <span className="mono">{pct}%</span>
                </div>
                <div className="ans-bar">
                  <div style={{ width: `${pct}%` }} />
                </div>
              </li>
            ))}
          </ul>
          <div className="stream-foot">
            <span className="dim">Average time to answer 11s</span>
            <Button>Reveal answer</Button>
          </div>
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Badges                                                             */
/* ================================================================== */

function Badges() {
  return (
    <>
      <SectionHead title="Badges" note="Design once, print for everyone, reprint at the desk.">
        <Button icon={Upload}>Upload artwork</Button>
        <Button kind="solid" icon={Download}>
          Print 541 badges
        </Button>
      </SectionHead>

      <div className="badge-wrap">
        <div className="canvas">
          <div className="canvas-rulers mono">
            <span>0</span>
            <span>25</span>
            <span>50</span>
            <span>75</span>
            <span>100 mm</span>
          </div>
          <div className="badge">
            <div className="badge-band" />
            <div className="badge-body">
              <div className="badge-ev">Accra Health Informatics Summit</div>
              <div className="badge-name">Abena Owusu</div>
              <div className="badge-org">University of Ghana Medical Centre</div>
              <div className="badge-foot">
                <div className="qr" aria-hidden="true">
                  {Array.from({ length: 64 }).map((_, i) => (
                    <i key={i} className={(i * 7) % 3 === 0 || i % 5 === 0 ? "on" : ""} />
                  ))}
                </div>
                <div className="badge-meta">
                  <span className="mono">AHIS26-9XR4</span>
                  <span className="mono">Seat A-012</span>
                  <b>Faculty</b>
                </div>
              </div>
            </div>
          </div>
          <p className="canvas-note">
            Faculty badges carry the teal band. Standard badges print the same layout without it.
          </p>
        </div>

        <div className="panel side">
          <div className="panel-head">
            <b>Print setup</b>
          </div>
          <div className="fields">
            <Field label="Card size">
              <div className="field-val mono">100 × 70 mm</div>
            </Field>
            <Field label="Bleed">
              <div className="field-val mono">3 mm</div>
            </Field>
            <Field label="Sheet">
              <div className="field-val">A4, six up, crop marks on</div>
            </Field>
            <Field label="Printer">
              <div className="field-val">Zebra ZD421 at the front desk</div>
            </Field>
          </div>
          <div className="panel-head bordered">
            <b>What goes on the card</b>
          </div>
          <ul className="layers">
            <li>
              Name <span className="dim">24pt</span>
            </li>
            <li>
              Organisation <span className="dim">11pt</span>
            </li>
            <li>
              Entry code <span className="dim">mono 9pt</span>
            </li>
            <li>
              Seat number <span className="dim">mono 9pt</span>
            </li>
            <li>
              Ticket type <span className="dim">10pt</span>
            </li>
            <li>
              Entry QR <span className="dim">18 mm, signed</span>
            </li>
          </ul>
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  Money                                                              */
/* ================================================================== */

function Finance() {
  return (
    <>
      <SectionHead title="Money" note="What you collected, what it cost, and where it goes.">
        <Button icon={Download}>Settlement statement</Button>
      </SectionHead>

      <div className="stats">
        <Stat label="Collected" value={money(EVENT.gross)} sub="587 orders" />
        <Stat label="Fees and refunds" value={`− ${money(41_240)}`} sub="Gateway 6.2%, refunds 4" />
        <Stat label="Settles to you" value={money(EVENT.net)} accent sub="Held until 26 Sep" />
        <Stat label="Paid out so far" value={money(180_000)} sub="Two payouts" />
      </div>

      <div className="split">
        <div className="panel flush">
          <div className="panel-head">
            <b>Where the money goes</b>
            <span className="panel-note">Verified accounts only</span>
          </div>
          <ul className="accounts">
            <li>
              <Smartphone size={16} strokeWidth={1.5} />
              <div>
                <b>MTN MoMo — Purpledot Limited</b>
                <span className="mono dim">024 ••• ••12</span>
              </div>
              <Tag tone="good">Name matched</Tag>
            </li>
            <li>
              <Building2 size={16} strokeWidth={1.5} />
              <div>
                <b>Absa Bank Ghana — current</b>
                <span className="mono dim">•••• 4417</span>
              </div>
              <Tag tone="good">Verified</Tag>
            </li>
            <li className="addrow">
              <Plus size={15} strokeWidth={1.75} />
              <div>
                <b>Add another account</b>
                <span>Changes take 48 hours to take effect.</span>
              </div>
            </li>
          </ul>
          <div className="kyc">
            <Check size={13} strokeWidth={2} />
            Business verification complete. Renew by 14 March 2027.
          </div>
        </div>

        <div className="panel flush">
          <div className="panel-head">
            <b>Payouts</b>
            <span className="panel-note">Weekly, three days after each event day</span>
          </div>
          <table className="table">
            <thead>
              <tr>
                <th>Date</th>
                <th>To</th>
                <th>Amount</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td className="mono">26 Sep</td>
                <td className="dim">Absa •••• 4417</td>
                <td className="mono">{money(191_160)}</td>
                <td>
                  <Tag tone="warn">Scheduled</Tag>
                </td>
              </tr>
              <tr>
                <td className="mono">12 Sep</td>
                <td className="dim">Absa •••• 4417</td>
                <td className="mono">{money(120_000)}</td>
                <td>
                  <Tag tone="good">Paid</Tag>
                </td>
              </tr>
              <tr>
                <td className="mono">29 Aug</td>
                <td className="dim">MTN MoMo ••12</td>
                <td className="mono">{money(60_000)}</td>
                <td>
                  <Tag tone="good">Paid</Tag>
                </td>
              </tr>
            </tbody>
          </table>
          <div className="hold">
            <Clock size={13} strokeWidth={1.75} />
            10% is held until three days after the summit closes, to cover late refunds.
          </div>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <b>Exports</b>
          <span className="panel-note">Large files arrive by email when they finish building</span>
        </div>
        <div className="exports">
          {[
            "Registrations, every field",
            "Payments and refunds",
            "Settlement statement",
            "Session attendance",
            "Catering and access needs",
            "Check-in log",
            "Quiz and poll results",
            "Accounting ledger",
          ].map((x) => (
            <button key={x} className="export">
              <Download size={13} strokeWidth={1.75} />
              {x}
            </button>
          ))}
        </div>
      </div>
    </>
  );
}

/* ================================================================== */
/*  App                                                                */
/* ================================================================== */

export default function MiConvener() {
  const [surface, setSurface] = useState("console");
  const [view, setView] = useState("overview");
  const [theme, setTheme] = useState("dark");
  const [now, setNow] = useState("09:55:04");

  useEffect(() => {
    const t = setInterval(() => {
      setNow(`09:55:${String(new Date().getSeconds()).padStart(2, "0")}`);
    }, 1000);
    return () => clearInterval(t);
  }, []);

  const views = {
    overview: Overview,
    programme: Programme,
    speakers: Speakers,
    tickets: Tickets,
    registrations: Registrations,
    guests: Guests,
    checkin: CheckIn,
    engagement: Engagement,
    badges: Badges,
    finance: Finance,
  };
  const Current = views[view];

  return (
    <>
      <style>{CSS}</style>
      {surface === "public" ? (
        <PublicPage onEnterConsole={() => setSurface("console")} theme={theme} onTheme={setTheme} />
      ) : (
        <div className={`app theme-${theme}`}>
          <nav className="rail">
            <div className="rail-brand">
              <span className="wordmark">MiConvener</span>
              <span className="rail-ev">{EVENT.name}</span>
            </div>
            <ul>
              {NAV.map((n) => {
                const Icon = n.icon;
                return (
                  <li key={n.key}>
                    <button
                      className={view === n.key ? "navbtn on" : "navbtn"}
                      onClick={() => setView(n.key)}
                    >
                      <Icon size={15} strokeWidth={1.6} />
                      {n.label}
                    </button>
                  </li>
                );
              })}
            </ul>
            <button className="navbtn foot" onClick={() => setSurface("public")}>
              <Globe size={14} strokeWidth={1.6} />
              View public page
            </button>
          </nav>

          <main className="main">
            <TopStrip now={now} theme={theme} onTheme={setTheme} />
            <div className="content">
              <Current />
            </div>
          </main>
        </div>
      )}
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
  --warn:#C9922F; --alert:#C4574B;
  --field:#0A0C0D;
}
.theme-light{
  --ink:#FFFFFF; --surface:#FBFBFC; --raised:#F1F3F4;
  --line:#DFE3E5; --line-soft:#EBEEEF;
  --fg:#111618; --fg-dim:#5A656A; --fg-faint:#8B979C;
  --accent:#00897C; --accent-soft:rgba(0,137,124,.09); --accent-ink:#FFFFFF;
  --warn:#8A6112; --alert:#A93F33;
  --field:#FFFFFF;
}

.app,.public{
  font-family:'Geist','Geist Sans',ui-sans-serif,system-ui,-apple-system,'Segoe UI','Helvetica Neue',sans-serif;
  background:var(--ink);color:var(--fg);
  font-size:13.5px;line-height:1.5;font-weight:400;
  -webkit-font-smoothing:antialiased;
}
.mono{font-family:'Geist Mono',ui-monospace,'SF Mono',Menlo,monospace;font-variant-numeric:tabular-nums}
.dim{color:var(--fg-dim)}
.accent-text{color:var(--accent)}
h1,h2,h3{margin:0;font-weight:500;letter-spacing:-.02em}
p{margin:0}
button{font:inherit;color:inherit;background:none;border:none;cursor:pointer}
input,textarea,select{font:inherit;color:inherit;background:var(--field);border:1px solid var(--line);outline:none;width:100%;padding:7px 10px}
input:focus,textarea:focus,select:focus{border-color:var(--accent)}
input::placeholder,textarea::placeholder{color:var(--fg-faint)}
input:disabled{opacity:.4}
textarea{resize:vertical;line-height:1.5}
ul{margin:0;padding:0;list-style:none}
:focus-visible{outline:1px solid var(--accent);outline-offset:2px}

/* ---- shell ---- */
.app{display:grid;grid-template-columns:212px 1fr;min-height:100vh}
.rail{border-right:1px solid var(--line);display:flex;flex-direction:column;background:var(--surface)}
.rail-brand{padding:20px 18px 18px;border-bottom:1px solid var(--line)}
.wordmark{display:block;font-size:14px;font-weight:600;letter-spacing:-.03em}
.rail-ev{display:block;margin-top:5px;font-size:11.5px;color:var(--fg-dim);line-height:1.35}
.rail ul{padding:8px 0}
.navbtn{display:flex;align-items:center;gap:10px;width:100%;padding:7px 18px;color:var(--fg-dim);font-size:13px;text-align:left;transition:color .12s,background .12s}
.navbtn:hover{color:var(--fg);background:var(--raised)}
.navbtn.on{color:var(--fg);background:var(--raised);box-shadow:inset 2px 0 0 var(--accent)}
.navbtn.foot{margin-top:auto;padding:14px 18px;border-top:1px solid var(--line);font-size:12px}
.main{display:flex;flex-direction:column;min-width:0}
.content{padding:26px 30px 60px;max-width:1240px}

.strip{display:flex;align-items:center;gap:26px;height:44px;padding:0 30px;border-bottom:1px solid var(--line);background:var(--surface);font-size:12.5px}
.strip-left{display:flex;align-items:center;gap:9px}
.pulse{width:6px;height:6px;background:var(--accent);animation:pulse 2.4s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
.strip-clock{color:var(--fg-dim)}
.strip-mid{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.strip-label{color:var(--fg-faint)}
.strip-mid b{font-weight:400;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.strip-room{color:var(--fg-dim);white-space:nowrap}
.strip-right{display:flex;align-items:center;gap:18px;color:var(--fg-dim)}
.strip-right em{font-style:normal;color:var(--fg)}
.themetoggle{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border:1px solid var(--line);color:var(--fg-dim)}
.themetoggle:hover{color:var(--accent);border-color:var(--accent)}

/* ---- headings, buttons, tags ---- */
.sec-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:20px}
.sec-head h2{font-size:22px}
.sec-note{margin-top:5px;color:var(--fg-dim);font-size:13px;max-width:62ch}
.sec-actions{display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:1px solid var(--line);color:var(--fg-dim);font-size:12.5px;transition:.12s;white-space:nowrap}
.btn:hover{border-color:var(--fg-faint);color:var(--fg)}
.btn-solid{background:var(--accent);border-color:var(--accent);color:var(--accent-ink);font-weight:500}
.btn-solid:hover{opacity:.86;color:var(--accent-ink)}
.iconbtn{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border:1px solid var(--line);color:var(--fg-faint)}
.iconbtn:hover{color:var(--alert);border-color:var(--alert)}
.tag{display:inline-block;padding:1px 7px;border:1px solid var(--line);font-size:11px;color:var(--fg-dim);white-space:nowrap}
.tone-good{border-color:var(--accent);color:var(--accent)}
.tone-accent{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}
.tone-warn{border-color:var(--warn);color:var(--warn)}
.tone-alert{border-color:var(--alert);color:var(--alert)}

/* ---- stats ---- */
.stats{display:grid;grid-template-columns:repeat(4,1fr);border:1px solid var(--line);margin-bottom:22px;background:var(--surface)}
.stat{padding:16px 18px;border-right:1px solid var(--line)}
.stat:last-child{border-right:none}
.stat-label{font-size:12px;color:var(--fg-dim)}
.stat-value{margin-top:7px;font-size:25px;letter-spacing:-.03em;font-family:'Geist Mono',ui-monospace,monospace;font-variant-numeric:tabular-nums}
.stat-value.accent{color:var(--accent)}
.stat-sub{margin-top:5px;font-size:11.5px;color:var(--fg-faint)}

/* ---- panels ---- */
.panel{border:1px solid var(--line);padding:16px 18px;margin-bottom:22px;background:var(--surface)}
.panel.flush{padding:0}
.panel.flush .panel-head{padding:14px 18px;border-bottom:1px solid var(--line);margin:0}
.panel-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px}
.panel-head b{font-weight:500}
.panel-head.bordered{border-top:1px solid var(--line);padding-top:14px;margin-top:16px}
.panel-note{font-size:11.5px;color:var(--fg-faint)}
.split{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.empty{padding:34px 18px;text-align:center;color:var(--fg-dim);font-size:12.5px}

/* ---- forms ---- */
.panel.form{padding:0}
.panel.form .panel-head{padding:14px 18px;border-bottom:1px solid var(--line);margin:0}
.formgrid{display:grid;grid-template-columns:1fr 1fr;gap:0}
.formcol{padding:18px;display:flex;flex-direction:column;gap:14px}
.formcol+.formcol{border-left:1px solid var(--line)}
.field label{display:block;font-size:11.5px;color:var(--fg-dim);margin-bottom:5px}
.field-val{font-size:12.5px}
.hint{margin-top:5px;font-size:11px;color:var(--fg-faint);line-height:1.45}
.tworow{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.inputgroup{display:flex;border:1px solid var(--line);background:var(--field)}
.inputgroup .prefix{padding:7px 9px;font-size:12px;color:var(--fg-faint);border-right:1px solid var(--line)}
.inputgroup input{border:none;background:none}
.formfoot{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 18px;border-top:1px solid var(--line);flex-wrap:wrap}
.formfoot .hint{margin:0;max-width:46ch}
.photodrop{display:flex;gap:14px;align-items:flex-start;padding-bottom:14px;border-bottom:1px solid var(--line-soft)}
.photoslot{display:flex;align-items:center;justify-content:center;width:76px;height:76px;border:1px solid var(--line);background:var(--field);color:var(--fg-faint);flex-shrink:0}
.photodrop b{font-weight:500;font-size:12.5px}
.photodrop .hint{margin:4px 0 9px}
.picker{display:flex;flex-wrap:wrap;gap:6px}
.segmented{display:flex;border:1px solid var(--line)}
.seg{flex:1;padding:6px 10px;font-size:12.5px;color:var(--fg-dim);border-right:1px solid var(--line)}
.seg:last-child{border-right:none}
.seg:hover{background:var(--raised)}
.seg.on{background:var(--accent-soft);color:var(--accent)}
.switchrow{display:flex;align-items:flex-start;gap:11px;text-align:left;padding:2px 0}
.switch{position:relative;width:30px;height:16px;border:1px solid var(--line);background:var(--field);flex-shrink:0;margin-top:1px;transition:.14s}
.switch .knob{position:absolute;top:2px;left:2px;width:10px;height:10px;background:var(--fg-faint);transition:.14s}
.switch.on{border-color:var(--accent);background:var(--accent-soft)}
.switch.on .knob{left:16px;background:var(--accent)}
.switchtext b{display:block;font-weight:400;font-size:12.5px}
.switchtext span{display:block;font-size:11px;color:var(--fg-faint);margin-top:2px;line-height:1.45}

/* ---- bars, queue, qa ---- */
.bars{display:flex;align-items:flex-end;gap:3px;height:132px}
.bar-wrap{flex:1;height:100%;display:flex;align-items:flex-end}
.bar{width:100%;background:var(--accent);opacity:.75}
.bar-wrap:hover .bar{opacity:1}
.bars-axis{display:flex;justify-content:space-between;margin-top:8px;font-size:10.5px;color:var(--fg-faint)}
.queue li{display:flex;align-items:center;gap:14px;padding:11px 0;border-top:1px solid var(--line-soft)}
.queue li:first-child{border-top:none}
.queue li div{flex:1;min-width:0}
.queue b{display:block;font-weight:400}
.queue span:not(.qtime){display:block;font-size:11.5px;color:var(--fg-faint)}
.qtime{color:var(--warn);width:26px;font-size:12px}
.queue li.done{opacity:.45}
.qa li{display:flex;align-items:center;gap:16px;padding:12px 0;border-top:1px solid var(--line-soft)}
.qa li:first-child{border-top:none}
.qa div{flex:1}
.qa b{display:block;font-weight:400}
.qa span{font-size:11.5px;color:var(--fg-faint)}
.ai-strip{display:flex;align-items:center;gap:14px;margin-top:14px;padding:13px 15px;border:1px solid var(--line);background:var(--accent-soft)}
.ai-strip b{font-weight:500;white-space:nowrap}
.ai-strip span{flex:1;font-size:12.5px;color:var(--fg-dim)}

/* ---- day tabs / programme ---- */
.prog-bar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:16px;flex-wrap:wrap}
.daybar{display:flex;border:1px solid var(--line)}
.daytab{padding:8px 15px;border-right:1px solid var(--line);text-align:left}
.daytab:last-child{border-right:none}
.daytab span{display:block;font-size:12.5px;color:var(--fg-dim)}
.daytab b{display:block;font-size:11px;font-weight:400;color:var(--fg-faint);margin-top:2px}
.daytab:hover{background:var(--raised)}
.daytab.on{background:var(--raised)}
.daytab.on span{color:var(--accent)}
.clash-flag{display:flex;align-items:center;gap:8px;padding:7px 12px;border:1px solid var(--warn);color:var(--warn);font-size:12px}
.grid-wrap{border:1px solid var(--line);margin-bottom:22px;overflow-x:auto;background:var(--surface)}
.grid-head{display:grid;grid-template-columns:62px repeat(3,minmax(190px,1fr));border-bottom:1px solid var(--line)}
.grid-col-head{padding:11px 13px;border-left:1px solid var(--line)}
.grid-col-head b{display:block;font-weight:500;font-size:12.5px}
.grid-col-head span{display:block;font-size:11px;color:var(--fg-faint);margin-top:2px}
.grid-body{display:grid;grid-template-columns:62px repeat(3,minmax(190px,1fr));position:relative}
.grid-gutter{position:relative}
.tick{position:absolute;left:0;width:100%;padding-right:9px;text-align:right;transform:translateY(-6px)}
.tick span{font-size:10.5px;color:var(--fg-faint)}
.grid-col{position:relative;border-left:1px solid var(--line)}
.rule{position:absolute;left:0;right:0;height:1px;background:var(--line-soft)}
.block{position:absolute;left:5px;right:5px;padding:7px 9px;overflow:hidden;background:var(--raised);border-left:2px solid var(--fg-faint)}
.block-time{font-size:10.5px;color:var(--fg-faint)}
.block-title{margin-top:3px;font-size:12.5px;line-height:1.35}
.block-who{margin-top:3px;font-size:11px;color:var(--fg-dim)}
.block-keynote,.block-plenary{border-left-color:var(--accent)}
.block-workshop{border-left-color:var(--warn)}
.block-panel{border-left-color:#4E7C86}
.block-break{background:transparent;border-left-color:var(--line)}
.block-break .block-title{color:var(--fg-faint)}
.block-clash{box-shadow:inset 0 0 0 1px var(--alert)}
.block-fill{display:flex;align-items:center;gap:7px;margin-top:6px;font-size:10.5px;color:var(--fg-faint)}
.fillbar{flex:1;height:2px;background:var(--line)}
.fillbar.wide{height:3px}
.fillbar div{height:100%;background:var(--accent);opacity:.65}
.block-fill.full{color:var(--warn)}
.block-fill.full .fillbar div{background:var(--warn)}
.nowline{position:absolute;left:62px;right:0;height:1px;background:var(--accent);z-index:3}
.nowline span{position:absolute;left:-62px;top:-7px;width:56px;text-align:right;font-size:10.5px;color:var(--accent)}
.nowline::after{content:'';position:absolute;left:0;top:-2px;width:5px;height:5px;background:var(--accent)}

/* ---- speakers ---- */
.avatar{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);background:var(--raised);color:var(--fg-dim);letter-spacing:-.02em;flex-shrink:0}
.spkgrid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.spkcard{border:1px solid var(--line);background:var(--surface);padding:16px 18px;display:flex;flex-direction:column;gap:11px}
.spkcard header{display:flex;align-items:flex-start;gap:12px}
.spkid{flex:1;min-width:0}
.spkid b{display:block;font-weight:500;font-size:14px}
.spkid span{display:block;font-size:12px;color:var(--fg-dim);margin-top:2px}
.spkbio{font-size:12.5px;color:var(--fg-dim);line-height:1.6}
.spksess{border-top:1px solid var(--line-soft);padding-top:9px}
.spksess li{font-size:12px;padding:3px 0 3px 12px;position:relative}
.spksess li::before{content:'';position:absolute;left:0;top:11px;width:5px;height:1px;background:var(--accent)}
.spkcard footer{display:flex;align-items:center;gap:14px;flex-wrap:wrap;border-top:1px solid var(--line-soft);padding-top:11px;margin-top:auto;font-size:11.5px}
.ok{display:inline-flex;align-items:center;gap:5px;color:var(--accent)}
.pending{display:inline-flex;align-items:center;gap:5px;color:var(--warn)}
.spklink{display:inline-flex;align-items:center;gap:5px;color:var(--fg-faint)}
.spkacts{margin-left:auto;display:flex;gap:7px}

/* ---- tickets ---- */
.ticketlist{border:1px solid var(--line);background:var(--surface)}
.ticketrow{display:grid;grid-template-columns:1fr 110px 150px auto;gap:20px;align-items:center;padding:15px 18px;border-bottom:1px solid var(--line-soft)}
.ticketrow:last-child{border-bottom:none}
.ticketrow:hover{background:var(--raised)}
.tr-name{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tr-name b{font-weight:500;font-size:13.5px}
.tr-main p{font-size:12px;color:var(--fg-dim);margin-top:3px}
.tr-fill{display:flex;align-items:center;gap:10px;margin-top:9px;max-width:340px}
.tr-fill .fillbar{flex:1}
.tr-fill span{font-size:11px}
.tr-price{font-size:14px;text-align:right}
.tr-dates{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--fg-dim)}
.tr-acts{display:flex;gap:7px;justify-content:flex-end}

/* ---- tables ---- */
.table{width:100%;border-collapse:collapse;font-size:12.5px}
.table th{padding:10px 14px;text-align:left;font-weight:400;font-size:11.5px;color:var(--fg-faint);border-bottom:1px solid var(--line)}
.table td{padding:11px 14px;border-bottom:1px solid var(--line-soft);vertical-align:middle}
.table tbody tr:last-child td{border-bottom:none}
.table tbody tr:hover{background:var(--raised)}
.table b{font-weight:400}
.cellwho{display:flex;align-items:center;gap:9px}
.rowsegs{display:inline-flex;gap:5px;margin-left:9px}
.rowact{text-align:right}

/* ---- guests ---- */
.mailpreview{border:1px solid var(--line);padding:18px;background:var(--ink)}
.mail-sub{font-size:14px;font-weight:500;margin-bottom:9px}
.mailacts{display:flex;gap:9px;margin-top:16px}
.fakebtn{padding:7px 14px;background:var(--accent);color:var(--accent-ink);font-size:12.5px;font-weight:500}
.fakebtn.ghost{background:none;border:1px solid var(--line);color:var(--fg-dim);font-weight:400}

/* ---- filters ---- */
.filters{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}
.chip{display:inline-flex;align-items:center;gap:8px;padding:5px 11px;border:1px solid var(--line);color:var(--fg-dim);font-size:12.5px;text-align:left}
.chip em{font-style:normal;font-size:11px;color:var(--fg-faint)}
.chip:hover{border-color:var(--fg-faint);color:var(--fg)}
.chip.on{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}
.chip.on em{color:var(--accent)}
.searchrow{display:flex;align-items:center;gap:10px;padding:0 14px;border:1px solid var(--line);margin-bottom:16px;color:var(--fg-faint);background:var(--surface)}
.searchrow input{flex:1;font-size:13px;border:none;background:none;padding:9px 0}

/* ---- check-in ---- */
.checkin{display:grid;grid-template-columns:340px 1fr;gap:22px;margin-bottom:22px}
.scanner{border:1px solid var(--line);padding:22px 20px;background:var(--surface)}
.scan-frame{position:relative;aspect-ratio:1;display:flex;align-items:center;justify-content:center;background:var(--field);border:1px solid var(--line-soft);overflow:hidden}
.scan-icon{color:var(--fg-faint)}
.scan-corner{position:absolute;width:22px;height:22px;border:1px solid var(--accent)}
.scan-corner.tl{top:14px;left:14px;border-right:none;border-bottom:none}
.scan-corner.tr{top:14px;right:14px;border-left:none;border-bottom:none}
.scan-corner.bl{bottom:14px;left:14px;border-right:none;border-top:none}
.scan-corner.br{bottom:14px;right:14px;border-left:none;border-top:none}
.scan-sweep{position:absolute;left:14px;right:14px;height:1px;background:var(--accent);opacity:.7;animation:sweep 3.2s ease-in-out infinite}
@keyframes sweep{0%,100%{top:16%}50%{top:84%}}
@media (prefers-reduced-motion:reduce){.scan-sweep{animation:none;top:50%}.pulse{animation:none}}
.scan-note{margin-top:14px;font-size:12px;color:var(--fg-dim)}
.manual{margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}
.manual label{display:block;font-size:11.5px;color:var(--fg-faint);margin-bottom:7px}
.manual-row{display:flex;gap:8px}
.manual-row input{letter-spacing:.04em}
.scans li{display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid var(--line-soft)}
.scans li:last-child{border-bottom:none}
.scans li div{flex:1;display:flex;flex-direction:column;gap:1px}
.scans b{font-weight:400}
.scans .dim{font-size:11px}
.dot{display:flex;align-items:center;justify-content:center;width:17px;height:17px;flex-shrink:0}
.dot.ok{background:var(--accent-soft);color:var(--accent)}
.dot.no{background:var(--raised);color:var(--alert)}
.scans li.bad b{color:var(--alert)}
.scan-gate{font-size:11.5px;color:var(--fg-dim)}
.scan-time{font-size:11.5px;color:var(--fg-faint)}
.scan-fail{display:flex;align-items:center;gap:9px;padding:11px 18px;border-top:1px solid var(--line);color:var(--warn);font-size:12px}

/* ---- live ---- */
.ask{font-size:19px;letter-spacing:-.02em;line-height:1.35;padding:4px 0 16px;border-bottom:1px solid var(--line)}
.ask.small{font-size:15px}
.stream li{position:relative;padding:11px 0 11px 15px;border-bottom:1px solid var(--line-soft);font-size:13.5px;animation:rise .3s ease both}
.stream li:last-child{border-bottom:none}
.quote-rule{position:absolute;left:0;top:13px;bottom:13px;width:2px;background:var(--accent);opacity:.5}
@keyframes rise{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.stream li{animation:none}}
.stream-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;padding-top:13px;border-top:1px solid var(--line);font-size:12px}
.answers li{padding:10px 0}
.ans-row{display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px}
.ans-row b{font-weight:400;color:var(--fg-dim)}
.answers li.right .ans-row b{color:var(--fg)}
.ans-bar{height:3px;background:var(--line)}
.ans-bar div{height:100%;background:var(--fg-faint)}
.answers li.right .ans-bar div{background:var(--accent)}

/* ---- badges ---- */
.badge-wrap{display:grid;grid-template-columns:1fr 300px;gap:22px}
.canvas{border:1px solid var(--line);padding:26px;background:var(--surface)}
.canvas-rulers{display:flex;justify-content:space-between;font-size:10px;color:var(--fg-faint);margin-bottom:9px;border-bottom:1px solid var(--line-soft);padding-bottom:5px}
.badge{display:flex;aspect-ratio:100/70;max-width:520px;background:#FFFFFF;border:1px solid var(--line)}
.badge-band{width:9px;background:var(--accent)}
.badge-body{flex:1;display:flex;flex-direction:column;padding:20px 22px;color:#111618}
.badge-ev{font-size:11px;color:#5A656A}
.badge-name{margin-top:auto;font-size:30px;letter-spacing:-.035em;line-height:1.05}
.badge-org{margin-top:6px;font-size:12px;color:#5A656A}
.badge-foot{display:flex;align-items:flex-end;gap:16px;margin-top:auto;padding-top:16px}
.qr{display:grid;grid-template-columns:repeat(8,1fr);width:54px;height:54px;background:#fff;padding:3px;gap:1px}
.qr i{background:#fff}
.qr i.on{background:#000}
.badge-meta{display:flex;flex-direction:column;gap:3px;font-size:11px;color:#5A656A}
.badge-meta b{color:#00776C;font-weight:500;font-size:11.5px}
.canvas-note{margin-top:16px;font-size:12px;color:var(--fg-faint);max-width:56ch}
.panel.side{margin:0}
.fields{display:flex;flex-direction:column;gap:12px}
.layers li{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--line-soft);font-size:12.5px}
.layers li:last-child{border-bottom:none}
.layers .dim{font-size:11px}

/* ---- money ---- */
.accounts li{display:flex;align-items:center;gap:13px;padding:14px 18px;border-bottom:1px solid var(--line-soft);color:var(--fg-dim)}
.accounts li div{flex:1;display:flex;flex-direction:column;gap:2px}
.accounts b{font-weight:400;color:var(--fg)}
.accounts span{font-size:11.5px}
.accounts li.addrow{color:var(--fg-faint);cursor:pointer}
.accounts li.addrow:hover{background:var(--raised)}
.kyc,.hold{display:flex;align-items:center;gap:9px;padding:12px 18px;border-top:1px solid var(--line);font-size:12px;color:var(--fg-dim)}
.kyc{color:var(--accent)}
.exports{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--line)}
.export{display:flex;align-items:center;gap:9px;padding:13px 14px;background:var(--surface);color:var(--fg-dim);font-size:12.5px;text-align:left}
.export:hover{background:var(--raised);color:var(--fg)}

/* ---- public ---- */
.public{min-height:100vh}
.pub-bar{display:flex;align-items:center;justify-content:space-between;padding:14px 40px;border-bottom:1px solid var(--line)}
.pub-bar-right{display:flex;align-items:center;gap:16px}
.linkish{font-size:12.5px;color:var(--fg-dim);border-bottom:1px solid var(--line)}
.linkish:hover{color:var(--accent);border-color:var(--accent)}
.cover{position:relative;overflow:hidden;background:var(--surface);border-bottom:1px solid var(--line)}
.cover-bars{position:absolute;inset:0;display:flex;align-items:flex-end;gap:2px;padding:0 4px}
.cover-bars span{flex:1;background:var(--accent);opacity:.42}
.cover-bars span:nth-child(3n){opacity:.2}
.cover-bars span:nth-child(4n){opacity:.62}
.pub-hero-body{max-width:1100px;margin:0 auto;padding:34px 40px 0}
.pub-vis{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--accent);border:1px solid var(--accent);padding:2px 8px}
.pub-title{margin-top:18px;font-size:clamp(36px,6.5vw,74px);line-height:.98;letter-spacing:-.045em;font-weight:400}
.pub-host{margin-top:14px;font-size:13.5px;color:var(--fg-dim)}
.pub-facts{display:grid;grid-template-columns:repeat(4,1fr);margin-top:34px;border-top:1px solid var(--line)}
.pub-facts div{padding:16px 20px 20px 0;border-right:1px solid var(--line)}
.pub-facts div:last-child{border-right:none}
.pub-facts span{display:block;font-size:11.5px;color:var(--fg-faint)}
.pub-facts b{display:block;margin-top:6px;font-size:14px;font-weight:400;line-height:1.35}
.pub-tabs{display:flex;gap:0;max-width:1100px;margin:0 auto;padding:0 40px;border-bottom:1px solid var(--line)}
.pubtab{padding:13px 16px;font-size:13px;color:var(--fg-dim);margin-bottom:-1px;border-bottom:1px solid transparent}
.pubtab:first-child{padding-left:0}
.pubtab:hover{color:var(--fg)}
.pubtab.on{color:var(--accent);border-bottom-color:var(--accent)}
.pub-body{display:grid;grid-template-columns:1fr 330px;gap:56px;max-width:1100px;margin:0 auto;padding:44px 40px 90px}
.lede{font-size:17px;line-height:1.55;letter-spacing:-.01em;max-width:60ch;color:var(--fg-dim)}
.prose{font-size:13.5px;line-height:1.68;max-width:66ch;color:var(--fg-dim);margin-top:12px}
.pub-block{margin-top:48px}
.pub-block.first{margin-top:0}
.pub-block h2{font-size:16px;margin-bottom:6px}
.daytheme{font-size:12.5px;color:var(--fg-faint);margin:12px 0 4px}
.agenda-row{display:grid;grid-template-columns:64px 1fr auto;gap:16px;padding:13px 0;border-top:1px solid var(--line-soft);align-items:baseline}
.agenda-row.muted{color:var(--fg-faint)}
.agenda-row .time{font-size:12px;color:var(--fg-dim)}
.agenda-main b{font-weight:400;font-size:14px}
.agenda-who{display:block;margin-top:3px;font-size:12px;color:var(--fg-dim)}
.agenda-room{font-size:11.5px;color:var(--fg-faint)}
.whorow{display:grid;grid-template-columns:repeat(5,1fr);border-top:1px solid var(--line);margin-top:14px}
.who{padding:14px 12px 14px 0;border-right:1px solid var(--line)}
.who:last-child{border-right:none}
.who b{display:block;font-size:19px;font-weight:400}
.who span{display:block;font-size:11.5px;color:var(--fg-dim);margin-top:5px;line-height:1.4}
.pubspeakers{display:flex;flex-direction:column}
.pubspk{display:flex;gap:18px;padding:22px 0;border-top:1px solid var(--line-soft)}
.pubspk:first-child{border-top:none;padding-top:0}
.pubspk h3{font-size:15px}
.pubspk-role{font-size:12.5px;color:var(--fg-dim);margin-top:3px}
.pubspk-sess{margin-top:12px}
.pubspk-sess li{font-size:12px;color:var(--fg-faint);padding:3px 0 3px 12px;position:relative}
.pubspk-sess li::before{content:'';position:absolute;left:0;top:11px;width:5px;height:1px;background:var(--accent)}
.pubtickets{border-top:1px solid var(--line)}
.pubticket{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:16px 0;border-bottom:1px solid var(--line-soft)}
.pubticket b{display:block;font-weight:500;font-size:14px}
.pubticket span{display:block;font-size:12.5px;color:var(--fg-dim);margin-top:3px}
.pubticket .tag{margin-top:7px}
.pubticket-right{text-align:right;flex-shrink:0}
.pubticket-right em{font-style:normal;font-size:15px;display:block}
.pubticket-right .dim{font-size:11px}
.pubticket.sold{opacity:.45}
.venuegrid{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid var(--line);border-left:1px solid var(--line);margin-top:20px}
.venuecell{padding:14px 16px;border-right:1px solid var(--line);border-bottom:1px solid var(--line)}
.venuecell b{display:block;font-weight:400;font-size:13.5px}
.venuecell span{display:block;font-size:12px;color:var(--fg-dim);margin-top:3px}
.ticketbox{position:sticky;top:26px;border:1px solid var(--line);padding:20px;background:var(--surface)}
.ticketbox h3{font-size:15px;margin-bottom:14px}
.tickets{display:flex;flex-direction:column;gap:1px;background:var(--line);margin-bottom:16px}
.ticket{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 14px;background:var(--ink);text-align:left;width:100%}
.ticket b{display:block;font-weight:400;font-size:13.5px}
.ticket span{display:block;font-size:11.5px;color:var(--fg-faint);margin-top:3px;line-height:1.4}
.ticket em{font-style:normal;font-size:14px;flex-shrink:0}
.ticket.on{box-shadow:inset 2px 0 0 var(--accent)}
.ticket.on em{color:var(--accent)}
.ticket.sold{opacity:.4;cursor:not-allowed}
.ticketbox .btn{width:100%;justify-content:center;padding:10px}
.paynote{margin-top:13px;font-size:11.5px;color:var(--fg-faint);line-height:1.5}

/* ---- responsive ---- */
@media (max-width:1100px){
  .split,.badge-wrap,.checkin,.spkgrid,.formgrid{grid-template-columns:1fr}
  .formcol+.formcol{border-left:none;border-top:1px solid var(--line)}
  .exports{grid-template-columns:repeat(2,1fr)}
  .pub-body{grid-template-columns:1fr;gap:40px}
  .ticketbox{position:static}
  .whorow{grid-template-columns:repeat(2,1fr)}
  .ticketrow{grid-template-columns:1fr auto;gap:12px}
  .tr-dates{display:none}
}
@media (max-width:820px){
  .app{grid-template-columns:1fr}
  .rail{flex-direction:row;align-items:center;overflow-x:auto;border-right:none;border-bottom:1px solid var(--line)}
  .rail-brand{border-bottom:none;border-right:1px solid var(--line);padding:12px 16px;white-space:nowrap}
  .rail-ev{display:none}
  .rail ul{display:flex;padding:0}
  .navbtn{padding:14px;white-space:nowrap}
  .navbtn.on{box-shadow:inset 0 -2px 0 var(--accent)}
  .navbtn.foot{margin-top:0;border-top:none;border-left:1px solid var(--line)}
  .stats{grid-template-columns:repeat(2,1fr)}
  .stat:nth-child(2n){border-right:none}
  .stat:nth-child(-n+2){border-bottom:1px solid var(--line)}
  .strip{gap:16px;padding:0 18px}
  .strip-mid{display:none}
  .content{padding:20px 18px 50px}
  .pub-bar,.pub-hero-body,.pub-tabs,.pub-body{padding-left:20px;padding-right:20px}
  .pub-facts{grid-template-columns:repeat(2,1fr)}
  .pub-facts div:nth-child(2n){border-right:none}
  .pub-tabs{overflow-x:auto}
  .venuegrid{grid-template-columns:1fr}
  .exports{grid-template-columns:1fr}
  .tworow{grid-template-columns:1fr}
}
`;
