<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found — Puzzlebox</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
            background: #f3f4f6;
            color: #111827;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #111827; color: #f9fafb; }
            .card { background: #1f2937; border-color: #374151; }
            .back { color: #6366f1; }
            .back:hover { color: #818cf8; }
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            max-width: 28rem;
            width: 100%;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        .code {
            font-size: 4rem;
            font-weight: 800;
            color: #6366f1;
            line-height: 1;
            margin-bottom: 1rem;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        p {
            font-size: 0.9rem;
            color: #6b7280;
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }
        .back {
            display: inline-block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #6366f1;
            text-decoration: none;
        }
        .back:hover { color: #4f46e5; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">404</div>
        <h1>Page not found</h1>
        <p>The page you're looking for doesn't exist, or the puzzle may have been removed.</p>
        <a href="/sudoku" class="back">← Back to puzzles</a>
    </div>
</body>
</html>
