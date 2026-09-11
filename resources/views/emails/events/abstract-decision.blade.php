<x-mail::message>
# Scientific Abstract Decision Notification

Dear Authors,

Thank you for your submission to **{{ $event->title }}**. The Scientific Committee and peer review panel have concluded the evaluation of your abstract.

**Title:** {{ $abstract->title }}  
**Abstract Code:** {{ $abstract->code }}  
**Track:** {{ $abstract->track ?? 'General Track' }}  
**Final Decision:** **{{ strtoupper(str_replace('_', ' ', $abstract->status)) }}**

@if($notes)
### Committee Notes & Feedback:
> {{ $notes }}
@endif

@if($abstract->isAccepted())
Congratulations on your acceptance! Our programme committee will be in touch shortly regarding session scheduling, presentation specifications, and slide deck submission deadlines.
@else
We received a large number of high-calibre submissions this year and regret that we cannot include your abstract in this edition's scientific programme. We encourage you to participate as a delegate and submit again in future meetings.
@endif

<x-mail::button :url="route('public.events.abstracts.show', ['subdomain' => request()->route('subdomain') ?? 'app', 'event' => $event->slug, 'code' => $abstract->code])">
View Abstract & Timeline
</x-mail::button>

Sincerely,  
**The Scientific Programme Committee**  
{{ $event->title }}
</x-mail::message>
