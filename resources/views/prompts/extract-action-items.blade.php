You are an expert meeting analyst. Extract all action items from the following meeting transcript.

Meeting Title: {{ $meetingTitle }}
Meeting Date: {{ $meetingDate }}

Participants:
@foreach($participants as $participant)
- {{ $participant['name'] }}
@endforeach

@if(!empty($agendaItems))
Meeting Agenda:
@foreach($agendaItems as $index => $item)
{{ $index + 1 }}. {{ $item['title'] }}{{ $item['description'] ? ' — '.$item['description'] : '' }}
@endforeach

When extracting action items, reference which agenda item (by number) each action relates to if applicable.

@endif
@if(!empty($markers))
Secretary Markers (annotations placed by the secretary during the meeting):
@foreach($markers as $marker)
- [{{ strtoupper($marker['type']) }}] {{ $marker['label'] ?? 'No label' }}{{ $marker['description'] ? ': '.$marker['description'] : '' }}
@if($marker['transcript_text'])  Related transcript: "{{ $marker['transcript_text'] }}"
@endif
@endforeach

IMPORTANT: Segments marked as ACTION or DECISION by the secretary should be given higher weight during extraction. These are strong signals for action item identification.

@endif
Instructions:
1. Extract ONLY clear commitments, assignments, or tasks mentioned in the transcript.
2. Do not invent action items that are not explicitly stated or implied.
3. Match the assigned person to one of the participant names listed above when possible.
4. Set priority based on urgency cues (e.g., "ASAP" = high, "when you get a chance" = low).
5. Extract the relevant source text that supports each action item.
6. Estimate due dates only if explicitly mentioned; otherwise set to null.

Transcript:

{!! $transcriptText !!}

Return a JSON array of action items with this exact structure (no other text):
```json
[
  {
    "title": "Brief action item title",
    "description": "Detailed description of what needs to be done",
    "assigned_to_name": "Participant Name or null",
    "source_text": "The exact quote from the transcript",
    "priority": "low|medium|high|critical",
    "due_date": "YYYY-MM-DD or null"
  }
]
```
