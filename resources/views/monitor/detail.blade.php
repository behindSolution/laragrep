@extends('laragrep::monitor.layout')

@section('title', 'Log #' . $entry->id . ' - LaraGrep Monitor')

@php
    $steps = json_decode($entry->steps, true) ?: [];
    $debugQueries = json_decode($entry->debug_queries, true) ?: [];
@endphp

@section('content')
    <div class="mb-4">
        <a href="{{ route('laragrep.monitor.list') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to logs</a>
    </div>

    {{-- Header --}}
    <div class="bg-white rounded-lg border p-6 mb-4">
        <div class="flex items-start justify-between mb-4">
            <h2 class="text-lg font-semibold">{{ $entry->question }}</h2>
            @if($entry->status === 'success')
                <span class="inline-block px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">success</span>
            @else
                <span class="inline-block px-2 py-0.5 bg-red-100 text-red-700 rounded text-xs font-medium">error</span>
            @endif
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-500">Scope:</span>
                <span class="font-medium">{{ $entry->scope }}</span>
            </div>
            <div>
                <span class="text-gray-500">User:</span>
                <span class="font-medium">{{ $entry->user_id ?? '-' }}</span>
            </div>
            <div>
                <span class="text-gray-500">Duration:</span>
                <span class="font-medium">{{ number_format($entry->duration_ms, 0) }}ms</span>
            </div>
            <div>
                <span class="text-gray-500">Date:</span>
                <span class="font-medium">{{ $entry->created_at }}</span>
            </div>
            <div>
                <span class="text-gray-500">Provider:</span>
                <span class="font-medium">{{ $entry->provider ?? '-' }}</span>
            </div>
            <div>
                <span class="text-gray-500">Model:</span>
                <span class="font-medium text-xs">{{ $entry->model ?? '-' }}</span>
            </div>
            <div>
                <span class="text-gray-500">Conversation:</span>
                <span class="font-medium text-xs">{{ $entry->conversation_id ?? '-' }}</span>
            </div>
            <div>
                <span class="text-gray-500">Iterations:</span>
                <span class="font-medium">{{ $entry->iterations }}</span>
            </div>
            <div>
                <span class="text-gray-500">Tokens:</span>
                @if(($entry->prompt_tokens ?? 0) + ($entry->completion_tokens ?? 0) > 0)
                    <span class="font-medium">{{ number_format($entry->prompt_tokens + $entry->completion_tokens) }}</span>
                    <span class="text-xs text-gray-400">({{ number_format($entry->prompt_tokens) }} in / {{ number_format($entry->completion_tokens) }} out)</span>
                @else
                    <span class="font-medium">~{{ number_format($entry->token_estimate) }}</span>
                    <span class="text-xs text-gray-400">(est.)</span>
                @endif
            </div>
            @if($entry->tables_total)
                <div>
                    <span class="text-gray-500">Schema:</span>
                    <span class="font-medium">{{ $entry->tables_filtered }} of {{ $entry->tables_total }} tables</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Smart Schema --}}
    @if($entry->tables_total && $entry->tables_total != $entry->tables_filtered)
        <div class="bg-white rounded-lg border p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Smart Schema Filtering</h3>
            <div class="flex items-center gap-3">
                <div class="flex-1 bg-gray-200 rounded-full h-2.5">
                    <div class="bg-indigo-600 h-2.5 rounded-full" style="width: {{ round(($entry->tables_filtered / $entry->tables_total) * 100) }}%"></div>
                </div>
                <span class="text-sm text-gray-600">{{ $entry->tables_filtered }}/{{ $entry->tables_total }} tables used ({{ round(100 - ($entry->tables_filtered / $entry->tables_total) * 100) }}% reduced)</span>
            </div>
        </div>
    @endif

    {{-- Error --}}
    @if($entry->status === 'error')
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
            <h3 class="text-sm font-semibold text-red-700 mb-2">Error</h3>
            <p class="text-sm text-red-600 font-mono">{{ $entry->error_class }}</p>
            <p class="text-sm text-red-800 mt-1">{{ $entry->error_message }}</p>
            @if($entry->error_trace)
                <details class="mt-3">
                    <summary class="text-xs text-red-500 cursor-pointer hover:text-red-700">Stack trace</summary>
                    <pre class="mt-2 text-xs text-red-600 overflow-x-auto bg-red-100 p-3 rounded">{{ $entry->error_trace }}</pre>
                </details>
            @endif
        </div>
    @endif

    {{-- Agent Loop Steps --}}
    @if(count($steps) > 0)
        <div class="mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Agent Loop Steps</h3>
            <div class="space-y-3">
                @foreach($steps as $i => $step)
                    <details class="bg-white rounded-lg border" {{ $i === 0 ? 'open' : '' }}>
                        <summary class="px-4 py-3 cursor-pointer hover:bg-gray-50 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium">Step {{ $i + 1 }}: {{ Str::limit($step['reason'] ?? 'No reason provided', 100) }}</span>
                                @if(!empty($step['connection']))
                                    <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-mono">{{ $step['connection'] }}</span>
                                @endif
                            </div>
                            @if(isset($step['results_truncated']))
                                <span class="text-xs text-amber-600">results truncated</span>
                            @endif
                        </summary>
                        <div class="px-4 pb-4 border-t space-y-3">
                            @if(!empty($step['reason']))
                                <div class="mt-3">
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Reason</span>
                                    <p class="text-sm mt-1">{{ $step['reason'] }}</p>
                                </div>
                            @endif
                            @if(!empty($step['connection']))
                                <div>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Connection</span>
                                    <span class="inline-block mt-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-mono">{{ $step['connection'] }}</span>
                                </div>
                            @endif
                            <div>
                                <span class="text-xs text-gray-500 uppercase tracking-wide">SQL Query</span>
                                <pre class="mt-1 text-sm bg-gray-50 p-3 rounded overflow-x-auto font-mono">{{ $step['query'] ?? '' }}</pre>
                            </div>
                            @if(!empty($step['bindings']))
                                <div>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Bindings</span>
                                    <pre class="mt-1 text-sm bg-gray-50 p-3 rounded overflow-x-auto font-mono">{{ json_encode($step['bindings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif
                            @if(isset($step['results']))
                                <div>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Results ({{ count($step['results']) }} rows)</span>
                                    <pre class="mt-1 text-sm bg-gray-50 p-3 rounded overflow-x-auto font-mono max-h-64">{{ json_encode($step['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </div>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Summary --}}
    @if($entry->summary)
        <div class="bg-white rounded-lg border p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Final Answer</h3>
            <div class="text-sm prose max-w-none whitespace-pre-line">{{ $entry->summary }}</div>
        </div>
    @endif

    {{-- Debug Queries --}}
    @if(count($debugQueries) > 0)
        <details class="bg-white rounded-lg border mb-4">
            <summary class="px-4 py-3 cursor-pointer hover:bg-gray-50 text-sm font-semibold text-gray-700">
                Raw Query Log ({{ count($debugQueries) }} queries)
            </summary>
            <div class="px-4 pb-4 border-t mt-3 space-y-3">
                @foreach($debugQueries as $i => $q)
                    <div class="border rounded p-3 bg-gray-50">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400 font-medium">#{{ $i + 1 }}</span>
                                @if(!empty($q['connection']))
                                    <span class="px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-mono">{{ $q['connection'] }}</span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-500">{{ isset($q['time']) ? number_format($q['time'], 2) . 'ms' : '-' }}</span>
                        </div>
                        <pre class="text-xs font-mono whitespace-pre-wrap break-all bg-white p-2 rounded border">{{ $q['query'] ?? '' }}</pre>
                        @if(!empty($q['bindings']))
                            <div class="mt-1.5 text-xs font-mono text-gray-500">Bindings: {{ json_encode($q['bindings'], JSON_UNESCAPED_UNICODE) }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @endif
@endsection
