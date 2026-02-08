@extends('laragrep::monitor.layout')

@section('title', 'Overview - LaraGrep Monitor')

@section('content')
    {{-- Period Selector --}}
    <div class="flex items-center gap-2 mb-6">
        <span class="text-sm text-gray-500">Period:</span>
        @foreach([7 => '7d', 30 => '30d', 90 => '90d'] as $d => $label)
            <a href="{{ route('laragrep.monitor.overview', ['days' => $d]) }}"
               class="px-3 py-1 rounded text-sm {{ $days == $d ? 'bg-indigo-600 text-white' : 'bg-white border text-gray-600 hover:bg-gray-50' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Stat Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <div class="bg-white rounded-lg border p-4">
            <div class="text-2xl font-bold">{{ number_format($stats['total_queries']) }}</div>
            <div class="text-xs text-gray-500 mt-1">Total Queries</div>
        </div>
        <div class="bg-white rounded-lg border p-4">
            <div class="text-2xl font-bold text-green-600">
                {{ $stats['total_queries'] > 0 ? number_format(($stats['success_count'] / $stats['total_queries']) * 100, 1) : 0 }}%
            </div>
            <div class="text-xs text-gray-500 mt-1">Success Rate</div>
        </div>
        <div class="bg-white rounded-lg border p-4">
            <div class="text-2xl font-bold text-red-600">{{ number_format($stats['error_count']) }}</div>
            <div class="text-xs text-gray-500 mt-1">Errors</div>
        </div>
        <div class="bg-white rounded-lg border p-4">
            <div class="text-2xl font-bold">{{ number_format($stats['avg_duration_ms'], 0) }}<span class="text-sm font-normal text-gray-400">ms</span></div>
            <div class="text-xs text-gray-500 mt-1">Avg Duration</div>
        </div>
        <div class="bg-white rounded-lg border p-4">
            @php
                $realTokens = $stats['total_prompt_tokens'] + $stats['total_completion_tokens'];
            @endphp
            @if($realTokens > 0)
                <div class="text-2xl font-bold">{{ number_format($realTokens) }}</div>
                <div class="text-xs text-gray-500 mt-1">Total Tokens</div>
                <div class="text-xs text-gray-400">{{ number_format($stats['total_prompt_tokens']) }} in / {{ number_format($stats['total_completion_tokens']) }} out</div>
            @else
                <div class="text-2xl font-bold">~{{ number_format($stats['total_tokens_estimate']) }}</div>
                <div class="text-xs text-gray-500 mt-1">Total Tokens (est.)</div>
            @endif
        </div>
        <div class="bg-white rounded-lg border p-4">
            <div class="text-2xl font-bold">{{ number_format($stats['unique_users']) }}</div>
            <div class="text-xs text-gray-500 mt-1">Unique Users</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Daily Usage --}}
        <div class="bg-white rounded-lg border p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Daily Usage</h3>
            @if($stats['daily_usage']->isNotEmpty())
                @php
                    $maxTotal = $stats['daily_usage']->max('total') ?: 1;
                @endphp
                <div class="flex items-end gap-1 h-32">
                    @foreach($stats['daily_usage'] as $day)
                        <div class="flex-1 flex flex-col items-center justify-end h-full group relative">
                            <div class="w-full flex flex-col justify-end" style="height: {{ round(($day->total / $maxTotal) * 100) }}%">
                                @if($day->errors > 0)
                                    <div class="w-full bg-red-400 rounded-t" style="height: {{ round(($day->errors / $day->total) * 100) }}%; min-height: 2px;"></div>
                                @endif
                                <div class="w-full bg-indigo-500 {{ $day->errors > 0 ? '' : 'rounded-t' }} rounded-b" style="height: {{ round((($day->total - $day->errors) / $day->total) * 100) }}%; min-height: 2px;"></div>
                            </div>
                            <div class="absolute bottom-full mb-1 hidden group-hover:block bg-gray-800 text-white text-xs rounded px-2 py-1 whitespace-nowrap z-10">
                                {{ $day->date }}: {{ $day->total }} queries{{ $day->errors > 0 ? ", {$day->errors} errors" : '' }}
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between mt-2 text-xs text-gray-400">
                    <span>{{ $stats['daily_usage']->first()->date }}</span>
                    <span>{{ $stats['daily_usage']->last()->date }}</span>
                </div>
            @else
                <p class="text-sm text-gray-400 text-center py-8">No data for this period.</p>
            @endif
        </div>

        {{-- Top Scopes --}}
        <div class="bg-white rounded-lg border p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Top Scopes</h3>
            @if($stats['top_scopes']->isNotEmpty())
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-1 text-gray-500 font-medium">Scope</th>
                            <th class="text-right py-1 text-gray-500 font-medium">Queries</th>
                            <th class="text-right py-1 text-gray-500 font-medium">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats['top_scopes'] as $scope)
                            <tr class="border-b last:border-0">
                                <td class="py-1.5">{{ $scope->scope }}</td>
                                <td class="py-1.5 text-right text-gray-500">{{ number_format($scope->total) }}</td>
                                <td class="py-1.5 text-right text-gray-500">
                                    {{ $stats['total_queries'] > 0 ? number_format(($scope->total / $stats['total_queries']) * 100, 1) : 0 }}%
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-sm text-gray-400 text-center py-4">No data.</p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Recent Errors --}}
        <div class="bg-white rounded-lg border p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Recent Errors</h3>
            @if($stats['recent_errors']->isNotEmpty())
                <div class="space-y-2">
                    @foreach($stats['recent_errors'] as $error)
                        <a href="{{ route('laragrep.monitor.detail', $error->id) }}" class="block p-3 rounded bg-red-50 hover:bg-red-100 transition">
                            <p class="text-sm font-medium text-red-800">{{ Str::limit($error->question, 80) }}</p>
                            <p class="text-xs text-red-600 mt-1">{{ Str::limit($error->error_message, 120) }}</p>
                            <p class="text-xs text-red-400 mt-1">{{ $error->created_at }}</p>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-400 text-center py-4">No errors in this period.</p>
            @endif
        </div>

        {{-- Storage --}}
        <div class="bg-white rounded-lg border p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Storage</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-2xl font-bold">{{ number_format($stats['storage']['total_rows']) }}</div>
                    <div class="text-xs text-gray-500 mt-1">Total Log Entries</div>
                </div>
                @if($stats['storage']['db_size_bytes'] !== null)
                    <div>
                        <div class="text-2xl font-bold">
                            @if($stats['storage']['db_size_bytes'] >= 1048576)
                                {{ number_format($stats['storage']['db_size_bytes'] / 1048576, 1) }}<span class="text-sm font-normal text-gray-400">MB</span>
                            @else
                                {{ number_format($stats['storage']['db_size_bytes'] / 1024, 1) }}<span class="text-sm font-normal text-gray-400">KB</span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 mt-1">Database Size</div>
                    </div>
                @endif
                <div>
                    <div class="text-lg font-bold text-gray-600">{{ number_format($stats['avg_iterations'], 1) }}</div>
                    <div class="text-xs text-gray-500 mt-1">Avg Iterations/Query</div>
                </div>
                <div>
                    <div class="text-lg font-bold text-gray-600">{{ number_format($stats['max_duration_ms'], 0) }}<span class="text-sm font-normal text-gray-400">ms</span></div>
                    <div class="text-xs text-gray-500 mt-1">Max Duration</div>
                </div>
            </div>
        </div>
    </div>
@endsection
