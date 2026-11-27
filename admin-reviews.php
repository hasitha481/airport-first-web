<?php
/**
 * Airport First — moderation page (reviews + recent inquiries)
 * -----------------------------------------------------------------------------
 * One password, set as a hash in ../airportfirst-db.php ('admin_password_hash').
 * Generate it once in cPanel → Terminal (or any PHP):
 *     php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
 *
 * Security: password_verify(); HTTP-only, SameSite=Strict session cookie,
 * renewed at sign-in and expiring after 30 idle minutes; a CSRF token on every
 * action; at most 5 failed sign-ins per visitor per 15 minutes; every value
 * HTML-escaped; strict CSP; not indexed, not cached, not frameable.
 */

declare(strict_types=1);

require_once __DIR__ . '/af-common.php';
require_once __DIR__ . '/db-config.php';

const ADMIN_IDLE_SECONDS   = 1800;
const ADMIN_MAX_FAILURES   = 5;
const ADMIN_LOCK_MINUTES   = 15;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$isLocal = in_array(strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]), ['localhost', '127.0.0.1'], true);
session_name('af_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/',
    'secure'   => !$isLocal,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && is_string($_POST['csrf']) && hash_equals((string) $_SESSION['csrf'], $_POST['csrf']);
}

function self_url(array $query = []): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return ($path !== '' ? $path : 'admin-reviews.php') . ($query ? '?' . http_build_query($query) : '');
}

function redirect(array $query = []): void
{
    header('Location: ' . self_url($query), true, 303);
    exit;
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($m) ? $m : null;
}

$pdo = af_db();
$configured = ADMIN_PASSWORD_HASH !== '' && $pdo !== null;
$ipHash = af_ip_hash(AF_HASH_SALT);

/* Idle timeout */
if (!empty($_SESSION['admin']) && (time() - (int) ($_SESSION['seen'] ?? 0)) > ADMIN_IDLE_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Signed out after 30 minutes without activity.');
}
if (!empty($_SESSION['admin'])) {
    $_SESSION['seen'] = time();
}
$signedIn = !empty($_SESSION['admin']);

// ---------------------------------------------------------------------------
// Actions (POST only, CSRF-checked)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Your session expired — please try again.');
        redirect();
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login' && $configured && !$signedIn) {
        $fails = $pdo->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip_hash = ? AND attempted_at > (NOW() - INTERVAL ' . ADMIN_LOCK_MINUTES . ' MINUTE)');
        $fails->execute([$ipHash]);
        if ((int) $fails->fetchColumn() >= ADMIN_MAX_FAILURES) {
            flash('Too many attempts. Please wait ' . ADMIN_LOCK_MINUTES . ' minutes and try again.');
            redirect();
        }
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && password_verify($password, ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['seen'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = ?')->execute([$ipHash]);
            redirect();
        }
        $pdo->prepare('INSERT INTO admin_login_attempts (ip_hash) VALUES (?)')->execute([$ipHash]);
        usleep(400000);
        flash('That password is not right.');
        redirect();
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect();
    }

    if ($signedIn && $pdo !== null) {
        $id  = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $tab = ($_POST['tab'] ?? '') === 'inquiries' ? 'inquiries' : 'reviews';
        if ($id !== false) {
            switch ($action) {
                case 'approve':
                    $pdo->prepare('UPDATE guest_reviews SET is_approved = 1 WHERE id = ?')->execute([$id]);
                    flash('Review approved — it is now live on the website.');
                    break;
                case 'unpublish':
                    $pdo->prepare('UPDATE guest_reviews SET is_approved = 0 WHERE id = ?')->execute([$id]);
                    flash('Review unpublished.');
                    break;
                case 'delete':
                    $pdo->prepare('DELETE FROM guest_reviews WHERE id = ?')->execute([$id]);
                    flash('Review deleted.');
                    break;
                case 'handled':
                    $pdo->prepare('UPDATE airport_inquiries SET handled = 1 WHERE id = ?')->execute([$id]);
                    flash('Inquiry marked as handled.');
                    break;
                case 'unhandled':
                    $pdo->prepare('UPDATE airport_inquiries SET handled = 0 WHERE id = ?')->execute([$id]);
                    flash('Inquiry marked as open.');
                    break;
            }
        }
        redirect(['tab' => $tab]);
    }
    redirect();
}

