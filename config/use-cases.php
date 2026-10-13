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
                ['title' => 'Call for abstracts', 'text' => 'Authors submit abstracts online, reviewers score them, and authors are emailed the decision.'],
                ['title' => 'A programme people can follow', 'text' => 'Sessions, tracks and speakers on a public event page, with times and rooms.'],
                ['title' => 'Registration and tickets', 'text' => 'Free or paid registration, ticket types for students, members and delegates, and promo codes.'],
                ['title' => 'Vivas and defences as sessions', 'text' => 'Schedule each defence or viva as a timed session with its panel and room, and share the timetable.'],
                ['title' => 'Attendance and certificates', 'text' => 'Check people in to the event and to individual sessions, then issue certificates of attendance.'],
                ['title' => 'Speaker materials', 'text' => 'Collect slides and papers and release them to attendees when you choose.'],
            ],
            'timeline' => [
                'Before' => ['Open the call for abstracts', 'Review submissions and notify authors', 'Publish the programme and open registration'],
                'On the day' => ['Print name badges and check people in', 'Record attendance session by session'],
                'Afterwards' => ['Issue certificates', 'Release speaker materials', 'Thank everyone by email'],
            ],
            'value' => [
                'title' => 'One record from submission to certificate',
                'text' => 'Authors, reviewers, speakers and delegates live in one system, so the programme, the attendance register and the certificates always agree.',
            ],
            'related' => ['classroom', 'international'],
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
                ['title' => 'Delegations and groups', 'text' => 'Group attendees by country, branch or delegation so you can see and address each one.'],
                ['title' => 'A ticket type for every attendee', 'text' => 'Delegates, observers, speakers and guests each get their own ticket type, price and rules.'],
                ['title' => 'Programmes across days and tracks', 'text' => 'Parallel sessions, speakers and rooms laid out clearly on one public page.'],
                ['title' => 'Sponsors and what they were promised', 'text' => 'Record sponsors and their deliverables, and keep track of what has been delivered.'],
                ['title' => 'Badges and accreditation', 'text' => 'Printed name badges and QR passes, checked at the door and at each session.'],
                ['title' => 'Announcements that reach everyone', 'text' => 'Email and text updates to all attendees or to a chosen audience.'],
            ],
            'timeline' => [
                'Before' => ['Open registration with the right ticket types', 'Group delegations and confirm sponsors', 'Send joining instructions'],
                'On the day' => ['Scan passes at the door and at sessions', 'Send updates by email and text as plans change'],
                'Afterwards' => ['Share materials and certificates', 'Report on attendance and delegations'],
            ],
            'value' => [
                'title' => 'Calm at the registration desk',
                'text' => 'When hundreds of people arrive from many places at once, one accurate list is what keeps the day moving.',
            ],
            'related' => ['delegates', 'academic'],
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
                ['title' => 'Registration with approval', 'text' => 'Require approval so only accredited delegates are confirmed, and tell everyone the outcome.'],
                ['title' => 'Paying delegates', 'text' => 'Delegate fees by card or mobile money, with promo codes for sponsored or subsidised delegates.'],
                ['title' => 'Branches and regions', 'text' => 'Group delegates by branch or region and see who has registered from each.'],
                ['title' => 'Credentials at the door', 'text' => 'QR passes and printed badges, scanned by ushers on their own phones.'],
                ['title' => 'Staff links for ushers', 'text' => 'Give ushers and floor crew a link, with no account needed, to scan passes and answer requests.'],
                ['title' => 'Clear money records', 'text' => 'Every payment, fee and payout on one statement for the treasurer.'],
            ],
            'timeline' => [
                'Before' => ['Open registration and approve delegates', 'Group delegates by branch', 'Send passes by email'],
                'On the day' => ['Scan credentials at the door', 'Ushers handle requests from their phones'],
                'Afterwards' => ['Export the attendance register', 'Reconcile fees on one statement'],
            ],
            'value' => [
                'title' => 'Only the right people, and every fee accounted for',
                'text' => 'Approval keeps the floor to accredited delegates, and the statement keeps the treasurer confident that every cedi is recorded.',
            ],
            'related' => ['international', 'communities'],
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
                ['title' => 'Live polls', 'text' => 'Put a question to the room and show the results as people answer.'],
                ['title' => 'Audience questions', 'text' => 'People send questions from their phones, and a moderator chooses what goes on screen.'],
                ['title' => 'A presenter deck', 'text' => 'Run polls and questions from a single presenter view while you teach.'],
                ['title' => 'Session attendance', 'text' => 'Record who attended which session, for credit or for your records.'],
                ['title' => 'Certificates', 'text' => 'Issue certificates of attendance when the session ends.'],
                ['title' => 'Materials after the session', 'text' => 'Release slides and handouts to attendees when you are ready.'],
            ],
            'timeline' => [
                'Before' => ['Register participants', 'Prepare polls in the presenter deck'],
                'On the day' => ['Run polls and take moderated questions', 'Record attendance for each session'],
                'Afterwards' => ['Issue certificates', 'Share materials'],
            ],
            'value' => [
                'title' => 'A room that answers back',
                'text' => 'Polls and questions turn a talk into a conversation, and quiet voices get heard as readily as loud ones.',
            ],
            'note' => 'Live polling and Q&A is included from the Growth plan, and can be added to other plans by the month or for a single event.',
            'related' => ['academic', 'social'],
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
                ['title' => 'Recurring events', 'text' => 'Daily, weekly or monthly gatherings that appear on their own, so you never rebuild the same event.'],
                ['title' => 'Reminders and notifications', 'text' => 'Automatic reminders before each occurrence, without anyone having to remember to send them.'],
                ['title' => 'Messages to members', 'text' => 'Announcements by email and SMS to everyone, or to a chosen group.'],
                ['title' => 'Donations and offerings', 'text' => 'Collect voluntary contributions by card or mobile money, with a receipt for every gift.'],
                ['title' => 'Groups', 'text' => 'Organise people into groups such as branches, classes or committees.'],
                ['title' => 'Simple attendance', 'text' => 'Check people in with a pass on their phone, or let them check themselves in.'],
            ],
            'timeline' => [
                'Once' => ['Create the recurring event and its reminders', 'Invite your members'],
                'Every time' => ['Reminders go out on schedule', 'People sign up and check in'],
                'Afterwards' => ['Thank-you messages', 'Receipts for donations'],
            ],
            'value' => [
                'title' => 'Less chasing, more gathering',
                'text' => 'The reminders, the sign-ups and the receipts happen by themselves, so the people who run the group can spend their time with its members.',
            ],
            'related' => ['legacy', 'social'],
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
                ['title' => 'Order of service', 'text' => 'Publish the programme for the day, with the sessions in their proper order, for everyone to follow.'],
                ['title' => 'Tributes and condolences', 'text' => 'A wall where friends and family leave words of remembrance, moderated by the family.'],
                ['title' => 'Donations and support', 'text' => 'Receive gifts toward the family or a memorial fund by card or mobile money, with a receipt for each.'],
                ['title' => 'Messages to those who cannot travel', 'text' => 'Share the programme and updates by email and text with everyone who wants to be kept informed.'],
                ['title' => 'Who is coming', 'text' => 'Let guests say they are attending, so the family can plan the day with confidence.'],
                ['title' => 'One link to share', 'text' => 'A single public page with the details, the programme and the tribute wall.'],
            ],
            'timeline' => [
                'In the days before' => ['Share the order of service', 'Open the tribute wall and donations', 'Let guests say they are coming'],
                'On the day' => ['Welcome guests and keep a register', 'Keep everyone updated by message'],
                'Afterwards' => ['Thank those who gave and wrote', 'Keep the tributes for the family'],
            ],
            'value' => [
                'title' => 'Words and support, kept together',
                'text' => 'Tributes, gifts and the programme sit in one place the family can return to long after the day itself.',
            ],
            'related' => ['communities', 'social'],
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
                ['title' => 'Free or ticketed', 'text' => 'Open sign-up, or sell tickets by card or mobile money, with promo codes if you want them.'],
                ['title' => 'A page worth sharing', 'text' => 'One public page with the details, the location and a sign-up button.'],
                ['title' => 'A pass on every phone', 'text' => 'Each guest receives a QR pass and can add the event to their calendar.'],
                ['title' => 'Reminders', 'text' => 'Automatic reminders so people remember the date and arrive on time.'],
                ['title' => 'Check-in at the door', 'text' => 'Scan passes quickly, or give a helper a link to do it from their phone.'],
                ['title' => 'Fundraising built in', 'text' => 'Collect voluntary contributions toward a cause alongside the event.'],
            ],
            'timeline' => [
                'Before' => ['Create the event and share the page', 'See who is coming as sign-ups arrive'],
                'On the day' => ['Scan passes at the door', 'Welcome guests by name'],
                'Afterwards' => ['Thank everyone who came', 'Share the totals raised'],
            ],
            'value' => [
                'title' => 'Hosting, without the spreadsheet',
                'text' => 'Guests, tickets and the door are handled, so you can enjoy your own event.',
            ],
            'related' => ['communities', 'legacy'],
        ],
    ],
];
