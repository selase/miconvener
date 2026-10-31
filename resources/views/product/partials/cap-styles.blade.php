{{-- Card grid (.cap) shared by the homepage community cards and the Full features page. --}}
@once
    @push('styles')
    <style>
        .cap { display: grid; gap: 1px; background: var(--rule); border: 1px solid var(--rule); border-radius: 12px; overflow: hidden; grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 900px) { .cap { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 580px) { .cap { grid-template-columns: 1fr; } }
        .cap > div { background: var(--surface); padding: 1.45rem 1.35rem; }
        .cap h3, .cap h4 { font-weight: 500; font-size: 1rem; }
        .cap p { color: var(--ink-2); font-size: .92rem; margin-top: .3rem; }
    </style>
    @endpush
@endonce
