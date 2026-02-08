@extends('laragrep::monitor.layout')

@section('title', 'Logs - LaraGrep Monitor')

@section('content')
    <div class="mb-6">
        <form method="GET" action="{{ route('laragrep.monitor.list') }}" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Search</label>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search questions..." class="border rounded px-3 py-1.5 text-sm w-48">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Scope</label>
                <select name="scope" class="border rounded px-3 py-1.5 text-sm">
                    <option value="">All</option>
                    @foreach($scopes as $s)
                        <option value="{{ $s }}" {{ ($filters['scope'] ?? '') === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Status</label>
                <select name="status" class="border rounded px-3 py-1.5 text-sm">
                    <option value="">All</option>
                    <option value="success" {{ ($filters['status'] ?? '') === 'success' ? 'selected' : '' }}>Success</option>
                    <option value="error" {{ ($filters['status'] ?? '') === 'error' ? 'selected' : '' }}>Error</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">User ID</label>
                <input type="text" name="user_id" value="{{ $filters['user_id'] ?? '' }}" placeholder="User ID" class="border rounded px-3 py-1.5 text-sm w-24">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">From</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="border rounded px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">To</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="border rounded px-3 py-1.5 text-sm">
            </div>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded text-sm hover:bg-indigo-700">Filter</button>
            <a href="{{ route('laragrep.monitor.list') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Clear</a>
        </form>
    </div>

    <div class="bg-white rounded-lg border overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">ID</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Question</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Scope</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Status</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">User</th>
                    <th class="text-right px-4 py-2 font-medium text-gray-600">Duration</th>
                    <th class="text-right px-4 py-2 font-medium text-gray-600">Steps</th>
                    <th class="text-right px-4 py-2 font-medium text-gray-600">Tokens</th>
                    <th class="text-left px-4 py-2 font-medium text-gray-600">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($entries as $entry)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-gray-500">{{ $entry->id }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('laragrep.monitor.detail', $entry->id) }}" class="text-indigo-600 hover:underline">
                                {{ Str::limit($entry->question, 80) }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $entry->scope }}</td>
                        <td class="px-4 py-2">
                            @if($entry->status === 'success')
                                <span class="inline-block px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">success</span>
                            @else
                                <span class="inline-block px-2 py-0.5 bg-red-100 text-red-700 rounded text-xs font-medium">error</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $entry->user_id ?? '-' }}</td>
                        <td class="px-4 py-2 text-right text-gray-500">{{ number_format($entry->duration_ms, 0) }}ms</td>
                        <td class="px-4 py-2 text-right text-gray-500">{{ $entry->iterations }}</td>
                        <td class="px-4 py-2 text-right text-gray-500">
                            @if(($entry->prompt_tokens ?? 0) + ($entry->completion_tokens ?? 0) > 0)
                                {{ number_format($entry->prompt_tokens + $entry->completion_tokens) }}
                            @else
                                ~{{ number_format($entry->token_estimate) }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500 text-xs">{{ $entry->created_at }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-400">No logs found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $entries->appends($filters)->links() }}
    </div>
@endsection
