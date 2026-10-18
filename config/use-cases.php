<?php

declare(strict_types=1);

/*
 * The use-case pages: who MiConvener is for and what it does for them.
 *
 * Every claim here must be something the product does today. Member dues,
 * anniversary reminders and surveys are not built, so no page promises them.
 * No customer names and no testimonials until there are real ones to show.
 * Live polling is described the way the plans sell it: included from Growth,
 * available as an add-on below that.
 */
return [
    'hub' => [
        'eyebrow' => 'Use cases',
        'title' => 'Made for every kind of gathering',
        'intro' => 'A conference, a thesis defence, a weekly service, a funeral, a reunion. MiConvener runs them the same dependable way: people register, pay, get reminded, show up and are looked after.',
    ],

    'groups' => [
        'Conferences and professional events' => ['academic', 'international', 'delegates', 'classroom'],
        'Communities and celebrations' => ['communities', 'legacy', 'social'],
    ],

    /*
     * How each group is introduced on the hub, and its short name in the filter.
     */
    'group_meta' => [
        'Conferences and professional events' => ['filter' => 'Conferences & professional', 'note' => 'Programmes, speakers, delegations and accreditation'],
        'Communities and celebrations' => ['filter' => 'Communities & celebrations', 'note' => 'Regular services, reunions, memorials and parties'],
    ],

    /*
     * "One platform underneath": the wording follows the event type (see
     * EventLexicon), and the same core runs every gathering.
     */
    'words' => [
        ['Churches', 'Tithes and offerings'],
        ['Memorials', 'Tribute wall'],
        ['Academic events', 'Department funds'],
    ],
    'core' => [
        ['title' => 'Registration and tickets', 'text' => 'Free, paid and tiered tickets with custom forms.'],
        ['title' => 'Payments and settlement', 'text' => 'Card and mobile money, with a statement and payout for every event.'],
        ['title' => 'Door scanning, offline', 'text' => 'Ushers scan from any phone with a staff link. No app, no account.'],
        ['title' => 'Badges and certificates', 'text' => 'Branded and verifiable, issued to the people who attended.'],
        ['title' => 'Attendee portal', 'text' => 'Tickets, materials, receipts and certificates in one place.'],
        ['title' => 'Reminders and updates', 'text' => 'Email and SMS to everyone or to a chosen group.'],
    ],

    'cases' => [
        'academic' => [
            'title' => 'Academic and research events',
            'summary' => 'Symposia, conferences, seminars, vivas and thesis defences.',
            'eyebrow' => 'Academic events',
            'headline' => 'From the call for abstracts to the certificate in hand',
            'intro' => 'Symposia, conferences, seminars, vivas and thesis defences. Collect and review abstracts, publish the programme, register delegates and send certificates, all from one workspace.',
            'for' => [
                'Universities, faculties and departments',
                'Research institutes and learned societies',
                'Graduate schools running defences and seminar series',
                'Professional bodies with an annual scientific meeting',
            ],
            'features' => [
                ['tag' => 'Abstracts', 'title' => 'Call for abstracts', 'text' => 'Authors submit abstracts online, reviewers score them, and authors are emailed the decision.'],
                ['tag' => 'Programme', 'title' => 'A programme people can follow', 'text' => 'Sessions, tracks and speakers on a public event page, with times and rooms.'],
                ['tag' => 'Registration', 'title' => 'Registration and tickets', 'text' => 'Free or paid registration, ticket types for students, members and delegates, and promo codes.'],
                ['tag' => 'Defences', 'title' => 'Vivas and defences as sessions', 'text' => 'Schedule each defence or viva as a timed session with its panel and room, and share the timetable.'],
                ['tag' => 'Certificates', 'title' => 'Attendance and certificates', 'text' => 'Check people in to the event and to individual sessions, then issue certificates of attendance.'],
                ['tag' => 'Materials', 'title' => 'Speaker materials', 'text' => 'Collect slides and papers and release them to attendees when you choose.'],
            ],
            'timeline' => [
                'Before' => ['Open the call for abstracts', 'Review submissions and notify authors', 'Publish the programme and open registration'],
                'On the day' => ['Print name badges and check people in', 'Record attendance session by session'],
                'Afterwards' => ['Issue certificates', 'Release speaker materials', 'Thank everyone by email'],
            ],
            'value' => [
                'title' => 'One record from submission to certificate',
                'text' => 'Authors, reviewers, speakers and delegates live in one system, so the programme, the attendance register and the certificates always agree.',
                'highlight' => 'always agree',
            ],
            'related' => ['classroom', 'international'],
            'tags' => ['Abstract review', 'Certificates', 'Department funds'],
            'panels' => [
                'Before' => ['title' => 'Registration', 'columns' => ['Ticket', 'Price', 'Rules'], 'rows' => [
                    ['Faculty and researchers', ['ghs' => 450, 'usd' => 45], 'Full programme'],
                    ['Students', ['ghs' => 150, 'usd' => 15], 'Student ID at check-in'],
                    ['Society members', ['ghs' => 300, 'usd' => 30], 'Promo code from your society'],
                    ['Presenting authors', 'Free', 'Accepted abstract'],
                ]],
                'On the day' => ['title' => 'Session attendance', 'columns' => ['Session', 'Room', 'Checked in'], 'rows' => [
                    ['Opening plenary', 'Great Hall', '312 of 340'],
                    ['Parallel A · Public health', 'Room 2', '88 of 96'],
                    ['Parallel B · Agriculture', 'Room 4', '64 of 70'],
                    ['PhD defence · Economics', 'Seminar room', '23 of 25'],
                ]],
                'Afterwards' => ['title' => 'Certificates', 'columns' => ['Certificate', 'Issued', 'Downloaded'], 'rows' => [
                    ['Attendance', '312', '241'],
                    ['Presenter', '46', '44'],
                    ['Reviewer', '18', '15'],
                ]],
            ],
            'cta' => 'Run your next symposium on MiConvener.',
        ],

        'international' => [
            'title' => 'International and institutional gatherings',
            'summary' => 'Summits, assemblies and multi-day meetings with many delegations.',
            'eyebrow' => 'International gatherings',
            'headline' => 'Many delegations, many days, one registration desk',
            'intro' => 'Agencies, institutes and networks that bring people together from many places: summits, assemblies, regional meetings and training weeks.',
            'for' => [
                'International and regional organisations',
                'Government agencies and institutes',
                'NGOs and professional networks',
                'Companies with multi-day conferences',
            ],
            'features' => [
                ['tag' => 'Delegations', 'title' => 'Delegations and groups', 'text' => 'Group attendees by country, branch or delegation so you can see and address each one.'],
                ['tag' => 'Ticketing', 'title' => 'A ticket type for every attendee', 'text' => 'Delegates, observers, speakers and guests each get their own ticket type, price and rules.'],
                ['tag' => 'Programme', 'title' => 'Programmes across days and tracks', 'text' => 'Parallel sessions, speakers and rooms laid out clearly on one public page.'],
                ['tag' => 'Sponsors', 'title' => 'Sponsors and what they were promised', 'text' => 'Record sponsors and their deliverables, and keep track of what has been delivered.'],
                ['tag' => 'Accreditation', 'title' => 'Badges and accreditation', 'text' => 'Printed name badges and QR passes, checked at the door and at each session.'],
                ['tag' => 'Messaging', 'title' => 'Announcements that reach everyone', 'text' => 'Email and text updates to all attendees or to a chosen audience.'],
            ],
            'timeline' => [
                'Before' => ['Open registration with the right ticket types', 'Group delegations and confirm sponsors', 'Send joining instructions'],
                'On the day' => ['Scan passes at the door and at sessions', 'Send updates by email and text as plans change'],
                'Afterwards' => ['Share materials and certificates', 'Report on attendance and delegations'],
            ],
            'value' => [
                'title' => 'Calm at the registration desk',
                'text' => 'When hundreds of people arrive from many places at once, one accurate list is what keeps the day moving.',
                'highlight' => 'one accurate list',
            ],
            'related' => ['delegates', 'academic'],
            'tags' => ['Delegations', 'Multi-day programme', 'Badges'],
            'panels' => [
                'Before' => ['title' => 'Ticket types', 'columns' => ['Type', 'Price', 'Rules'], 'rows' => [
                    ['Delegate', ['ghs' => 1500, 'usd' => 150], 'Nominated by delegation'],
                    ['Observer', ['ghs' => 600, 'usd' => 60], 'Plenary sessions only'],
                    ['Speaker', 'Free', 'Invite code required'],
                    ['Guest', ['ghs' => 400, 'usd' => 40], 'Evening programme'],
                ]],
                'On the day' => ['title' => 'Check-in by session', 'columns' => ['Session', 'Room', 'Scanned'], 'rows' => [
                    ['Opening plenary', 'Hall A', '1,104'],
                    ['Track 1 · Trade', 'Room 3', '286'],
                    ['Track 2 · Health', 'Room 5', '241'],
                    ['Evening reception', 'Terrace', '612'],
                ]],
                'Afterwards' => ['title' => 'Attendance by delegation', 'columns' => ['Delegation', 'Registered', 'Attended'], 'rows' => [
                    ['Ghana', '46', '42'],
                    ['Kenya', '40', '38'],
                    ['Germany', '36', '31'],
                    ['Brazil', '34', '27'],
                ]],
            ],
            'cta' => 'Plan your next gathering on MiConvener.',
        ],

        'delegates' => [
            'title' => 'Delegate conferences and congresses',
            'summary' => 'Associations, unions and professional bodies meeting as delegates.',
            'eyebrow' => 'Delegate conferences',
            'headline' => 'Accredit delegates, collect their fees, run the floor',
            'intro' => 'Annual general meetings, national congresses and delegate conferences for associations, unions and professional bodies. Register delegates by branch, take their fees and keep order at the door.',
            'for' => [
                'Associations, unions and professional bodies',
                'Federations with branches or regions',
                'Societies holding congresses and general meetings',
            ],
            'features' => [
                ['tag' => 'Approval', 'title' => 'Registration with approval', 'text' => 'Require approval so only accredited delegates are confirmed, and tell everyone the outcome.'],
                ['tag' => 'Payments', 'title' => 'Paying delegates', 'text' => 'Delegate fees by card or mobile money, with promo codes for sponsored or subsidised delegates.'],
                ['tag' => 'Branches', 'title' => 'Branches and regions', 'text' => 'Group delegates by branch or region and see who has registered from each.'],
                ['tag' => 'Credentials', 'title' => 'Credentials at the door', 'text' => 'QR passes and printed badges, scanned by ushers on their own phones.'],
                ['tag' => 'Staff links', 'title' => 'Staff links for ushers', 'text' => 'Give ushers and floor crew a link, with no account needed, to scan passes and answer requests.'],
                ['tag' => 'Statement', 'title' => 'Clear money records', 'text' => 'Every payment, fee and payout on one statement for the treasurer.'],
            ],
            'timeline' => [
                'Before' => ['Open registration and approve delegates', 'Group delegates by branch', 'Send passes by email'],
                'On the day' => ['Scan credentials at the door', 'Ushers handle requests from their phones'],
                'Afterwards' => ['Export the attendance register', 'Reconcile fees on one statement'],
            ],
            'value' => [
                'title' => 'Only the right people, and every fee accounted for',
                'text' => 'Approval keeps the floor to accredited delegates, and the statement keeps the treasurer confident that every cedi is recorded.',
                'highlight' => 'every cedi is recorded',
            ],
            'related' => ['international', 'communities'],
            'tags' => ['Delegate approval', 'Mobile money', 'Staff links'],
            'panels' => [
                'Before' => ['title' => 'Delegate fees', 'columns' => ['Type', 'Fee', 'Rules'], 'rows' => [
                    ['Branch delegate', ['ghs' => 800, 'usd' => 70], 'Approval required'],
                    ['Observer', ['ghs' => 300, 'usd' => 25], 'No voting card'],
                    ['Sponsored delegate', 'Free with code', 'Code from your branch'],
                    ['Past president', 'Free', 'Invitation only'],
                ]],
                'On the day' => ['title' => 'Door scanning', 'columns' => ['Staff link', 'Entrance', 'Scanned'], 'rows' => [
                    ['Usher 1', 'Main gate', '412'],
                    ['Usher 2', 'Main gate', '389'],
                    ['Usher 3', 'Side entrance', '156'],
                ]],
                'Afterwards' => ['title' => 'Fees by payment method', 'columns' => ['Method', 'Delegates', 'Amount'], 'rows' => [
                    ['Mobile money', '240', ['ghs' => 192000, 'usd' => 16800]],
                    ['Card', '177', ['ghs' => 141600, 'usd' => 12390]],
                    ['Promo code', '3', 'Free'],
                ]],
            ],
            'cta' => 'Run your next congress on MiConvener.',
        ],

        'classroom' => [
            'title' => 'Live classrooms and workshops',
            'summary' => 'Interactive sessions with polls and questions from the room.',
            'eyebrow' => 'Live classrooms',
            'headline' => 'Hear from the whole room, not just the front row',
            'intro' => 'Lectures, workshops and training sessions where the audience takes part. Ask a poll, watch the answers arrive, and take questions without a microphone going round.',
            'for' => [
                'Lecturers and trainers',
                'Workshop and bootcamp organisers',
                'Conference session chairs',
                'Corporate learning teams',
            ],
            'features' => [
                ['tag' => 'Polls', 'title' => 'Live polls', 'text' => 'Put a question to the room and show the results as people answer.'],
                ['tag' => 'Q&A', 'title' => 'Audience questions', 'text' => 'People send questions from their phones, and a moderator chooses what goes on screen.'],
                ['tag' => 'Presenter', 'title' => 'A presenter deck', 'text' => 'Run polls and questions from a single presenter view while you teach.'],
                ['tag' => 'Attendance', 'title' => 'Session attendance', 'text' => 'Record who attended which session, for credit or for your records.'],
                ['tag' => 'Certificates', 'title' => 'Certificates', 'text' => 'Issue certificates of attendance when the session ends.'],
                ['tag' => 'Materials', 'title' => 'Materials after the session', 'text' => 'Release slides and handouts to attendees when you are ready.'],
            ],
            'timeline' => [
                'Before' => ['Register participants', 'Prepare polls in the presenter deck'],
                'On the day' => ['Run polls and take moderated questions', 'Record attendance for each session'],
                'Afterwards' => ['Issue certificates', 'Share materials'],
            ],
            'value' => [
                'title' => 'A room that answers back',
                'text' => 'Polls and questions turn a talk into a conversation, and quiet voices get heard as readily as loud ones.',
                'highlight' => 'quiet voices get heard',
            ],
            'note' => 'Live polling and Q&A is included from the Growth plan, and can be added to other plans by the month or for a single event.',
            'related' => ['academic', 'social'],
            'tags' => ['Live polling', 'Audience Q&A', 'Materials'],
            'panels' => [
                'Before' => ['title' => 'Poll deck', 'columns' => ['#', 'Question', 'Type'], 'rows' => [
                    ['1', 'How confident are you with spreadsheets?', 'Rating'],
                    ['2', 'One word for how today feels', 'Word cloud'],
                    ['3', 'Which topic should we cover next?', 'Multiple choice'],
                    ['4', 'Check your understanding', 'Quiz'],
                ]],
                'On the day' => ['title' => 'Questions from the room', 'columns' => ['Question', 'Votes', 'Status'], 'rows' => [
                    ['Can we get the dataset after class?', '18', 'On screen'],
                    ['How do pivot tables handle blanks?', '12', 'Next'],
                    ['Will there be a certificate?', '7', 'Answered'],
                ]],
                'Afterwards' => ['title' => 'Session report', 'columns' => ['Session', 'Attended', 'Certificates'], 'rows' => [
                    ['Workshop 1 · Data cleaning', '58', '58'],
                    ['Workshop 2 · Charts', '54', '54'],
                    ['Workshop 3 · Statistics', '49', '49'],
                ]],
            ],
            'cta' => 'Run your next workshop on MiConvener.',
        ],

        'communities' => [
            'title' => 'Congregations, unions and old-school groups',
            'summary' => 'Weekly services, regular meetings and reunions, with reminders built in.',
            'eyebrow' => 'Communities',
            'headline' => 'Set it once; every meeting takes care of itself',
            'intro' => 'Services, chapter meetings, branch gatherings and old-school reunions repeat. Create the event once, and each occurrence is scheduled, announced and remembered for you.',
            'for' => [
                'Churches, mosques and faith groups',
                'Trade unions and associations',
                'Old-school and alumni groups',
                'Clubs, societies and community organisations',
            ],
            'features' => [
                ['tag' => 'Recurring', 'title' => 'Recurring events', 'text' => 'Daily, weekly or monthly gatherings that appear on their own, so you never rebuild the same event.'],
                ['tag' => 'Reminders', 'title' => 'Reminders and notifications', 'text' => 'Automatic reminders before each occurrence, without anyone having to remember to send them.'],
                ['tag' => 'Messaging', 'title' => 'Messages to members', 'text' => 'Announcements by email and SMS to everyone, or to a chosen group.'],
                ['tag' => 'Giving', 'title' => 'Donations and offerings', 'text' => 'Collect voluntary contributions by card or mobile money, with a receipt for every gift.'],
                ['tag' => 'Groups', 'title' => 'Groups', 'text' => 'Organise people into groups such as branches, classes or committees.'],
                ['tag' => 'Check-in', 'title' => 'Simple attendance', 'text' => 'Check people in with a pass on their phone, or let them check themselves in.'],
            ],
            'timeline' => [
                'Once' => ['Create the recurring event and its reminders', 'Invite your members'],
                'Every time' => ['Reminders go out on schedule', 'People sign up and check in'],
                'Afterwards' => ['Thank-you messages', 'Receipts for donations'],
            ],
            'value' => [
                'title' => 'Less chasing, more gathering',
                'text' => 'The reminders, the sign-ups and the receipts happen by themselves, so the people who run the group can spend their time with its members.',
                'highlight' => 'spend their time with its members',
            ],
            'related' => ['legacy', 'social'],
            'tags' => ['Recurring events', 'Reminders', 'Tithes and offerings'],
            'panels' => [
                'Once' => ['title' => 'Recurring event', 'columns' => ['Setting', 'Value'], 'rows' => [
                    ['Repeats', 'Every Sunday'],
                    ['Time', '09:00 to 11:30'],
                    ['Reminder', 'Saturday 18:00, email and SMS'],
                    ['Giving', 'Card and mobile money'],
                ]],
                'Every time' => ['title' => 'This Sunday', 'columns' => ['Group', 'Signed up', 'Checked in'], 'rows' => [
                    ['Choir', '42', '40'],
                    ['Youth', '118', '96'],
                    ['Men\'s fellowship', '64', '51'],
                    ['Women\'s fellowship', '88', '79'],
                ]],
                'Afterwards' => ['title' => 'Giving this month', 'columns' => ['Gift', 'Receipts', 'Amount'], 'rows' => [
                    ['Tithes', '214', ['ghs' => 38520, 'usd' => 3300]],
                    ['Offerings', '356', ['ghs' => 12460, 'usd' => 1070]],
                    ['Missions', '41', ['ghs' => 9840, 'usd' => 845]],
                ]],
            ],
            'cta' => 'Bring your community together on MiConvener.',
        ],

        'legacy' => [
            'title' => 'Legacy: honouring a life',
            'summary' => 'Funerals, memorials and celebrations of life.',
            'eyebrow' => 'Legacy',
            'headline' => 'A dignified place to honour a life',
            'intro' => 'A funeral, a memorial or a celebration of life brings family and friends together from near and far. Share the order of service, gather tributes and receive support, all in one gentle place.',
            'for' => [
                'Families arranging a funeral or memorial',
                'Funeral committees and community groups',
                'Churches and organisations marking a member\'s passing',
            ],
            'features' => [
                ['tag' => 'Programme', 'title' => 'Order of service', 'text' => 'Publish the programme for the day, with the sessions in their proper order, for everyone to follow.'],
                ['tag' => 'Tributes', 'title' => 'Tributes and condolences', 'text' => 'A wall where friends and family leave words of remembrance, moderated by the family.'],
                ['tag' => 'Giving', 'title' => 'Donations and support', 'text' => 'Receive gifts toward the family or a memorial fund by card or mobile money, with a receipt for each.'],
                ['tag' => 'Messaging', 'title' => 'Messages to those who cannot travel', 'text' => 'Share the programme and updates by email and text with everyone who wants to be kept informed.'],
                ['tag' => 'RSVP', 'title' => 'Who is coming', 'text' => 'Let guests say they are attending, so the family can plan the day with confidence.'],
                ['tag' => 'Sharing', 'title' => 'One link to share', 'text' => 'A single public page with the details, the programme and the tribute wall.'],
            ],
            'timeline' => [
                'In the days before' => ['Share the order of service', 'Open the tribute wall and donations', 'Let guests say they are coming'],
                'On the day' => ['Welcome guests and keep a register', 'Keep everyone updated by message'],
                'Afterwards' => ['Thank those who gave and wrote', 'Keep the tributes for the family'],
            ],
            'value' => [
                'title' => 'Words and support, kept together',
                'text' => 'Tributes, gifts and the programme sit in one place the family can return to long after the day itself.',
                'highlight' => 'long after the day itself',
            ],
            'related' => ['communities', 'social'],
            'tags' => ['Tribute wall', 'Donations', 'Order of service'],
            'panels' => [
                'In the days before' => ['title' => 'Order of service', 'columns' => ['Time', 'Programme'], 'rows' => [
                    ['07:00', 'Filing past'],
                    ['09:00', 'Burial service'],
                    ['12:00', 'Interment'],
                    ['14:00', 'Thanksgiving reception'],
                ]],
                'On the day' => ['title' => 'Guest register', 'columns' => ['Guests', 'Expected', 'Arrived'], 'rows' => [
                    ['Family', '85', '82'],
                    ['Church', '140', '126'],
                    ['Colleagues', '60', '44'],
                    ['Friends', '55', '51'],
                ]],
                'Afterwards' => ['title' => 'Support received', 'columns' => ['Gift', 'Gifts', 'Amount'], 'rows' => [
                    ['Donations to the family', '186', ['ghs' => 48600, 'usd' => 4170]],
                    ['Memorial fund', '52', ['ghs' => 15200, 'usd' => 1300]],
                ]],
            ],
            'cta' => 'Prepare a dignified farewell on MiConvener.',
        ],

        'social' => [
            'title' => 'Social gatherings',
            'summary' => 'Reunions, parties, meetups and celebrations.',
            'eyebrow' => 'Social gatherings',
            'headline' => 'The invitation, the guest list and the door, handled',
            'intro' => 'Reunions, anniversary dinners, meetups, fundraisers and parties. Invite people, see who is coming, and welcome them at the door, free or ticketed.',
            'for' => [
                'Friends and family organising a reunion or celebration',
                'Clubs and meetup groups',
                'Fundraising committees',
                'Anyone hosting a ticketed evening',
            ],
            'features' => [
                ['tag' => 'Tickets', 'title' => 'Free or ticketed', 'text' => 'Open sign-up, or sell tickets by card or mobile money, with promo codes if you want them.'],
                ['tag' => 'Event page', 'title' => 'A page worth sharing', 'text' => 'One public page with the details, the location and a sign-up button.'],
                ['tag' => 'Passes', 'title' => 'A pass on every phone', 'text' => 'Each guest receives a QR pass and can add the event to their calendar.'],
                ['tag' => 'Reminders', 'title' => 'Reminders', 'text' => 'Automatic reminders so people remember the date and arrive on time.'],
                ['tag' => 'Check-in', 'title' => 'Check-in at the door', 'text' => 'Scan passes quickly, or give a helper a link to do it from their phone.'],
                ['tag' => 'Fundraising', 'title' => 'Fundraising built in', 'text' => 'Collect voluntary contributions toward a cause alongside the event.'],
            ],
            'timeline' => [
                'Before' => ['Create the event and share the page', 'See who is coming as sign-ups arrive'],
                'On the day' => ['Scan passes at the door', 'Welcome guests by name'],
                'Afterwards' => ['Thank everyone who came', 'Share the totals raised'],
            ],
            'value' => [
                'title' => 'Hosting, without the spreadsheet',
                'text' => 'Guests, tickets and the door are handled, so you can enjoy your own event.',
                'highlight' => 'enjoy your own event',
            ],
            'related' => ['communities', 'legacy'],
            'tags' => ['Paid tickets', 'Mobile money', 'Door scanning'],
            'panels' => [
                'Before' => ['title' => 'Tickets', 'columns' => ['Ticket', 'Price', 'Includes'], 'rows' => [
                    ['Early bird', ['ghs' => 250, 'usd' => 22], 'Dinner and drinks'],
                    ['Regular', ['ghs' => 350, 'usd' => 30], 'Dinner and drinks'],
                    ['Couple', ['ghs' => 600, 'usd' => 52], 'Two guests'],
                    ['Helpers', 'Free', 'Staff link to scan'],
                ]],
                'On the day' => ['title' => 'Arrivals', 'columns' => ['Time', 'Arrived', 'Of 214'], 'rows' => [
                    ['18:00', '24', '11%'],
                    ['18:30', '71', '33%'],
                    ['19:00', '128', '60%'],
                    ['19:30', '188', '88%'],
                ]],
                'Afterwards' => ['title' => 'Totals', 'columns' => ['Item', 'Count', 'Amount'], 'rows' => [
                    ['Tickets sold', '214', ['ghs' => 71500, 'usd' => 6120]],
                    ['Scholarship fund gifts', '63', ['ghs' => 18900, 'usd' => 1620]],
                ]],
            ],
            'cta' => 'Host your next celebration on MiConvener.',
        ],
    ],
];
