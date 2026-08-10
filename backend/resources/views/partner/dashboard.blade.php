<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Partner Dashboard — TripInele</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 2rem; }
        .wrap { max-width: 960px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        h1 { font-size: 1.25rem; margin: 0; }
        .muted { color: #94a3b8; font-size: 0.875rem; }
        .logout { background: none; border: 1px solid #334155; color: #e2e8f0; padding: 0.4rem 0.9rem; border-radius: 6px; text-decoration: none; font-size: 0.875rem; }
        .totals { display: flex; gap: 1rem; margin-bottom: 2rem; }
        .stat { background: #1e293b; border-radius: 10px; padding: 1rem 1.25rem; flex: 1; }
        .stat .label { color: #94a3b8; font-size: 0.8rem; }
        .stat .value { font-size: 1.5rem; font-weight: 600; margin-top: 0.25rem; }
        section { background: #1e293b; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem; }
        section h2 { font-size: 1rem; margin: 0 0 1rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th, td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-weight: 500; }
        .badge { padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.75rem; }
        .badge.pending { background: #78350f; color: #fbbf24; }
        .badge.reconciled { background: #1e3a8a; color: #93c5fd; }
        .badge.paid { background: #14532d; color: #86efac; }
        .empty { color: #64748b; font-size: 0.875rem; }
        code { background: #0f172a; padding: 0.1rem 0.4rem; border-radius: 4px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>{{ $partner->user->name }}</h1>
            <div class="muted">Share rate: {{ rtrim(rtrim(number_format($partner->share_percentage, 2), '0'), '.') }}% on first booking</div>
        </div>
        <a class="logout" href="{{ route('auth.logout') }}">Sign out</a>
    </header>

    <div class="totals">
        <div class="stat"><div class="label">Pending</div><div class="value">€{{ number_format($totals['pending'], 2) }}</div></div>
        <div class="stat"><div class="label">Reconciled</div><div class="value">€{{ number_format($totals['reconciled'], 2) }}</div></div>
        <div class="stat"><div class="label">Paid</div><div class="value">€{{ number_format($totals['paid'], 2) }}</div></div>
    </div>

    <section>
        <h2>Referral codes</h2>
        @if ($partner->referralCodes->isEmpty())
            <div class="empty">No codes yet — created by TripInele admin.</div>
        @else
            <table>
                <thead><tr><th>Code</th><th>Label</th><th>Referred users</th></tr></thead>
                <tbody>
                @foreach ($partner->referralCodes as $code)
                    <tr>
                        <td><code>{{ $code->code }}</code></td>
                        <td>{{ $code->label ?? '—' }}</td>
                        <td>{{ $attributions->where('referral_code_id', $code->id)->count() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section>
        <h2>Commissions</h2>
        @if ($commissionShares->isEmpty())
            <div class="empty">No confirmed bookings yet.</div>
        @else
            <table>
                <thead><tr><th>Date</th><th>Booking #</th><th>Share</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                @foreach ($commissionShares as $share)
                    <tr>
                        <td>{{ $share->created_at->format('Y-m-d') }}</td>
                        <td>{{ $share->booking_sequence_number }}</td>
                        <td>{{ rtrim(rtrim(number_format($share->share_percentage_applied, 2), '0'), '.') }}%</td>
                        <td>{{ $share->estimated_amount_eur !== null ? '€'.number_format($share->estimated_amount_eur, 2) : '—' }}</td>
                        <td><span class="badge {{ $share->status }}">{{ $share->status }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
</body>
</html>
