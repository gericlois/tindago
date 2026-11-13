<?php
// A plain hosting-provider style "404 Not Found" page with no TindaGo
// branding. Used for:
//  - every member page while maintenance mode is on (module.php) — status
//    503 + Retry-After so search engines treat it as temporary;
//  - the hidden admin panel (includes/auth.php) and missing pages
//    (/404.php, the ErrorDocument) — set $status_code = 404 first.
$status_code = $status_code ?? 503;
http_response_code($status_code);
if ($status_code === 503) {
    header('Retry-After: 3600');
}
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>404 Not Found</title>
<style>
  :root {
    --ink: #111111;
    --muted: #595959;
    --line: #d4dbe0;
    --teal: #00838c;
    --teal-dark: #006a71;
    --bg: #ffffff;
    --band: #f5f7f8;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; height: 100%; }
  body {
    background: var(--bg);
    color: var(--ink);
    font-family: "Helvetica Neue", Helvetica, Arial, "Segoe UI", sans-serif;
    display: flex;
    flex-direction: column;
    -webkit-font-smoothing: antialiased;
  }
  .topbar { height: 6px; background: var(--ink); }
  main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 16px;
  }
  .card { max-width: 560px; width: 100%; }
  .code {
    font-size: clamp(88px, 22vw, 160px);
    font-weight: 800;
    line-height: .9;
    letter-spacing: -.04em;
    margin: 0 0 8px;
  }
  h1 {
    font-size: clamp(24px, 5vw, 32px);
    font-weight: 700;
    margin: 0 0 16px;
  }
  p { font-size: 16px; line-height: 1.6; color: var(--muted); margin: 0 0 28px; }
  .btn {
    display: inline-block;
    padding: 12px 28px;
    border: 2px solid var(--ink);
    border-radius: 999px;
    color: var(--ink);
    font-weight: 700;
    font-size: 15px;
    text-decoration: none;
    transition: background .15s, color .15s;
  }
  .btn:hover, .btn:focus-visible { background: var(--ink); color: #fff; outline: none; }
  .hint { margin-top: 36px; padding-top: 20px; border-top: 1px solid var(--line); font-size: 13px; color: var(--muted); }
  .hint a { color: var(--teal); }
  .hint a:hover { color: var(--teal-dark); }
  footer {
    background: var(--band);
    border-top: 1px solid var(--line);
    padding: 16px;
    font-size: 12px;
    color: var(--muted);
    text-align: center;
  }
</style>
</head>
<body>
  <div class="topbar"></div>
  <main>
    <div class="card">
      <div class="code">404</div>
      <h1>Page not found</h1>
      <p>The page you're looking for can't be found. It may have been moved, renamed, or is temporarily unavailable.</p>
      <a class="btn" href="<?= BASE_URL ?>/">Go to homepage</a>
      <div class="hint">If you typed the address, check that it's spelled correctly, or try again later.</div>
    </div>
  </main>
  <footer>Error 404 &middot; Not Found</footer>
</body>
</html>
