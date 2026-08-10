<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Partner Login — TripInele</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2.5rem; border-radius: 12px; width: 100%; max-width: 360px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        h1 { font-size: 1.25rem; margin: 0 0 1.5rem; }
        label { display: block; font-size: 0.875rem; margin-bottom: 0.375rem; color: #94a3b8; }
        input { width: 100%; box-sizing: border-box; padding: 0.625rem 0.75rem; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; margin-bottom: 1rem; font-size: 0.95rem; }
        button { width: 100%; padding: 0.7rem; border-radius: 8px; border: none; background: #f59e0b; color: #1e293b; font-weight: 600; cursor: pointer; font-size: 0.95rem; }
        button:hover { background: #fbbf24; }
        .error { color: #f87171; font-size: 0.875rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>TripInele — Partner Login</h1>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('partner.login.submit') }}">
            @csrf
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
