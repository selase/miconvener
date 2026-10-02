import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import {
    Sparkles,
    Terminal,
    Copy,
    Check,
    Play,
    Bot,
    ShieldCheck,
    Cpu,
    BookOpen,
    Code2,
    ArrowRight,
    Loader2,
    Key,
} from 'lucide-react';

export default function McpIndex({ server, partner = null, tools = [] }) {
    const [activeTab, setActiveTab] = useState('playground'); // 'playground' | 'docs' | 'connect'
    const [selectedToolIndex, setSelectedToolIndex] = useState(0);
    const [payloadArgs, setPayloadArgs] = useState(
        JSON.stringify(tools[0]?.example || {}, null, 2)
    );
    const [apiKey, setApiKey] = useState('');
    const [loading, setLoading] = useState(false);
    const [responseOutput, setResponseOutput] = useState(null);
    const [copiedUrl, setCopiedUrl] = useState(false);
    const [copiedConfig, setCopiedConfig] = useState(false);

    const selectedTool = tools[selectedToolIndex] || tools[0];

    const handleSelectTool = (idx) => {
        setSelectedToolIndex(idx);
        setPayloadArgs(JSON.stringify(tools[idx]?.example || {}, null, 2));
        setResponseOutput(null);
    };

    const handleCopyUrl = () => {
        navigator.clipboard.writeText(server.endpoint);
        setCopiedUrl(true);
        setTimeout(() => setCopiedUrl(false), 2000);
    };

    const claudeDesktopConfig = JSON.stringify(
        {
            mcpServers: {
                miconvener_marketplace: {
                    url: server.endpoint,
                },
            },
        },
        null,
        2
    );

    const handleCopyConfig = () => {
        navigator.clipboard.writeText(claudeDesktopConfig);
        setCopiedConfig(true);
        setTimeout(() => setCopiedConfig(false), 2000);
    };

    const handleExecuteRpc = async (e) => {
        e.preventDefault();
        setLoading(true);
        setResponseOutput(null);

        let parsedArgs = {};
        try {
            parsedArgs = JSON.parse(payloadArgs);
        } catch (err) {
            setResponseOutput({
                error: `Invalid JSON in arguments: ${err.message}`,
            });
            setLoading(false);
            return;
        }

        const rpcPayload = {
            jsonrpc: '2.0',
            id: Date.now(),
            method: 'tools/call',
            params: {
                name: selectedTool.name,
                arguments: parsedArgs,
            },
        };

        const startTime = performance.now();

        const reqHeaders = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        if (apiKey.trim()) {
            reqHeaders['X-Api-Key'] = apiKey.trim();
        }

        try {
            const res = await fetch(server.endpoint, {
                method: 'POST',
                headers: reqHeaders,
                body: JSON.stringify(rpcPayload),
            });

            const elapsed = Math.round(performance.now() - startTime);
            const data = await res.json();
            const limitHeader = res.headers.get('X-RateLimit-Limit');
            const remainingHeader = res.headers.get('X-RateLimit-Remaining');

            setResponseOutput({
                http_status: res.status,
                latency_ms: elapsed,
                rate_limit: limitHeader ? `${remainingHeader} / ${limitHeader} req/min remaining` : null,
                data,
            });
        } catch (err) {
            setResponseOutput({
                error: `Network / Request Error: ${err.message}`,
            });
        } finally {
            setLoading(false);
        }
    };

    return (
        <MarketplaceLayout>
            <Head title="Model Context Protocol (MCP) Server — MiConvener Marketplace" />

            <div className="bg-canvas py-10 sm:py-16">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    {/* Header Banner */}
                    <div className="rounded-2xl border border-border bg-surface p-6 sm:p-10 shadow-sm relative overflow-hidden">
                        <div className="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-accent/5 blur-3xl pointer-events-none" />

                        <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
                            <div>
                                <div className="inline-flex items-center gap-2 rounded-full border border-purple-500/20 bg-purple-500/10 px-3 py-1 text-xs font-semibold text-purple-600 dark:text-purple-400 mb-4">
                                    <Sparkles className="h-3.5 w-3.5" />
                                    <span>Model Context Protocol (MCP) Server</span>
                                    <span className="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                                    <span className="text-emerald-600 dark:text-emerald-400 font-medium">
                                        Online
                                    </span>
                                </div>

                                <h1 className="text-2xl sm:text-4xl font-bold tracking-tight text-ink">
                                    {server.name}
                                </h1>
                                <p className="mt-2 text-sm sm:text-base text-ink-secondary max-w-3xl">
                                    {server.description}
                                </p>

                                <div className="mt-4 flex flex-wrap items-center gap-4 text-xs text-ink-tertiary">
                                    <span className="flex items-center gap-1.5">
                                        <Cpu className="h-4 w-4 text-accent" />
                                        <span>Protocol: {server.protocol}</span>
                                    </span>
                                    <span>•</span>
                                    <span className="flex items-center gap-1.5">
                                        <ShieldCheck className="h-4 w-4 text-emerald-600" />
                                        <span>Rate Limit: {server.rate_limit}</span>
                                    </span>
                                    <span>•</span>
                                    <span>Version: {server.version}</span>
                                </div>
                            </div>

                            {/* Endpoint Card */}
                            <div className="flex flex-col gap-2 rounded-xl border border-border bg-canvas p-4 sm:w-96 shrink-0">
                                <span className="text-[11px] font-semibold text-ink-secondary uppercase tracking-wider">
                                    Streamable HTTP Endpoint (POST)
                                </span>
                                <div className="flex items-center justify-between rounded-lg border border-border/80 bg-surface px-3 py-2 text-xs font-mono text-ink">
                                    <span className="truncate">{server.endpoint}</span>
                                    <button
                                        type="button"
                                        onClick={handleCopyUrl}
                                        className="ml-2 text-ink-tertiary hover:text-ink cursor-pointer"
                                        title="Copy Endpoint URL"
                                    >
                                        {copiedUrl ? (
                                            <Check className="h-4 w-4 text-emerald-600" />
                                        ) : (
                                            <Copy className="h-4 w-4" />
                                        )}
                                    </button>
                                </div>
                                <div className="flex items-center justify-between pt-1 text-[11px] text-ink-tertiary">
                                    <span>Accepts: application/json</span>
                                    <Link
                                        href="/marketplace/venues"
                                        className="inline-flex items-center gap-1 text-accent font-medium hover:underline"
                                    >
                                        <span>Browse Venues</span>
                                        <ArrowRight className="h-3 w-3" />
                                    </Link>
                                </div>
                            </div>
                        </div>

                        {/* Navigation Tabs */}
                        <div className="mt-8 flex items-center gap-2 border-b border-border pb-px">
                            <button
                                type="button"
                                onClick={() => setActiveTab('playground')}
                                className={`inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-xs font-semibold transition-colors cursor-pointer ${
                                    activeTab === 'playground'
                                        ? 'border-accent text-accent'
                                        : 'border-transparent text-ink-secondary hover:text-ink'
                                }`}
                            >
                                <Terminal className="h-4 w-4" />
                                <span>Interactive RPC Playground</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('docs')}
                                className={`inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-xs font-semibold transition-colors cursor-pointer ${
                                    activeTab === 'docs'
                                        ? 'border-accent text-accent'
                                        : 'border-transparent text-ink-secondary hover:text-ink'
                                }`}
                            >
                                <BookOpen className="h-4 w-4" />
                                <span>Tools Reference ({tools.length})</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('connect')}
                                className={`inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-xs font-semibold transition-colors cursor-pointer ${
                                    activeTab === 'connect'
                                        ? 'border-accent text-accent'
                                        : 'border-transparent text-ink-secondary hover:text-ink'
                                }`}
                            >
                                <Bot className="h-4 w-4" />
                                <span>Connect AI Agents (Claude / Cursor)</span>
                            </button>
                        </div>
                    </div>

                    {/* Tab Contents */}
                    <div className="mt-8">
                        {/* Tab 1: Playground */}
                        {activeTab === 'playground' && (
                            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                                {/* Left Request Panel */}
                                <div className="lg:col-span-6 space-y-4">
                                    <div className="rounded-xl border border-border bg-surface p-5 shadow-xs">
                                        <div className="flex items-center justify-between mb-4">
                                            <label className="text-xs font-semibold text-ink uppercase tracking-wider">
                                                Select MCP Tool
                                            </label>
                                            <span className="rounded bg-accent/10 px-2 py-0.5 text-[11px] font-mono font-medium text-accent">
                                                tools/call
                                            </span>
                                        </div>

                                        <div className="grid grid-cols-2 gap-2 mb-4">
                                            {tools.map((t, idx) => (
                                                <button
                                                    key={t.name}
                                                    type="button"
                                                    onClick={() => handleSelectTool(idx)}
                                                    className={`rounded-lg border p-2.5 text-left text-xs transition-all cursor-pointer ${
                                                        selectedToolIndex === idx
                                                            ? 'border-accent bg-accent/5 font-semibold text-accent shadow-xs'
                                                            : 'border-border bg-canvas text-ink-secondary hover:text-ink'
                                                    }`}
                                                >
                                                    <div className="truncate font-mono">
                                                        {t.name}
                                                    </div>
                                                    <div className="text-[10px] text-ink-tertiary truncate mt-0.5">
                                                        {t.title}
                                                    </div>
                                                </button>
                                            ))}
                                        </div>

                                        <div className="mb-4 space-y-1.5">
                                            <div className="flex items-center justify-between">
                                                <label className="text-xs font-semibold text-ink flex items-center gap-1.5">
                                                    <Key className="h-3.5 w-3.5 text-accent" />
                                                    <span>Partner API Key (Optional)</span>
                                                </label>
                                                <span className="text-[10px] text-ink-tertiary">
                                                    Unlocks 300 req/min
                                                </span>
                                            </div>
                                            <input
                                                type="password"
                                                placeholder="sk_... or leave blank for public tier (60 req/min)"
                                                value={apiKey}
                                                onChange={(e) => setApiKey(e.target.value)}
                                                className="w-full rounded-lg border border-border bg-canvas px-3 py-2 font-mono text-xs text-ink placeholder:text-ink-tertiary/60 focus:border-accent focus:ring-1 focus:ring-accent"
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <div className="flex items-center justify-between">
                                                <label className="text-xs font-semibold text-ink">
                                                    Arguments (JSON)
                                                </label>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setPayloadArgs(
                                                            JSON.stringify(
                                                                selectedTool.example,
                                                                null,
                                                                2
                                                            )
                                                        )
                                                    }
                                                    className="text-[11px] text-accent hover:underline cursor-pointer"
                                                >
                                                    Reset Example
                                                </button>
                                            </div>
                                            <textarea
                                                rows={7}
                                                value={payloadArgs}
                                                onChange={(e) => setPayloadArgs(e.target.value)}
                                                className="w-full rounded-lg border border-border bg-canvas p-3 font-mono text-xs text-ink focus:border-accent focus:ring-1 focus:ring-accent"
                                            />
                                        </div>

                                        <button
                                            type="button"
                                            onClick={handleExecuteRpc}
                                            disabled={loading}
                                            className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-accent/90 disabled:opacity-50 transition-all cursor-pointer"
                                        >
                                            {loading ? (
                                                <>
                                                    <Loader2 className="h-4 w-4 animate-spin" />
                                                    <span>Calling MCP Server...</span>
                                                </>
                                            ) : (
                                                <>
                                                    <Play className="h-4 w-4 fill-white" />
                                                    <span>Execute Tool Call</span>
                                                </>
                                            )}
                                        </button>
                                    </div>
                                </div>

                                {/* Right Response Panel */}
                                <div className="lg:col-span-6 space-y-4">
                                    <div className="rounded-xl border border-border bg-surface p-5 shadow-xs flex flex-col h-full min-h-[380px]">
                                        <div className="flex items-center justify-between mb-3 border-b border-border pb-3">
                                            <div className="flex items-center gap-2">
                                                <Terminal className="h-4 w-4 text-ink-secondary" />
                                                <span className="text-xs font-semibold text-ink uppercase tracking-wider">
                                                    JSON-RPC 2.0 Response
                                                </span>
                                            </div>

                                            {responseOutput && (
                                                <div className="flex items-center gap-2 text-[11px]">
                                                    <span className={`rounded px-2 py-0.5 font-medium ${
                                                        responseOutput.http_status >= 400
                                                            ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                                            : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                    }`}>
                                                        HTTP {responseOutput.http_status || 200}
                                                    </span>
                                                    {responseOutput.rate_limit && (
                                                        <span className="rounded bg-accent/10 px-2 py-0.5 font-mono text-accent">
                                                            {responseOutput.rate_limit}
                                                        </span>
                                                    )}
                                                    {responseOutput.latency_ms && (
                                                        <span className="text-ink-tertiary font-mono">
                                                            {responseOutput.latency_ms}ms
                                                        </span>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex-1 rounded-lg border border-border/80 bg-canvas p-3.5 overflow-auto font-mono text-xs text-ink">
                                            {loading ? (
                                                <div className="flex h-full items-center justify-center gap-2 text-ink-tertiary">
                                                    <Loader2 className="h-5 w-5 animate-spin text-accent" />
                                                    <span>Awaiting MCP agent response...</span>
                                                </div>
                                            ) : responseOutput ? (
                                                <pre className="whitespace-pre-wrap">
                                                    {JSON.stringify(responseOutput, null, 2)}
                                                </pre>
                                            ) : (
                                                <div className="flex h-full flex-col items-center justify-center text-center p-6 text-ink-tertiary">
                                                    <Code2 className="h-8 w-8 stroke-1 text-ink-tertiary/60 mb-2" />
                                                    <p className="text-xs">
                                                        Click &quot;Execute Tool Call&quot; to test
                                                        the live response from{' '}
                                                        <span className="font-mono text-accent">
                                                            /mcp/marketplace
                                                        </span>
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Tab 2: Tools Documentation */}
                        {activeTab === 'docs' && (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                {tools.map((t) => (
                                    <div
                                        key={t.name}
                                        className="rounded-xl border border-border bg-surface p-6 shadow-xs flex flex-col"
                                    >
                                        <div className="flex items-center justify-between mb-2">
                                            <h3 className="text-sm font-semibold text-ink">
                                                {t.title}
                                            </h3>
                                            <span className="font-mono text-xs rounded bg-accent/10 px-2 py-0.5 text-accent">
                                                {t.name}
                                            </span>
                                        </div>

                                        <p className="text-xs text-ink-secondary mb-4">
                                            {t.description}
                                        </p>

                                        <div className="mt-auto space-y-2 border-t border-border pt-3">
                                            <span className="text-[11px] font-semibold text-ink-tertiary uppercase">
                                                Parameters
                                            </span>
                                            <div className="space-y-1.5">
                                                {Object.entries(t.arguments || {}).map(
                                                    ([param, meta]) => (
                                                        <div
                                                            key={param}
                                                            className="flex items-start justify-between text-xs font-mono"
                                                        >
                                                            <div className="flex items-center gap-1.5">
                                                                <span className="text-ink font-semibold">
                                                                    {param}
                                                                </span>
                                                                {meta.required && (
                                                                    <span className="text-[9px] rounded bg-rose-500/10 px-1 text-rose-600">
                                                                        required
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <span className="text-[11px] text-ink-tertiary">
                                                                {meta.type}
                                                            </span>
                                                        </div>
                                                    )
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Tab 3: Connect AI Assistants */}
                        {activeTab === 'connect' && (
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                {/* Claude Desktop */}
                                <div className="rounded-xl border border-border bg-surface p-6 shadow-xs space-y-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Bot className="h-5 w-5 text-accent" />
                                            <h3 className="text-sm font-semibold text-ink">
                                                Claude Desktop Setup
                                            </h3>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={handleCopyConfig}
                                            className="inline-flex items-center gap-1 text-xs text-accent hover:underline cursor-pointer"
                                        >
                                            {copiedConfig ? (
                                                <Check className="h-3.5 w-3.5 text-emerald-600" />
                                            ) : (
                                                <Copy className="h-3.5 w-3.5" />
                                            )}
                                            <span>Copy Config</span>
                                        </button>
                                    </div>

                                    <p className="text-xs text-ink-secondary">
                                        Add this entry to your{' '}
                                        <code className="rounded bg-canvas px-1 py-0.5 text-ink font-mono text-[11px]">
                                            claude_desktop_config.json
                                        </code>{' '}
                                        to empower Claude with Ghanaian venue scouting capabilities.
                                    </p>

                                    <pre className="rounded-lg border border-border bg-canvas p-4 font-mono text-xs text-ink overflow-x-auto">
                                        {claudeDesktopConfig}
                                    </pre>
                                </div>

                                {/* Cursor / cURL */}
                                <div className="rounded-xl border border-border bg-surface p-6 shadow-xs space-y-4">
                                    <div className="flex items-center gap-2">
                                        <Terminal className="h-5 w-5 text-accent" />
                                        <h3 className="text-sm font-semibold text-ink">
                                            Direct JSON-RPC (cURL)
                                        </h3>
                                    </div>

                                    <p className="text-xs text-ink-secondary">
                                        Any script, agent, or workflow can invoke the MCP server via
                                        HTTP POST:
                                    </p>

                                    <pre className="rounded-lg border border-border bg-canvas p-4 font-mono text-xs text-ink overflow-x-auto">
                                        {`curl -X POST ${server.endpoint} \\
  -H "Content-Type: application/json" \\
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
      "name": "search_venues",
      "arguments": {
        "query": "Ocean view ballroom with standby generator",
        "city": "Accra"
      }
    }
  }'`}
                                    </pre>
                                </div>

                                {/* Partner / Corporate Planner Integration */}
                                <div className="rounded-xl border border-accent/20 bg-accent/5 p-6 shadow-xs space-y-4 lg:col-span-2">
                                    <div className="flex items-center gap-2">
                                        <Key className="h-5 w-5 text-accent" />
                                        <h3 className="text-sm font-semibold text-ink">
                                            Partner / Developer Tier (300 req/min)
                                        </h3>
                                        <span className="rounded bg-accent/10 px-2 py-0.5 text-[10px] font-mono font-medium text-accent">
                                            Corporate Agencies
                                        </span>
                                    </div>

                                    <p className="text-xs text-ink-secondary leading-relaxed">
                                        Corporate event planners and agency tenants can authenticate with their tenant API key (<code className="rounded bg-canvas px-1 py-0.5 text-ink font-mono text-[11px]">X-Api-Key: sk_...</code> or <code className="rounded bg-canvas px-1 py-0.5 text-ink font-mono text-[11px]">Authorization: Bearer sk_...</code>) to unlock <strong>300 requests/minute</strong> and tag RFQ quote requests with their agency credentials and priority routing.
                                    </p>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                                        <div>
                                            <span className="text-[11px] font-semibold text-ink-tertiary uppercase">Claude Desktop with Partner Key</span>
                                            <pre className="mt-1.5 rounded-lg border border-border bg-canvas p-3 font-mono text-xs text-ink overflow-x-auto">
{JSON.stringify(
    {
        mcpServers: {
            miconvener_marketplace: {
                url: server.endpoint,
                headers: {
                    'X-Api-Key': 'sk_your_tenant_api_key_here',
                },
            },
        },
    },
    null,
    2
)}
                                            </pre>
                                        </div>
                                        <div>
                                            <span className="text-[11px] font-semibold text-ink-tertiary uppercase">cURL with Partner Header</span>
                                            <pre className="mt-1.5 rounded-lg border border-border bg-canvas p-3 font-mono text-xs text-ink overflow-x-auto">
{`curl -X POST ${server.endpoint} \\
  -H "Content-Type: application/json" \\
  -H "X-Api-Key: sk_your_tenant_api_key_here" \\
  -d '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}'`}
                                            </pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </MarketplaceLayout>
    );
}