// ---------------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------------
$tab = ($_GET['tab'] ?? '') === 'inquiries' ? 'inquiries' : 'reviews';
$reviews = $inquiries = [];
$pendingCount = 0;
if ($signedIn && $pdo !== null) {
    $reviews = $pdo->query(
        'SELECT id, guest_name, country, rating, trip_type, review_text, avatar_data, is_approved, created_at
           FROM guest_reviews ORDER BY is_approved ASC, created_at DESC LIMIT 150'
    )->fetchAll();
    $pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM guest_reviews WHERE is_approved = 0')->fetchColumn();
    if ($tab === 'inquiries') {
        $inquiries = $pdo->query(
            'SELECT id, ref_code, guest_name, phone, email, pickup, dropoff, route_stops, route_legs, distance_km, distance_text, travel_date,
                    travel_time, flight_number, vehicle, passengers, special_requests, email_sent, handled, created_at
               FROM airport_inquiries ORDER BY created_at DESC LIMIT 60'
        )->fetchAll();

        /* Every card shows a distance. A row saved without one (an old cached
           page, or the route service was down) gets a road estimate from the
           place names, which is also written back so the table has it too. */
        require_once __DIR__ . '/af-places.php';
        $fill = null;
        foreach ($inquiries as &$q) {
            if ($q['distance_km'] !== null) {
                continue;
            }
            $text = (string) $q['distance_text'];
            if ($text !== '' && preg_match('/^~?([0-9]+(?:[.,][0-9]+)?) km/u', $text, $dm) === 1) {
                $q['distance_km'] = (float) str_replace(',', '.', $dm[1]);
            } else {
                $stops = (string) $q['route_stops'] === '' ? [] : array_map('trim', explode('→', (string) $q['route_stops']));
                $est = af_route_estimate((string) $q['pickup'], $stops, (string) $q['dropoff']);
                if ($est === null) {
                    continue;
                }
                $q['distance_km'] = $est['km'];
                $q['distance_text'] = $est['text'];
                if ((string) $q['route_legs'] === '') {
                    $q['route_legs'] = $est['legs'];
                }
            }
            try {
                $fill = $fill ?? $pdo->prepare(
                    'UPDATE airport_inquiries SET distance_km = ?, distance_text = ?, route_legs = ? WHERE id = ? AND distance_km IS NULL'
                );
                $fill->execute([$q['distance_km'], $q['distance_text'], (string) $q['route_legs'], (int) $q['id']]);
            } catch (Throwable $err) {
                error_log('[AirportFirst] could not fill the distance for ' . $q['ref_code'] . ': ' . $err->getMessage());
            }
        }
        unset($q);
    }
}
$message = flash();
$token = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Airport First · Moderation</title>
<style>
  :root { --red:#C8102E; --navy:#0F172A; --muted:#64748B; --line:#E2E8F0; --bg:#F8FAFC; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color:var(--navy); background:var(--bg); }
  header { background:#fff; border-bottom:1px solid var(--line); padding:14px 20px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
  header strong { color:var(--red); font-size:17px; }
  main { max-width:980px; margin:0 auto; padding:20px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:18px; margin-bottom:14px; }
  .tabs { display:flex; gap:8px; margin-bottom:16px; }
  .tabs a { padding:9px 16px; border-radius:999px; text-decoration:none; color:var(--navy); background:#fff; border:1px solid var(--line); font-weight:600; font-size:14px; }
  .tabs a.on { background:var(--navy); color:#fff; border-color:var(--navy); }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:700; }
  .pending { background:#FEF3C7; color:#92400E; } .live { background:#DCFCE7; color:#166534; } .open { background:#FEE2E2; color:#991B1B; }
  .row { display:flex; gap:14px; align-items:flex-start; }
  .row img { width:48px; height:48px; border-radius:999px; object-fit:cover; flex:none; }
  .meta { color:var(--muted); font-size:13px; }
  .stars { color:var(--red); letter-spacing:2px; }
  .actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
  form.inline { display:inline; margin:0; }
  button { font:inherit; font-size:14px; font-weight:700; border:0; border-radius:10px; padding:9px 14px; cursor:pointer; }
  .b-ok { background:#16A34A; color:#fff; } .b-mute { background:#E2E8F0; color:var(--navy); } .b-del { background:#fff; color:#B91C1C; border:1px solid #FECACA; } .b-main { background:var(--red); color:#fff; }
  .flash { background:#EFF6FF; border:1px solid #BFDBFE; color:#1E3A8A; border-radius:12px; padding:10px 14px; margin-bottom:14px; }
  .login { max-width:380px; margin:12vh auto; }
  input[type=password] { width:100%; font-size:16px; padding:12px 14px; border:1px solid var(--line); border-radius:12px; margin:8px 0 14px; }
  dl { display:grid; grid-template-columns:max-content 1fr; gap:4px 14px; margin:10px 0 0; font-size:14px; }
  dt { color:var(--muted); } dd { margin:0; word-break:break-word; }
  p.text { white-space:pre-line; margin:8px 0 0; }
  dd a { color:var(--navy); }
  .km { font-size:16px; }
  .b-copy { margin-left:8px; padding:3px 10px; font-size:12px; border-radius:8px; background:#E2E8F0; color:var(--navy); }
  @media (max-width:560px) { dl { grid-template-columns:1fr; } dt { margin-top:6px; } }
</style>
</head>
<body>
<?php if (!$signedIn): ?>
  <div class="login card">
    <h1 style="margin:0 0 4px;font-size:22px;">Airport First</h1>
    <p class="meta" style="margin:0 0 14px;">Moderation — reviews and inquiries</p>
    <?php if ($message): ?><div class="flash"><?= e($message) ?></div><?php endif; ?>
    <?php if (!$configured): ?>
      <p>This page is not set up yet. Add <code>admin_password_hash</code> and the database details to
        <code>airportfirst-db.php</code> above <code>public_html</code> (see DEPLOYMENT.md).</p>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <label for="pw" style="font-weight:600;">Password</label>
        <input id="pw" type="password" name="password" required autofocus autocomplete="current-password">
        <button class="b-main" type="submit" style="width:100%;">Sign in</button>
      </form>
    <?php endif; ?>
  </div>
<?php else: ?>
  <header>
    <div><strong>Airport First</strong> <span class="meta">· Moderation</span></div>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <button class="b-mute" type="submit">Sign out</button>
    </form>
  </header>
  <main>
    <?php if ($message): ?><div class="flash"><?= e($message) ?></div><?php endif; ?>
    <nav class="tabs">
      <a href="<?= e(self_url(['tab' => 'reviews'])) ?>" class="<?= $tab === 'reviews' ? 'on' : '' ?>">Reviews<?= $pendingCount ? ' (' . $pendingCount . ' waiting)' : '' ?></a>
      <a href="<?= e(self_url(['tab' => 'inquiries'])) ?>" class="<?= $tab === 'inquiries' ? 'on' : '' ?>">Inquiries</a>
    </nav>

    <?php if ($tab === 'reviews'): ?>
      <?php if (!$reviews): ?><div class="card meta">No reviews yet.</div><?php endif; ?>
      <?php foreach ($reviews as $r): $live = (int) $r['is_approved'] === 1; ?>
        <div class="card">
          <div class="row">
            <?php if (is_string($r['avatar_data']) && preg_match('~^data:image/(jpeg|png|webp);base64,~', $r['avatar_data']) === 1): ?>
              <img src="<?= e($r['avatar_data']) ?>" alt="">
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
              <span class="badge <?= $live ? 'live' : 'pending' ?>"><?= $live ? 'Live on website' : 'Waiting for approval' ?></span>
              <div style="margin-top:6px;"><strong><?= e($r['guest_name']) ?></strong>
                <span class="meta"><?= $r['country'] !== '' ? '· ' . e($r['country']) : '' ?><?= $r['trip_type'] !== '' ? ' · ' . e($r['trip_type']) : '' ?> · <?= e($r['created_at']) ?></span></div>
              <div class="stars" aria-label="<?= (int) $r['rating'] ?> out of 5"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?></div>
              <p class="text"><?= e($r['review_text']) ?></p>
              <div class="actions">
                <?php foreach ($live ? ['unpublish' => ['Unpublish', 'b-mute']] : ['approve' => ['Approve', 'b-ok']] as $act => [$label, $cls]): ?>
                  <form method="post" class="inline">
                    <input type="hidden" name="action" value="<?= e($act) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="tab" value="reviews"><input type="hidden" name="csrf" value="<?= e($token) ?>">
                    <button class="<?= e($cls) ?>" type="submit"><?= e($label) ?></button>
                  </form>
                <?php endforeach; ?>
                <form method="post" class="inline" onsubmit="return confirm('Delete this review permanently? This cannot be undone.');">
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="tab" value="reviews"><input type="hidden" name="csrf" value="<?= e($token) ?>">
                  <button class="b-del" type="submit">Delete</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

    <?php else: ?>
      <?php if (!$inquiries): ?><div class="card meta">No inquiries yet.</div><?php endif; ?>
      <?php foreach ($inquiries as $q): $done = (int) $q['handled'] === 1; ?>
        <div class="card">
          <span class="badge <?= $done ? 'live' : 'open' ?>"><?= $done ? 'Handled' : 'Open' ?></span>
          <strong style="margin-left:6px;"><?= e($q['ref_code']) ?></strong>
          <span class="meta">· <?= e($q['created_at']) ?><?= (int) $q['email_sent'] === 1 ? '' : ' · email not sent' ?></span>
          <dl>
            <dt>Guest</dt><dd><?= e($q['guest_name']) ?></dd>
            <dt>Phone</dt><dd><?= e($q['phone']) ?></dd>
            <?php if ((string) $q['email'] !== ''): ?><dt>Email</dt><dd>
              <!--email_off--><a href="mailto:<?= e($q['email']) ?>"><?= e($q['email']) ?></a>
              <button type="button" class="b-copy" data-copy="<?= e($q['email']) ?>">Copy</button><!--/email_off-->
            </dd><?php endif; ?>
            <dt>Route</dt><dd><?= e($q['pickup']) ?> → <?= e($q['dropoff']) ?></dd>
            <?php if ($q['route_stops'] !== ''): ?><dt>Via</dt><dd><?= e($q['route_stops']) ?></dd><?php endif; ?>
            <dt>Distance</dt><dd>
              <?php if ($q['distance_km'] !== null): $isRoad = strpos((string) $q['distance_text'], ' by road') !== false; ?>
                <strong class="km"><?= e(af_fmt_km((float) $q['distance_km'])) ?></strong>
                <span class="meta"><?= $isRoad ? '(road route)' : '(est.)' ?></span>
                <?php if ((string) $q['distance_text'] !== ''): ?><div class="meta"><?= e((string) $q['distance_text']) ?></div><?php endif; ?>
                <?php if ((string) $q['route_legs'] !== ''): ?><div class="meta">Legs: <?= e((string) $q['route_legs']) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="meta">Not calculated — the places were not recognised; confirm with the guest.</span>
              <?php endif; ?>
            </dd>
            <dt>When</dt><dd><?= e((string) $q['travel_date']) ?> <?= e($q['travel_time']) ?><?= $q['flight_number'] !== '' ? ' · flight ' . e($q['flight_number']) : '' ?></dd>
            <dt>Vehicle</dt><dd><?= e($q['vehicle']) ?><?= $q['passengers'] !== null ? ' · ' . (int) $q['passengers'] . ' pax' : '' ?></dd>
            <?php if ((string) $q['special_requests'] !== ''): ?><dt>Notes</dt><dd style="white-space:pre-line;"><?= e((string) $q['special_requests']) ?></dd><?php endif; ?>
          </dl>
          <div class="actions">
            <form method="post" class="inline">
              <input type="hidden" name="action" value="<?= $done ? 'unhandled' : 'handled' ?>"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
              <input type="hidden" name="tab" value="inquiries"><input type="hidden" name="csrf" value="<?= e($token) ?>">
              <button class="<?= $done ? 'b-mute' : 'b-ok' ?>" type="submit"><?= $done ? 'Mark as open' : 'Mark as handled' ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
<?php endif; ?>
<script>
  /* One-click copy for the guest's email address. */
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-copy]') : null;
    if (!btn) return;
    var value = btn.getAttribute('data-copy');
    var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done, function () { window.prompt('Copy the address:', value); });
    } else {
      window.prompt('Copy the address:', value);
    }
  });
</script>
</body>
</html>
