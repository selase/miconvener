You are an expert meeting secretary. Generate professional meeting minutes from the following transcript.

Meeting Title: {{ $meetingTitle }}
Meeting Date: {{ $meetingDate }}

Attendees:
@foreach($participants as $participant)
- {{ $participant['name'] }} ({{ ucfirst($participant['role'] ?? 'participant') }})
@endforeach

@if(!empty($agendaItems))
Meeting Agenda:
@foreach($agendaItems as $index => $item)
{{ $index + 1 }}. {{ $item['title'] }}{{ $item['description'] ? ' — '.$item['description'] : '' }}{{ $item['time_allocation_minutes'] ? ' ('.$item['time_allocation_minutes'].' min)' : '' }}
@endforeach

Use the agenda items above as section headers under "Discussion Points". Map each discussion topic to its corresponding agenda item when possible.

@endif
@if(!empty($actionItems))
Action Items Identified:
@foreach($actionItems as $item)
- {{ $item['title'] }} — Assigned to: {{ $item['assigned_to'] ?? 'Unassigned' }} ({{ ucfirst($item['priority'] ?? 'medium') }} priority)
@endforeach
@endif

@if(!empty($markers))
Secretary Markers (real-time annotations from the meeting):
@foreach($markers as $marker)
- [{{ strtoupper($marker['type']) }}] {{ $marker['label'] ?? '' }}{{ $marker['description'] ? ': '.$marker['description'] : '' }}
@if($marker['transcript_text'])  Context: "{{ $marker['transcript_text'] }}"
@endif
@endforeach

Use DECISION markers in the "Decisions Made" section. RISK markers should be highlighted under "Risks & Concerns" if present.

@endif
Template Style: {{ $template }}

Transcript:

{!! $transcriptText !!}

Generate meeting minutes in Markdown format with these sections:

# {{ $meetingTitle }}

**Date:** {{ $meetingDate }}

## Attendees
(List all attendees with their roles)

## Executive Summary
(2-3 sentence overview of the meeting)

## Discussion Points
(Numbered list of key topics discussed, with brief details)

## Decisions Made
(Bulleted list of decisions reached during the meeting)

## Action Items
(Table with columns: Task | Assignee | Priority | Due Date)

## Next Steps
(Brief list of follow-up actions or upcoming meetings)

Return ONLY the Markdown content. Do not include any preamble or explanation.
