@php
    use Illuminate\Support\Facades\Route;

    $apiBase = url('/api/v1');
    $otpDriver = config('services.otp.driver');
    $accessHours = intdiv((int) config('sanctum.access_token_expiration'), 60);
    $refreshDays = intdiv((int) config('sanctum.refresh_token_expiration'), 60 * 24);

    /** @var array<string, true> $liveRoutes "METHOD uri" keys of every registered API route */
    $liveRoutes = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $liveRoutes[$method.' '.$route->uri()] = true;
        }
    }

    $isLive = function (string $method, string $path) use ($liveRoutes): bool {
        $uri = 'api/v1'.preg_replace('/\{[^}]+\}/', '{x}', $path);
        foreach (array_keys($liveRoutes) as $key) {
            [$routeMethod, $routeUri] = explode(' ', $key, 2);
            if ($routeMethod === $method && preg_replace('/\{[^}]+\}/', '{x}', $routeUri) === $uri) {
                return true;
            }
        }

        return false;
    };

    /** @var array<string, array{note: string, endpoints: list<array{0: string, 1: string, 2: string}>}> $groups */
    $groups = [
        'Auth' => [
            'note' => 'Phone + password. OTP verification can be switched on from the server without an app update.',
            'endpoints' => [
                ['GET', '/auth/options', 'Which login options are enabled (OTP on/off)'],
                ['POST', '/auth/register', 'Create an account, returns a session'],
                ['POST', '/auth/login', 'Log in with phone and password'],
                ['POST', '/auth/verify-otp', 'Confirm registration with an OTP'],
                ['POST', '/auth/resend-otp', 'Send the OTP again'],
                ['POST', '/auth/login/otp', 'Request a login OTP'],
                ['POST', '/auth/login/otp/verify', 'Log in with an OTP'],
                ['POST', '/auth/refresh', 'Swap a refresh token for a new session'],
                ['POST', '/auth/logout', 'Revoke this device\'s tokens'],
            ],
        ],
        'Profile' => [
            'note' => 'The signed-in user.',
            'endpoints' => [
                ['GET', '/me', 'Current user'],
                ['PUT', '/me', 'Update name and email'],
                ['POST', '/me/device-token', 'Register a device for push (reserved)'],
            ],
        ],
        'Events' => [
            'note' => 'Every event is returned as seen by the requesting user: role, own contribution, totals.',
            'endpoints' => [
                ['GET', '/events', 'Events I own (scope=owned) or joined (scope=joined)'],
                ['POST', '/events', 'Create an event'],
                ['GET', '/events/{id}', 'Event details'],
                ['PUT', '/events/{id}', 'Update an event (owner)'],
                ['GET', '/events/{id}/members', 'Members and pending invites'],
                ['GET', '/events/{id}/contributions', 'Who paid, newest first'],
                ['POST', '/events/{id}/invitations', 'Invite members by phone (owner)'],
            ],
        ],
        'Invitations' => [
            'note' => 'Invitations received by the signed-in user.',
            'endpoints' => [
                ['GET', '/invitations', 'Pending invitations'],
                ['POST', '/invitations/{id}/accept', 'Join the event'],
                ['POST', '/invitations/{id}/decline', 'Decline the invitation'],
            ],
        ],
        'Payments' => [
            'note' => 'Razorpay orders with a single-use UPI QR. Capture is idempotent across the verify call and the webhook.',
            'endpoints' => [
                ['POST', '/events/{id}/payments/order', 'Start a contribution, returns the UPI QR'],
                ['POST', '/payments/verify', 'Verify a Razorpay Checkout payment'],
                ['GET', '/payments/{order_id}/status', 'Poll the payment status'],
                ['POST', '/webhooks/razorpay', 'Razorpay webhook (server to server)'],
            ],
        ],
        'Wallet & bank' => [
            'note' => 'Event owners receive every contribution in their wallet and withdraw to a bank account.',
            'endpoints' => [
                ['GET', '/wallet', 'Balance and totals'],
                ['GET', '/wallet/transactions', 'Ledger, newest first'],
                ['POST', '/wallet/withdrawals', 'Withdraw to a bank account'],
                ['GET', '/bank-accounts', 'Saved bank accounts (numbers masked)'],
                ['POST', '/bank-accounts', 'Add a bank account'],
                ['DELETE', '/bank-accounts/{id}', 'Remove a bank account'],
                ['POST', '/bank-accounts/{id}/primary', 'Make an account primary'],
            ],
        ],
        'Notifications' => [
            'note' => 'In-app inbox. The app polls it every 30 seconds while open.',
            'endpoints' => [
                ['GET', '/notifications', 'Latest notifications'],
                ['POST', '/notifications/{id}/read', 'Mark one as read'],
            ],
        ],
    ];

    $total = 0;
    $live = 0;
    foreach ($groups as $group) {
        foreach ($group['endpoints'] as [$method, $path]) {
            $total++;
            $live += $isLive($method, $path) ? 1 : 0;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ChandaBox · Community donations, made simple</title>
    <meta name="description" content="ChandaBox helps clubs and communities collect chanda for pujas and events through UPI, and is the API behind the ChandaBox app.">
    <link rel="icon" type="image/png" href="{{ asset('images/chandabox-mark.png') }}">
    <style>
        :root {
            --bg: #fffaf5;
            --surface: #ffffff;
            --surface-2: #fff3e6;
            --text: #2a1a10;
            --muted: #7a6152;
            --border: #f1dfcf;
            --brand: #f05a0e;
            --brand-strong: #d9400a;
            --brand-soft: #ffe6d3;
            --amber: #f7a51c;
            --ok: #1a8d4f;
            --ok-soft: #dcf3e6;
            --wait: #8a6d00;
            --wait-soft: #fbf0c8;
            --code-bg: #2a1a10;
            --code-text: #ffe9d6;
            --shadow: 0 1px 2px rgba(80, 40, 10, .06), 0 8px 24px rgba(80, 40, 10, .06);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #17100b;
                --surface: #211710;
                --surface-2: #2a1d14;
                --text: #f8ece2;
                --muted: #c2a896;
                --border: #3a2a1f;
                --brand: #ff7a2f;
                --brand-strong: #ff9150;
                --brand-soft: #3d2416;
                --ok: #5fd394;
                --ok-soft: #173826;
                --wait: #f1cf5b;
                --wait-soft: #3a3115;
                --code-bg: #0f0a07;
                --shadow: none;
            }
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--brand-strong); }
        code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .9em; }
        .wrap { max-width: 1080px; margin: 0 auto; padding-inline: 20px; }

        header.top {
            position: sticky; top: 0; z-index: 10;
            background: color-mix(in srgb, var(--bg) 88%, transparent);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }
        header.top .wrap { display: flex; align-items: center; gap: 16px; height: 60px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 18px; color: var(--text); text-decoration: none; }
        .brand img { width: 30px; height: 30px; }
        nav.links { margin-left: auto; display: flex; gap: 20px; font-size: 14px; }
        nav.links a { color: var(--muted); text-decoration: none; }
        nav.links a:hover { color: var(--brand-strong); }

        .hero { padding-block: 64px 48px; display: grid; grid-template-columns: 1.1fr .9fr; gap: 40px; align-items: center; }
        .eyebrow { display: inline-block; font-size: 13px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--brand-strong); background: var(--brand-soft); padding: 4px 10px; border-radius: 999px; }
        h1 { font-size: clamp(32px, 5vw, 48px); line-height: 1.12; margin: 16px 0; letter-spacing: -.02em; }
        h1 span { background: linear-gradient(90deg, var(--brand), var(--amber)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .lead { font-size: 18px; color: var(--muted); max-width: 34em; }
        .cta { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border-radius: 12px; font-weight: 600; text-decoration: none; font-size: 15px; }
        .btn.primary { background: linear-gradient(135deg, var(--amber), var(--brand)); color: #fff; box-shadow: 0 6px 18px rgba(240, 90, 14, .28); }
        .btn.ghost { border: 1px solid var(--border); color: var(--text); background: var(--surface); }
        .hero-logo { background: #fff; border-radius: 28px; padding: 28px; box-shadow: var(--shadow); border: 1px solid var(--border); }
        .hero-logo img { width: 100%; height: auto; display: block; }

        section { padding: 48px 0; }
        h2 { font-size: 28px; margin: 0 0 8px; letter-spacing: -.01em; }
        .section-lead { color: var(--muted); margin: 0 0 28px; max-width: 46em; }

        .grid { display: grid; gap: 16px; }
        .grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 22px; box-shadow: var(--shadow); }
        .card h3 { margin: 12px 0 6px; font-size: 17px; }
        .card p { margin: 0; color: var(--muted); font-size: 15px; }
        .icon { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: var(--brand-soft); color: var(--brand-strong); }
        .icon svg { width: 22px; height: 22px; }

        .steps { counter-reset: step; display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .step { position: relative; padding: 22px; background: var(--surface-2); border-radius: 18px; border: 1px solid var(--border); }
        .step::before { counter-increment: step; content: counter(step); display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; background: var(--brand); color: #fff; font-weight: 700; font-size: 14px; }
        .step h3 { margin: 12px 0 4px; font-size: 16px; }
        .step p { margin: 0; color: var(--muted); font-size: 14px; }

        .status-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
        .stat { flex: 1 1 200px; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 16px 18px; }
        .stat .label { font-size: 13px; color: var(--muted); }
        .stat .value { font-size: 17px; font-weight: 650; margin-top: 2px; word-break: break-all; }
        .progress { height: 8px; border-radius: 99px; background: var(--brand-soft); margin-top: 10px; overflow: hidden; }
        .progress > div { height: 100%; background: linear-gradient(90deg, var(--amber), var(--brand)); border-radius: 99px; }

        .conventions { width: 100%; border-collapse: collapse; font-size: 15px; }
        .conventions th, .conventions td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
        .conventions th { width: 140px; color: var(--muted); font-weight: 600; }

        pre { background: var(--code-bg); color: var(--code-text); padding: 18px; border-radius: 14px; overflow-x: auto; font-size: 13.5px; line-height: 1.55; margin: 0; }

        .group { margin-top: 28px; }
        .group-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .group-head h3 { margin: 0; font-size: 19px; }
        .group-head p { margin: 4px 0 12px; color: var(--muted); font-size: 14px; flex-basis: 100%; }
        .endpoints { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
        .ep { display: grid; grid-template-columns: 74px minmax(0, 1.1fr) minmax(0, 1fr) 84px; gap: 12px; align-items: center; padding: 11px 16px; border-top: 1px solid var(--border); font-size: 14.5px; }
        .ep:first-child { border-top: 0; }
        .ep .path { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13.5px; overflow-wrap: anywhere; }
        .ep .desc { color: var(--muted); }
        .method { font: 700 11.5px/1 ui-monospace, Menlo, monospace; padding: 5px 0; border-radius: 6px; text-align: center; letter-spacing: .03em; }
        .m-GET { background: #e3f0ff; color: #1857b8; }
        .m-POST { background: var(--brand-soft); color: var(--brand-strong); }
        .m-PUT { background: #efe6ff; color: #6b3bc4; }
        .m-DELETE { background: #ffe1e1; color: #c23030; }
        @media (prefers-color-scheme: dark) {
            .m-GET { background: #15263d; color: #8ab8ff; }
            .m-PUT { background: #261c3d; color: #c3a6ff; }
            .m-DELETE { background: #3d1717; color: #ff9a9a; }
        }
        .badge { font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 999px; text-align: center; white-space: nowrap; }
        .badge.live { background: var(--ok-soft); color: var(--ok); }
        .badge.soon { background: var(--wait-soft); color: var(--wait); }

        footer { border-top: 1px solid var(--border); padding: 28px 0 40px; color: var(--muted); font-size: 14px; }
        footer .wrap { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: center; }

        @media (max-width: 860px) {
            .hero { grid-template-columns: 1fr; padding-block-start: 40px; }
            .hero-logo { max-width: 360px; }
            .grid.cols-3 { grid-template-columns: 1fr 1fr; }
            .steps { grid-template-columns: 1fr 1fr; }
            nav.links { display: none; }
        }
        @media (max-width: 560px) {
            .wrap { padding-inline: 16px; }
            .grid.cols-3, .steps { grid-template-columns: 1fr; }
            .ep { grid-template-columns: 64px 1fr; row-gap: 4px; }
            .ep .desc { grid-column: 2; }
            .ep .badge { grid-column: 2; justify-self: start; }
            .conventions th { width: 100px; }
        }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="brand" href="#top">
                <img src="{{ asset('images/chandabox-mark.png') }}" alt="">
                ChandaBox
            </a>
            <nav class="links" aria-label="Sections">
                <a href="#features">Features</a>
                <a href="#how">How it works</a>
                <a href="#api">API</a>
                <a href="#endpoints">Endpoints</a>
            </nav>
        </div>
    </header>

    <main id="top">
        <div class="wrap hero">
            <div>
                <span class="eyebrow">Community donations · UPI</span>
                <h1>Collect <span>chanda</span> for your puja, the simple way.</h1>
                <p class="lead">
                    ChandaBox lets clubs, para committees and families run a collection drive from their phone:
                    invite members, take contributions by UPI QR, see who has paid, and withdraw the money to a bank account.
                </p>
                <div class="cta">
                    <a class="btn primary" href="#api">Explore the API</a>
                    <a class="btn ghost" href="#how">How it works</a>
                </div>
            </div>
            <div class="hero-logo">
                <img src="{{ asset('images/chandabox-logo.png') }}" alt="ChandaBox logo" width="640" height="469">
            </div>
        </div>

        <section id="features">
            <div class="wrap">
                <h2>Everything a collection drive needs</h2>
                <p class="section-lead">No more paper receipt books or chasing people for cash. Every rupee is tracked from the payer to the organiser's bank.</p>
                <div class="grid cols-3">
                    <div class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>
                        <h3>Events with a programme</h3>
                        <p>Create an event with a suggested amount, an optional target, dates, venue and a day-by-day schedule.</p>
                    </div>
                    <div class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg></div>
                        <h3>Invite by phone number</h3>
                        <p>Invite members by mobile number, even before they install the app. Invitations wait for them when they sign up.</p>
                    </div>
                    <div class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg></div>
                        <h3>Pay by UPI QR</h3>
                        <p>Members scan a single-use QR with any UPI app, or pay through Razorpay Checkout. Payments confirm automatically.</p>
                    </div>
                    <div class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg></div>
                        <h3>Transparent totals</h3>
                        <p>Everyone in the event sees the total collected, progress to the target and the list of contributions.</p>
                    </div>
                    <div class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1 0-4h12v4"/><path d="M4 6v12a2 2 0 0 0 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg></div>
                        <h3>Wallet & withdrawals</h3>
                        <p>Contributions land in the organiser's wallet. Withdraw to a saved bank account; account numbers are stored encrypted.</p>
                    </div>
                    <div class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></div>
                        <h3>Notifications & languages</h3>
                        <p>Get notified about invitations, payments and withdrawals. The app speaks English, Bengali and Hindi.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="how">
            <div class="wrap">
                <h2>How the money moves</h2>
                <p class="section-lead">Payments are collected through Razorpay and recorded in a ledger, so every balance can be traced back to the contributions behind it.</p>
                <div class="steps">
                    <div class="step">
                        <h3>Organiser creates an event</h3>
                        <p>Sets the suggested amount and invites members by phone.</p>
                    </div>
                    <div class="step">
                        <h3>Members contribute</h3>
                        <p>Each payment gets a Razorpay order and a 15-minute UPI QR.</p>
                    </div>
                    <div class="step">
                        <h3>Payment is captured</h3>
                        <p>Verified once, recorded as a contribution and credited to the organiser's wallet.</p>
                    </div>
                    <div class="step">
                        <h3>Organiser withdraws</h3>
                        <p>Money is paid out to their bank account through RazorpayX.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="api">
            <div class="wrap">
                <h2>The ChandaBox API</h2>
                <p class="section-lead">This server is the JSON API behind the ChandaBox mobile app. It is built with Laravel and authenticates with Sanctum bearer tokens.</p>

                <div class="status-row">
                    <div class="stat">
                        <div class="label">Base URL</div>
                        <div class="value mono">{{ $apiBase }}</div>
                    </div>
                    <div class="stat">
                        <div class="label">Phone OTP</div>
                        <div class="value">{{ $otpDriver === 'none' ? 'Off (phone + password)' : 'On ('.$otpDriver.')' }}</div>
                    </div>
                    <div class="stat">
                        <div class="label">Endpoints available</div>
                        <div class="value">{{ $live }} of {{ $total }}</div>
                        <div class="progress" aria-hidden="true"><div style="width: {{ $total > 0 ? round($live / $total * 100) : 0 }}%"></div></div>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                    <div class="card">
                        <table class="conventions">
                            <tr><th>Auth</th><td><code>Authorization: Bearer &lt;token&gt;</code></td></tr>
                            <tr><th>Headers</th><td><code>Accept: application/json</code></td></tr>
                            <tr><th>Sessions</th><td>Access token {{ $accessHours }} h, refresh token {{ $refreshDays }} days. Refresh once on a <code>401</code>.</td></tr>
                            <tr><th>Money</th><td>Rupees as numbers, e.g. <code>1001</code> or <code>1001.50</code></td></tr>
                            <tr><th>Phones</th><td>10-digit Indian mobile; <code>+91</code> and leading <code>0</code> are accepted and normalised</td></tr>
                            <tr><th>Dates</th><td>ISO-8601, e.g. <code>2026-11-09T09:00:00Z</code></td></tr>
                            <tr><th>Errors</th><td><code>{"message", "errors": {field: [...]}}</code> with 401, 403, 404, 422, 429</td></tr>
                        </table>
                    </div>
                    <div>
<pre><span style="color:#ffb07a">curl</span> -X POST {{ $apiBase }}/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"phone":"9800000002","password":"••••••••"}'

<span style="color:#9a8576"># 200 OK</span>
{
  "token": "1|abc…",
  "token_type": "Bearer",
  "expires_at": "…",
  "refresh_token": "2|def…",
  "refresh_token_expires_at": "…",
  "user": { "id": 2, "name": "Arijit Sen",
            "phone": "9800000002" }
}</pre>
                    </div>
                </div>
            </div>
        </section>

        <section id="endpoints" style="padding-top: 8px;">
            <div class="wrap">
                <h2>Endpoints</h2>
                <p class="section-lead">
                    All paths are relative to <code>{{ $apiBase }}</code>.
                    <span class="badge live">Live</span> endpoints are running on this server;
                    <span class="badge soon">Coming soon</span> ones are part of the contract the app already uses and are being built.
                </p>

                @foreach ($groups as $title => $group)
                    <div class="group">
                        <div class="group-head">
                            <h3>{{ $title }}</h3>
                            <p>{{ $group['note'] }}</p>
                        </div>
                        <div class="endpoints">
                            @foreach ($group['endpoints'] as [$method, $path, $description])
                                @php($available = $isLive($method, $path))
                                <div class="ep">
                                    <span class="method m-{{ $method }}">{{ $method }}</span>
                                    <span class="path">{{ $path }}</span>
                                    <span class="desc">{{ $description }}</span>
                                    <span class="badge {{ $available ? 'live' : 'soon' }}">{{ $available ? 'Live' : 'Coming soon' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap">
            <span>© {{ date('Y') }} ChandaBox. Community donations, made simple.</span>
            <span class="mono">API v1</span>
        </div>
    </footer>
</body>
</html>
