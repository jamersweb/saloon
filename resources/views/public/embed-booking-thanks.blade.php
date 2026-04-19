<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Thank You | Vina</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, 'Times New Roman', serif;
            background: #f7f4f2;
            color: #3d2b28;
        }
        .embed-shell {
            max-width: 40rem;
            margin: 0 auto;
            padding: 1.75rem clamp(1.25rem, 4vw, 2.5rem) 2.5rem;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: clamp(1.5rem, 3vw, 2.25rem) clamp(1.25rem, 3vw, 2rem);
            box-shadow: 0 1px 3px rgba(61, 43, 40, 0.08);
            text-align: center;
        }
        .brand img {
            display: block;
            margin: 0 auto 1.25rem;
            max-width: min(18rem, 100%);
            height: auto;
        }
        h1 {
            font-size: 1.5rem;
            margin: 0 0 0.75rem;
        }
        p {
            font-family: system-ui, sans-serif;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #6b534d;
            margin: 0 0 1.5rem;
        }
        a {
            font-family: system-ui, sans-serif;
            display: inline-block;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 999px;
            padding: 0.75rem 1.5rem;
            background: #a36868;
            color: #fff;
        }
        a:hover { background: #8f5a5a; }
    </style>
</head>
<body>
<div class="embed-shell">
    <div class="card">
        <div class="brand">
            <img src="{{ asset('images/vina-logo.png') }}" alt="Vina">
        </div>
        <h1>Thank you</h1>
        <p>Your booking request was received. We will confirm shortly.</p>
        <p><a href="{{ route('embed.booking') }}">Book another appointment</a></p>
    </div>
</div>
</body>
</html>
