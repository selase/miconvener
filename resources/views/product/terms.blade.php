@extends('layouts.product')

@section('title', 'Terms of Service - ' . config('product-page.brand.name'))

@section('content')
    @php($brand = config('product-page.brand.name'))
    <article class="mx-auto max-w-3xl px-6 pt-20 pb-24 md:pt-28 text-slate-600 text-[17px] leading-[28px] [&_h2]:mt-12 [&_h2]:mb-3 [&_h2]:text-[22px] [&_h2]:font-semibold [&_h2]:text-slate-900 [&_p]:mt-4 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6 [&_li]:mt-2">
        <h1 class="text-[40px] leading-[46px] font-bold tracking-[-0.02em] text-slate-900 md:text-[52px] md:leading-[58px]">Terms of Service</h1>
        <p class="text-sm text-slate-400">Last updated 4 October 2026</p>

        <p>These terms govern your use of {{ $brand }}, the event registration, ticketing and engagement platform at {{ parse_url(config('app.url'), PHP_URL_HOST) }}. By creating an account or registering for an event, you agree to them.</p>

        <h2>Who these terms cover</h2>
        <p><strong>Organizers</strong> are the organizations that open a {{ $brand }} workspace to run events. <strong>Attendees</strong> are the people who register for, buy tickets to, or take part in those events. Each event is run by its organizer, not by {{ $brand }}.</p>

        <h2>Your account</h2>
        <ul>
            <li>You must give accurate details and keep your sign-in credentials private. You are responsible for what happens under your account.</li>
            <li>An organizer is responsible for the team members it invites and the permissions it gives them.</li>
        </ul>

        <h2>Organizers and their events</h2>
        <ul>
            <li>The organizer is responsible for its events: what is promised to attendees, whether the event takes place, and its refund policy.</li>
            <li>The organizer decides what it collects from attendees and must have a lawful reason to collect it. Our <a href="{{ route('privacy') }}" class="underline hover:text-slate-900">Privacy Policy</a> explains how we handle that information on the organizer's behalf.</li>
            <li>Questions about an event, a ticket or a refund go to the organizer first.</li>
        </ul>

        <h2>Payments, commission and payouts</h2>
        <ul>
            <li>Ticket payments and contributions are processed by Paystack. Card and mobile money details go to Paystack, never to us.</li>
            <li>We charge a commission on paid tickets at the rate of the organizer's plan, up to the cap per ticket shown on our <a href="{{ route('home') }}#pricing" class="underline hover:text-slate-900">pricing</a>, or at a rate agreed in writing. Payment processing and transfer fees are passed on at cost.</li>
            <li>The organizer chooses for each event whether it or the attendee bears these fees.</li>
            <li>Ticket income, less commission and fees, is paid out to the organizer's account. Refunds to attendees are made from the organizer's funds.</li>
        </ul>

        <h2>Plans, renewals and add-ons</h2>
        <ul>
            <li>Paid plans are billed monthly or yearly in advance, at the prices on our pricing page when they renew.</li>
            <li>A plan paid by a saved card renews automatically. A plan paid by mobile money renews when you pay the link we send before the renewal date.</li>
            <li>If a renewal is not paid, the workspace has a 7-day grace period and then moves to the Free plan. Your data is kept, and events already live keep running until they end.</li>
            <li>Add-ons are billed as shown when you buy them. A recurring add-on can be cancelled at any time and stays active until the end of the period already paid for.</li>
            <li>Payments for plans and add-ons are not refunded for part of a period, unless the law requires it.</li>
        </ul>

        <h2>Acceptable use</h2>
        <p>You may not use {{ $brand }} to run fraudulent or unlawful events, to send unsolicited messages, to collect data you have no right to, or to interfere with the service or other users. We may suspend an event or account that does, and we may withhold payouts connected to fraud while it is investigated.</p>

        <h2>Your content</h2>
        <p>You keep ownership of what you upload: event descriptions, images, materials and attendee data. You give us permission to store and display it only as needed to run the service for you.</p>

        <h2>Availability</h2>
        <p>We work to keep {{ $brand }} running and your data backed up, but the service is provided as it is, without a guarantee of uninterrupted availability. We may change or improve features over time.</p>

        <h2>Liability</h2>
        <p>To the extent the law allows, we are not liable for indirect or consequential losses, or for the conduct of organizers or attendees. Our total liability to an organizer is limited to the fees it paid us in the twelve months before the claim.</p>

        <h2>Ending your use</h2>
        <p>You can stop using {{ $brand }} at any time. We may end an account that breaks these terms. Money owed to an organizer for events already held is still paid out.</p>

        <h2>Changes to these terms</h2>
        <p>We will post any changes here with a new date, and tell organizers about significant changes before they take effect.</p>

        <h2>Governing law</h2>
        <p>These terms are governed by the laws of the Republic of Ghana.</p>

        <h2>Contact</h2>
        <p>Questions about these terms can be sent through our <a href="{{ route('product.enterprise') }}" class="underline hover:text-slate-900">contact form</a>.</p>
    </article>
@endsection
