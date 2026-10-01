import { useState, useRef, useEffect } from 'react';
import {
    Sparkles,
    X,
    Send,
    Bot,
    User,
    Building2,
    MapPin,
    ArrowRight,
    Loader2,
    ShieldCheck,
} from 'lucide-react';

export default function AiConciergeDrawer({ isOpen, onClose }) {
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [messages, setMessages] = useState([
        {
            role: 'assistant',
            text: 'Hello! I am your MiConvener Venue Concierge. Tell me about your event (attendee count, preferred city, required power or AV, and budget) and I will scout the best verified spaces for you.',
            venues: [],
        },
    ]);

    const messagesEndRef = useRef(null);

    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };

    useEffect(() => {
        if (isOpen) {
            scrollToBottom();
        }
    }, [isOpen, messages]);

    if (!isOpen) return null;

    const quickPrompts = [
        'Executive boardroom with ocean view in Accra',
        'Large ballroom for 300+ guests with standby generator',
        'Auditorium plenary hall in Kumasi',
        'Beachfront venue for cocktail reception',
    ];

    const sendMessage = async (promptText) => {
        const textToSend = promptText || input;
        if (!textToSend.trim() || loading) return;

        const userMessage = { role: 'user', text: textToSend };
        setMessages((prev) => [...prev, userMessage]);
        setInput('');
        setLoading(true);

        try {
            // Call the MiConvener Marketplace MCP JSON-RPC server directly
            const response = await fetch('/mcp/marketplace', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    jsonrpc: '2.0',
                    id: Date.now(),
                    method: 'tools/call',
                    params: {
                        name: 'search_venues',
                        arguments: {
                            query: textToSend,
                            limit: 5,
                        },
                    },
                }),
            });

            if (!response.ok) {
                throw new Error(`MCP Server responded with status ${response.status}`);
            }

            const data = await response.json();

            if (data?.error) {
                throw new Error(data.error.message || 'JSON-RPC error');
            }

            if (data?.result?.isError) {
                const errorMessage = data?.result?.content?.[0]?.text || 'Error processing inquiry';
                setMessages((prev) => [
                    ...prev,
                    {
                        role: 'assistant',
                        text: `Notice: ${errorMessage}`,
                        venues: [],
                    },
                ]);
                return;
            }

            const rawContent = data?.result?.content?.[0]?.text;
            const parsed = rawContent ? JSON.parse(rawContent) : null;
            const matchedVenues = parsed?.venues || [];

            let replyText = '';
            if (matchedVenues.length > 0) {
                replyText = `Found ${parsed.total_found} verified space${parsed.total_found === 1 ? '' : 's'} matching your requirements. Here are the top recommendations:`;
            } else {
                replyText =
                    "I couldn't find any published spaces strictly matching all those keywords. Try adjusting your capacity, city, or required amenities.";
            }

            setMessages((prev) => [
                ...prev,
                {
                    role: 'assistant',
                    text: replyText,
                    venues: matchedVenues,
                },
            ]);
        } catch (error) {
            console.error('MCP concierge error:', error);
            setMessages((prev) => [
                ...prev,
                {
                    role: 'assistant',
                    text: 'Sorry, I encountered a temporary connection issue while searching venue spaces. Please try again or browse the catalog directly.',
                    venues: [],
                },
            ]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 overflow-hidden">
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-black/50 backdrop-blur-xs transition-opacity"
                onClick={onClose}
            />

            <div className="fixed inset-y-0 right-0 flex max-w-full pl-10">
                <div className="w-screen max-w-md border-l border-border bg-surface shadow-2xl flex flex-col">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-border px-5 py-4 bg-gradient-to-r from-accent/10 via-purple-500/10 to-transparent">
                        <div className="flex items-center gap-2.5">
                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-white shadow-xs">
                                <Sparkles className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="text-sm font-semibold text-ink flex items-center gap-1.5">
                                    AI Venue Concierge
                                    <span className="rounded-full bg-accent/20 px-2 py-0.5 text-[10px] font-medium text-accent">
                                        MCP Powered
                                    </span>
                                </h3>
                                <p className="text-[11px] text-ink-secondary">
                                    Natural language venue matching & recommendations
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-1.5 text-ink-tertiary hover:bg-canvas hover:text-ink transition-colors"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>

                    {/* Messages Body */}
                    <div className="flex-1 overflow-y-auto p-4 space-y-4">
                        {messages.map((msg, index) => (
                            <div
                                key={index}
                                className={`flex gap-3 ${
                                    msg.role === 'user' ? 'justify-end' : 'justify-start'
                                }`}
                            >
                                {msg.role === 'assistant' && (
                                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent/10 text-accent">
                                        <Bot className="h-4 w-4" />
                                    </div>
                                )}

                                <div
                                    className={`max-w-[85%] rounded-2xl px-4 py-3 text-xs leading-relaxed ${
                                        msg.role === 'user'
                                            ? 'bg-accent text-white rounded-tr-none'
                                            : 'bg-canvas border border-border text-ink rounded-tl-none'
                                    }`}
                                >
                                    <p className="whitespace-pre-wrap">{msg.text}</p>

                                    {/* Venue Cards in Message */}
                                    {msg.venues && msg.venues.length > 0 && (
                                        <div className="mt-3 space-y-2">
                                            {msg.venues.map((venue) => (
                                                <div
                                                    key={venue.id}
                                                    className="rounded-xl border border-border bg-surface p-3 text-left shadow-xs hover:border-accent/50 transition-colors"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <h4 className="font-semibold text-ink line-clamp-1">
                                                            {venue.title}
                                                        </h4>
                                                        {venue.similarity_match && (
                                                            <span className="shrink-0 rounded-full bg-purple-500/10 border border-purple-500/20 px-2 py-0.5 text-[10px] font-semibold text-purple-600 dark:text-purple-400">
                                                                {venue.similarity_match} match
                                                            </span>
                                                        )}
                                                    </div>

                                                    <p className="mt-0.5 text-[11px] text-ink-secondary flex items-center gap-1">
                                                        <Building2 className="h-3 w-3 shrink-0" />
                                                        <span>{venue.host?.name}</span>
                                                        {venue.host?.is_verified && (
                                                            <ShieldCheck className="h-3 w-3 text-accent shrink-0" />
                                                        )}
                                                    </p>

                                                    <div className="mt-2 flex items-center justify-between text-[11px]">
                                                        <span className="font-medium text-ink">
                                                            {venue.pricing?.formatted}
                                                        </span>
                                                        <a
                                                            href={venue.url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex items-center gap-1 font-semibold text-accent hover:underline"
                                                        >
                                                            View Space
                                                            <ArrowRight className="h-3 w-3" />
                                                        </a>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                {msg.role === 'user' && (
                                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-border text-ink-secondary">
                                        <User className="h-4 w-4" />
                                    </div>
                                )}
                            </div>
                        ))}

                        {loading && (
                            <div className="flex gap-3 justify-start items-center text-ink-secondary text-xs">
                                <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent/10 text-accent animate-pulse">
                                    <Bot className="h-4 w-4" />
                                </div>
                                <div className="inline-flex items-center gap-2 rounded-2xl bg-canvas border border-border px-4 py-2.5">
                                    <Loader2 className="h-3.5 w-3.5 animate-spin text-accent" />
                                    <span>Scouting venue spaces via MCP...</span>
                                </div>
                            </div>
                        )}

                        <div ref={messagesEndRef} />
                    </div>

                    {/* Quick Suggestions */}
                    {messages.length <= 2 && (
                        <div className="border-t border-border px-4 py-2.5 bg-canvas/50">
                            <p className="text-[10px] font-medium text-ink-tertiary mb-1.5 uppercase tracking-wider">
                                Suggested inquiries
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                {quickPrompts.map((prompt, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        onClick={() => sendMessage(prompt)}
                                        className="rounded-full border border-border bg-surface px-2.5 py-1 text-[11px] text-ink hover:border-accent hover:text-accent transition-colors"
                                    >
                                        {prompt}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Input Footer */}
                    <div className="border-t border-border p-3 bg-surface">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                sendMessage();
                            }}
                            className="flex items-center gap-2"
                        >
                            <input
                                type="text"
                                placeholder="Describe your event needs..."
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                disabled={loading}
                                className="flex-1 rounded-xl border border-border bg-canvas px-3.5 py-2.5 text-xs text-ink placeholder:text-ink-tertiary focus:border-accent focus:ring-1 focus:ring-accent disabled:opacity-50"
                            />
                            <button
                                type="submit"
                                disabled={!input.trim() || loading}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-accent text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                            >
                                <Send className="h-4 w-4" />
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    );
}
