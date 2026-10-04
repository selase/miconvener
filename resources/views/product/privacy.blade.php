@extends('layouts.product')

@section('title', 'Privacy Policy - ' . config('product-page.brand.name'))

@section('content')
    @php($brand = config('product-page.brand.name'))
    <article class="mx-auto max-w-3xl px-6 pt-20 pb-24 md:pt-28 text-slate-600 text-[17px] leading-[28px] [&_h2]:mt-12 [&_h2]:mb-3 [&_h2]:text-[22px] [&_h2]:font-semibold [&_h2]:text-slate-900 [&_p]:mt-4 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6 [&_li]:mt-2">
        <h1 class="text-[40px] leading-[46px] font-bold tracking-[-0.02em] text-slate-900 md:text-[52px] md:leading-[58px]">Privacy Policy</h1>
        <p class="text-sm text-slate-400">Last updated 4 October 2026</p>

        <p>This policy explains what personal information {{ $brand }} handles, why, and the choices you have. We handle personal information in line with Ghana's Data Protection Act, 2012 (Act 843).</p>

        <h2>Two roles</h2>
        <ul>
            <li>For <strong>organizers</strong> who open a workspace, we decide how their account information is used.</li>
            <li>For <strong>attendees</strong>, the organizer of the event decides what is collected and why. We store and process that information on the organizer's behalf, to run their event. Questions about how an organizer uses your details should go to that organizer.</li>
        </ul>

        <h2>What we collect</h2>
        <ul>
            <li><strong>Account details:</strong> name, email address, phone number and organization details for organizers and their team members.</li>
            <li><strong>Registration details:</strong> what an event's registration form asks for, such as name, email address and phone number, plus ticket, check-in and session attendance records.</li>
            <li><strong>Participation:</strong> poll answers, questions, forum posts and contributions you choose to make during an event.</li>
            <li><strong>Payments:</strong> amounts, references and status. Card and mobile money details are entered with Paystack and never reach us.</li>
            <li><strong>Technical information:</strong> server logs such as IP address and browser type, kept to secure and troubleshoot the service.</li>
        </ul>

        <h2>Cookies</h2>
        <p>We use only the cookies needed to keep you signed in and to protect forms against forgery. We do not use advertising or analytics trackers.</p>

        <h2>How we use it</h2>
        <ul>
            <li>To run events: registration, tickets, check-in, emails about the event, and live engagement.</li>
            <li>To process payments, commission and payouts, and to keep the financial records the law requires.</li>
            <li>To operate, secure and support the service.</li>
        </ul>
        <p>We do not sell personal information, and we do not use attendee information for our own marketing.</p>

        <h2>Who we share it with</h2>
        <ul>
            <li><strong>The event's organizer</strong>, for the events you register for: your registration details and your activity at the event, such as check-in and requests for help.</li>
            <li><strong>Venues and suppliers on the marketplace</strong>, when you ask them for a quote or a booking: your name, contact details and what you asked for.</li>
            <li><strong>Paystack</strong>, which processes payments and payouts.</li>
            <li><strong>Our hosting, file storage and email delivery providers</strong>, which store data and send email for us and may process it outside Ghana under safeguards that protect it.</li>
            <li><strong>Authorities</strong>, where the law requires us to.</li>
        </ul>

        <h2>How long we keep it</h2>
        <p>We keep information while the organizer's account is active and as long as needed for the event it was collected for. Payment records are kept as long as financial law requires. Database backups are kept for up to twelve months before they are deleted.</p>

        <h2>Security</h2>
        <p>Data is sent over encrypted connections, each organizer's information is kept separate from other organizers', and access within a workspace follows the roles its owner assigns.</p>

        <h2>Your rights</h2>
        <p>You may ask to see the personal information held about you, to correct it, or to have it deleted where it is no longer needed. Attendees should contact the event's organizer first; we will help organizers respond. You may also complain to Ghana's Data Protection Commission.</p>

        <h2>Children</h2>
        <p>{{ $brand }} is not directed at children. An organizer running an event for under-18s is responsible for obtaining the consent the law requires.</p>

        <h2>Changes to this policy</h2>
        <p>We will post any changes here with a new date.</p>

        <h2>Contact</h2>
        <p>Privacy questions and requests can be sent through our <a href="{{ route('product.enterprise') }}" class="underline hover:text-slate-900">contact form</a>. Read this policy together with our <a href="{{ route('terms') }}" class="underline hover:text-slate-900">Terms of Service</a>.</p>
    </article>
@endsection
