<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sandbox payment</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f4f5; color: #18181b; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        main { background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 24px; width: min(360px, calc(100vw - 32px)); }
        .badge { display: inline-block; font-size: 11px; font-weight: 600; letter-spacing: .05em; color: #92400e; background: #fef3c7; border-radius: 6px; padding: 2px 8px; }
        label { display: block; font-size: 13px; margin: 16px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d4d4d8; border-radius: 8px; font-size: 15px; }
        button { margin-top: 16px; width: 100%; padding: 10px; border: 0; border-radius: 8px; background: #18181b; color: #fff; font-size: 15px; cursor: pointer; }
        button.secondary { background: transparent; color: #52525b; }
        p.muted { color: #71717a; font-size: 13px; }
    </style>
</head>
<body>
<main>
    <span class="badge">SANDBOX</span>
    <h1 style="font-size: 20px; margin: 12px 0 4px">{{ $amount }}</h1>
    <p class="muted">{{ $description }} · {{ $reference }}</p>
    <form method="post" action="{{ route('billing.sandbox.checkout.pay', $reference) }}">
        @csrf
        <label for="card_number">Test card number</label>
        <input id="card_number" name="card_number" value="4242 4242 4242 4242" inputmode="numeric" autocomplete="off">
        <p class="muted">4242… pays · 4000 0000 0000 0002 declines · 4000 0000 0000 0077 stays pending</p>
        <button type="submit">Pay {{ $amount }}</button>
        <button type="submit" name="cancel" value="1" class="secondary">Cancel and return</button>
    </form>
</main>
</body>
</html>
