@php($logs = $getState() ?? collect())
<div class="bb-chatter">
    @forelse ($logs as $log)
        <div class="msg">
            <div class="av">{{ mb_substr($log->user?->name ?? '؟', 0, 1) }}</div>
            <div>
                <div class="meta">{{ $log->user?->name ?? __('System') }} · {{ $log->created_at->format('Y-m-d H:i') }}</div>
                <div class="what">{{ __(\App\Models\AuditLog::ACTIONS[$log->action] ?? $log->action) }}@if($log->reason) — {{ $log->reason }}@endif</div>
                @if (is_array($log->before) && is_array($log->after))
                    @foreach (collect($log->after)->except(['lines'])->filter(fn ($v, $k) => !is_array($v) && ($log->before[$k] ?? null) != $v) as $k => $v)
                        <div class="chg">{{ __($k) }}: {{ is_array($log->before[$k] ?? null) ? '' : ($log->before[$k] ?? '—') }} ← {{ $v }}</div>
                    @endforeach
                @endif
            </div>
        </div>
    @empty
        <div class="meta">{{ __('No activity yet.') }}</div>
    @endforelse
</div>
