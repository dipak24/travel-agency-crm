<div style="background:#b45309;color:#fff;padding:8px 16px;font-size:14px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:center;">
    <span>
        You are signed in as <strong>{{ auth('tenant')->user()?->name }}</strong>
        on behalf of platform admin <strong>{{ $impersonator['name'] }}</strong>. Everything you do is recorded.
    </span>
    <form method="POST" action="{{ route('impersonation.leave') }}" style="margin:0;">
        @csrf
        <button type="submit" style="background:#fff;color:#b45309;border:0;border-radius:6px;padding:4px 12px;font-weight:600;cursor:pointer;">
            End impersonation
        </button>
    </form>
</div>
