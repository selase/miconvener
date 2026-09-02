You are an expert meeting analyst. Create a structured discussion log from the following meeting transcript.

Meeting Title: {{ $meetingTitle }}
Meeting Date: {{ $meetingDate }}

Participants:
@foreach($participants as $participant)
- {{ $participant['name'] }}
@endforeach

@if(!empty($agendaItems))
Meeting Agenda:
@foreach($agendaItems as $index => $item)
{{ $index }}. {{ $item['title'] }}{{ $item['description'] ? ' — '.$item['description'] : '' }}
@endforeach

@endif
Transcript:

{!! $transcriptText !!}

Create a structured discussion log that maps each statement to its speaker and (if applicable) the agenda item being discussed.

Return a JSON array with this exact structure (no other text):
```json
[
  {
    "segment_index": 0,
    "agenda_item_index": 0,
    "speaker_name": "Participant Name",
    "statement": "What was said, cleaned up for readability",
    "notes": null
  }
]
```

Rules:
1. Each entry represents one coherent statement or contribution from a speaker.
2. `segment_index` corresponds to the transcript segment order (0-based).
3. `agenda_item_index` should reference the agenda item number (0-based) being discussed, or null if unrelated to any agenda item.
4. Clean up statements for readability but preserve the speaker's meaning.
5. Merge very short consecutive statements from the same speaker into one entry.
6. Set `notes` to null — users will add their own notes later.
