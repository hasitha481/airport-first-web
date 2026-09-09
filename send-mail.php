<?php
/**
 * Airport First — inquiry mailer
 * -----------------------------------------------------------------------------
 * Receives the JSON payload posted by index.html and emails it to the dispatch
 * desk over authenticated SMTP, using the site's own cPanel mailbox. No API
 * key and no third-party account: the only dependency is PHPMailer.
 *
 * UPLOAD: place this file in the same folder as index.html (usually
 * public_html/), and put PHPMailer beside it as PHPMailer/src/*.php --
 * download the release zip from github.com/PHPMailer/PHPMailer and upload the
 * src folder, or run `composer require phpmailer/phpmailer` if the plan has
 * Composer, in which case vendor/autoload.php is picked up instead.
 *
 * ADDRESSES: mail always leaves as MAIL_FROM, a mailbox on the site's own
 * domain, because that is the only domain this server is allowed to send for.
 * A guest's Gmail (or the dispatch desk's) in the From line fails Gmail's
 * SPF/DMARC checks and gets bounced or filtered as spoofed. The guest stays
 * reachable through Reply-To instead, which no SPF check looks at.
 *
 * SETUP: create MAIL_FROM in cPanel > Email Accounts before go-live, and make
 * sure the domain's SPF record covers this server. MAIL_TO is only where the
 * mail lands, so it can stay a Gmail address.
 *
 * PASSWORD: the mailbox password is NOT in this file. It is read from
 * ../airportfirst-smtp.php, one level above public_html, where Apache cannot
 * serve it and no commit can carry it. See SMTP_PASS below.
 *
 * WHAT GUARDS THIS ENDPOINT: it answers POST only, from this site's own origin
 * only, at a capped rate per caller, on a body it size-checks before parsing.
 * Every field is validated for shape as well as length, CR/LF can never reach
 * a mail header, and every failure answers with a fixed sentence while the
 * detail goes to the PHP error log.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const MAIL_TO        = 'mhasitha481@gmail.com';       // TESTING — client's airportfirst1@gmail.com for go-live
const MAIL_FROM      = 'noreply@airportfirst.vip';    // must be a real mailbox on the sending domain
const MAIL_FROM_NAME = 'Airport First Dispatch';
const SITE_NAME      = 'Airport First';

/* SMTP — cPanel > Email Accounts > Connect Devices lists all of these for the
   mailbox. Port 25 pairs with '', 587 with 'tls', 465 with 'ssl'. */
const SMTP_HOST   = 'localhost';               // the mail server on this machine; the session never leaves the box
const SMTP_PORT   = 587;                       // 587 with 'tls'; 25 with SMTP_SECURE '' is the plain-loopback alternative
const SMTP_SECURE = 'tls';
const SMTP_USER   = MAIL_FROM;                 // the mailbox authenticates as the address it sends from
const SMTP_DEBUG  = false;                     // true logs the whole SMTP conversation to the PHP error log

/* Only this site may post here. A request carrying someone else's Origin, or
   no Origin and no Referer at all, is refused: the page is static, so there is
   no session to hang a CSRF token on, and matching the origin is what a static
   form can do. The server's own hostname is accepted too, so a temporary cPanel
   URL or a staging domain works without editing this list. */
const ALLOWED_HOSTS = ['airportfirst.vip', 'www.airportfirst.vip'];

/* Anti-flood. A guest sends one inquiry; these numbers only bite a script.
   Both are per caller: one submission every RATE_BURST seconds, and at most
   RATE_MAX inside RATE_WINDOW. */
const RATE_WINDOW = 600;                       // seconds in the sliding window
const RATE_MAX    = 5;                         // submissions allowed per window
const RATE_BURST  = 20;                        // seconds between two submissions
const RATE_SALT   = 'airport-first/throttle';  // only seasons the hash of an IP

const MAX_BODY_BYTES = 16384;                  // a full inquiry is well under 2 KB

/* The password lives outside the web root and outside git, in a file that
   returns it:

       <?php return 'the mailbox password';

   Put it at ../airportfirst-smtp.php — one level ABOVE public_html, beside the
   fallback log, where Apache has nothing to serve and no commit can pick it
   up. Without it authentication fails, the inquiry is kept in the fallback
   log, and the error log says so in as many words. */
$smtpPasswordFile = dirname(__DIR__) . '/airportfirst-smtp.php';
define('SMTP_PASS', is_readable($smtpPasswordFile) ? trim((string) require $smtpPasswordFile) : '');

/* No Composer on a stock cPanel account, so PHPMailer is loaded from whichever
   of these is actually present. A missing library is reported by
   send_smtp_mail() rather than being fatal here, so the inquiry still reaches
   the fallback log. */
foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/PHPMailer/src/Exception.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/PHPMailer/src/SMTP.php',
] as $dependency) {
    if (is_readable($dependency)) {
        require_once $dependency;
    }
}

// ---------------------------------------------------------------------------
// Plumbing
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');             // a route that handles guest contact details

/* Nothing about this server's internals belongs in an HTTP response: a warning
   printed ahead of the JSON would leak paths and break the parse in index.html
   at the same time. Everything is logged instead. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/** Send a JSON response and stop. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Text from the caller must not be able to forge extra lines in the log. */
function log_safe(string $value, int $max = 120): string
{
    return substr(preg_replace('/[^\x20-\x7E]/', '.', $value) ?? '', 0, $max);
}

/* An uncaught throwable or a fatal would otherwise print a stack trace, with
   absolute paths in it, straight into the response body. Both are answered
   with the same sentence every other failure gets. */
set_exception_handler(static function (Throwable $e): void {
    error_log('[AirportFirst] uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    respond(500, ['ok' => false, 'error' => 'The dispatch desk could not be reached']);
});

register_shutdown_function(static function (): void {
    $fatal = error_get_last();
    if ($fatal === null
        || !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    error_log('[AirportFirst] fatal: ' . $fatal['message'] . ' in ' . $fatal['file'] . ':' . $fatal['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'The dispatch desk could not be reached']);
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

// ---------------------------------------------------------------------------
// Who is calling
// ---------------------------------------------------------------------------

/** The host the browser says it is posting from: Origin, or Referer if absent. */
function request_host(): string
{
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $value = (string) ($_SERVER[$header] ?? '');
        if ($value === '') {
            continue;
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }
    }
    return '';
}

$origin   = request_host();
$allowed  = ALLOWED_HOSTS;
$selfHost = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
if ($selfHost !== '') {
    $allowed[] = $selfHost;
}

/* Browsers send Origin on every POST, so a blank one is a script rather than a
   guest. If a proxy in front of the site ever strips it, this is the line to
   relax — not the list above. */
if ($origin === '' || !in_array($origin, $allowed, true)) {
    error_log('[AirportFirst] refused a post from origin "' . log_safe($origin) . '"');
    respond(403, ['ok' => false, 'error' => 'Request rejected']);
}

/**
 * A stable, non-identifying key for the caller.
 *
 * Cloudflare fronts this domain, so REMOTE_ADDR is an edge address and the
 * guest's own arrives in CF-Connecting-IP. That header is trusted for
 * throttling and nothing else — the worst a forged one can do is spend someone
 * else's allowance. The result is hashed, so the store never holds an IP.
 */
function client_key(): string
{
    $ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $edge = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($edge !== '' && filter_var($edge, FILTER_VALIDATE_IP) !== false) {
        $ip = $edge;
    }
    return hash('sha256', RATE_SALT . '|' . $ip);
}

/** Counters live above the web root when that is writable, /tmp otherwise. */
function throttle_dir(): ?string
{
    foreach ([dirname(__DIR__) . '/af-throttle', sys_get_temp_dir() . '/af-throttle'] as $dir) {
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            return $dir;
        }
    }
    return null;
}

/** Drop counters nobody has touched in a day, now and then rather than always. */
function throttle_sweep(string $dir, int $now): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    foreach ((array) glob($dir . '/*') as $file) {
        if (is_string($file) && is_file($file) && (int) @filemtime($file) < $now - 86400) {
            @unlink($file);
        }
    }
}

/**
 * Sliding window per caller: 0 when the request may proceed, otherwise the
 * seconds left to wait.
 *
 * A store that cannot be written fails open — a broken counter must not stop a
 * real guest booking a car — and says so in the error log.
 */
function throttle_retry_after(): int
{
    $dir = throttle_dir();
    if ($dir === null) {
        error_log('[AirportFirst] no writable throttle store — rate limiting is off');
        return 0;
    }

    $handle = @fopen($dir . '/' . client_key(), 'c+');
    if ($handle === false) {
        error_log('[AirportFirst] could not open the throttle counter — rate limiting is off');
        return 0;
    }

    flock($handle, LOCK_EX);
    $now    = time();
    $stamps = [];
    foreach (explode(',', (string) stream_get_contents($handle)) as $token) {
        $stamp = (int) $token;
        if ($stamp > $now - RATE_WINDOW) {
            $stamps[] = $stamp;
        }
    }

    $retry = 0;
    if ($stamps !== [] && $now - max($stamps) < RATE_BURST) {
        $retry = RATE_BURST - ($now - max($stamps));
    } elseif (count($stamps) >= RATE_MAX) {
        $retry = RATE_WINDOW - ($now - min($stamps));
    }

    if ($retry <= 0) {
        $retry    = 0;
        $stamps[] = $now;          // only a request we are letting through counts
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, implode(',', $stamps));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    throttle_sweep($dir, $now);

    return $retry;
}

$retryAfter = throttle_retry_after();
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    respond(429, ['ok' => false, 'error' => 'Too many requests — please try again in a moment']);
}

// ---------------------------------------------------------------------------
// Read the request
// ---------------------------------------------------------------------------

/* Accepts the JSON body the page sends, and falls back to a normal form POST
   so the endpoint also works from a plain <form> without JavaScript. */
$raw = (string) file_get_contents('php://input');

if (strlen($raw) > MAX_BODY_BYTES) {
    error_log('[AirportFirst] refused a ' . strlen($raw) . '-byte body');
    respond(413, ['ok' => false, 'error' => 'Request too large']);
}

/* Invalid UTF-8 would make every /u pattern below fail silently and return
   null, so it is refused here rather than half-processed. */
if ($raw !== '' && !mb_check_encoding($raw, 'UTF-8')) {
    respond(400, ['ok' => false, 'error' => 'Malformed request']);
}

$input = json_decode($raw, true, 8);           // shallow: the payload is one flat object
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input) || $input === []) {
    respond(400, ['ok' => false, 'error' => 'Empty request']);
}

// ---------------------------------------------------------------------------
// Validate
// ---------------------------------------------------------------------------

/**
 * Trim, cap length and strip control characters from a submitted value.
 * Line breaks are removed from every field except the notes, so nothing that
 * reaches a mail header can carry a CR/LF (the header-injection vector).
 * Anything that is not a scalar, or not valid UTF-8, becomes an empty string.
 */
function field(array $src, string $key, int $max = 300, bool $multiline = false): string
{
    $value = isset($src[$key]) && is_scalar($src[$key]) ? (string) $src[$key] : '';
    if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $strip = $multiline
        ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'          // keep \r\n
        : '/[\x00-\x1F\x7F]/u';                          // drop \r\n too
    $value = preg_replace($strip, ' ', trim($value)) ?? '';
    return mb_substr(trim($value), 0, $max);
}

/** Header values must never contain a newline — that is how header injection works. */
function header_safe(string $value): string
{
    return trim(str_replace(["\r", "\n", "%0a", "%0d"], ' ', $value));
}

/** A person's name or a place name carries no markup and no link. */
function is_clean_line(string $value): bool
{
    return strpos($value, '<') === false
        && strpos($value, '>') === false
        && stripos($value, '://') === false;
}

/** How many links a value carries. Bots fill every box with them. */
function count_links(string $value): int
{
    return (int) preg_match_all('~(https?://|www\.)~i', $value);
}

/**
 * The form posts an <input type="date">, which is always YYYY-MM-DD, so
 * anything else is refused rather than guessed: 03/04/2026 is the 3rd of April
 * to a guest from Colombo and the 4th of March to one from Chicago, and a car
 * sent on the wrong day is worse than a form that asks again.
 */
function clean_date(string $value): ?string
{
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
}

/** <input type="time"> posts 24-hour HH:MM; a typed "9:30 pm" is normalised. */
function clean_time(string $value): ?string
{
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{1,2}):([0-5]\d)\s*([APap][Mm])?$/', $value, $m) !== 1) {
        return null;
    }
    $hour   = (int) $m[1];
    $suffix = strtolower($m[3] ?? '');
    if ($suffix === 'pm' && $hour < 12) {
        $hour += 12;
    } elseif ($suffix === 'am' && $hour === 12) {
        $hour = 0;
    }
    return $hour <= 23 ? sprintf('%02d:%s', $hour, $m[2]) : null;
}

/** One plain sentence for the guest; specifics go to the log, never the body. */
function reject(string $message): void
{
    respond(422, ['ok' => false, 'error' => $message]);
}

$data = [
    'name'    => field($input, 'name', 120),
    'phone'   => field($input, 'phone', 60),
    'email'   => field($input, 'email', 160),
    'pickup'  => field($input, 'pickup', 200),
    'dropoff' => field($input, 'dropoff', 200),
    'date'    => field($input, 'date', 40),
    'time'    => field($input, 'time', 40),
    'flight'  => field($input, 'flight', 40),
    'pax'     => field($input, 'pax', 20),
    'vehicle' => field($input, 'vehicle', 120),
    'notes'   => field($input, 'notes', 1200, true),
    'source'  => field($input, 'source', 60),
];

// A name and some way to reply are the minimum worth emailing about.
if ($data['name'] === '' || ($data['phone'] === '' && $data['email'] === '')) {
    reject('Name and a phone or email are required');
}

if (mb_strlen($data['name']) < 2 || !is_clean_line($data['name'])) {
    reject('That name does not look right');
}

if ($data['phone'] !== ''
    && (preg_match('~^[0-9+()/.\- ]{7,40}$~', $data['phone']) !== 1
        || preg_match_all('/\d/', $data['phone']) < 7)) {
    reject('That phone number does not look right');
}

if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
    reject('That email address does not look right');
}

foreach (['pickup' => 'Pick-up', 'dropoff' => 'Drop-off', 'vehicle' => 'Vehicle'] as $key => $label) {
    if ($data[$key] !== '' && !is_clean_line($data[$key])) {
        reject($label . ' should be a place or a vehicle name');
    }
}

$date = clean_date($data['date']);
if ($date === null) {
    reject('Use the date picker — the date should look like 2026-09-12');
}
$data['date'] = $date;

$time = clean_time($data['time']);
if ($time === null) {
    reject('The arrival time should look like 09:30');
}
$data['time'] = $time;

if ($data['pax'] !== ''
    && (preg_match('/^\d{1,2}$/', $data['pax']) !== 1
        || (int) $data['pax'] < 1 || (int) $data['pax'] > 60)) {
    reject('Passengers should be a number between 1 and 60');
}

if ($data['flight'] !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{0,11}$/', $data['flight']) !== 1) {
    reject('That flight number does not look right');
}

/* Link spam. A guest describing a trip does not need two links; a bot posting
   an advert always does. */
$links = 0;
foreach ($data as $value) {
    $links += count_links($value);
}
if ($links > 2) {
    error_log('[AirportFirst] dropped a submission carrying ' . $links . ' links');
    reject('Please describe the trip without links');
}

/* The source label is ours, not the guest's: it is the one field the page
   fills in by itself, so anything unexpected is dropped rather than forwarded
   into the mail. */
if (preg_match('/^[a-z0-9\-]{1,60}$/i', $data['source']) !== 1) {
    $data['source'] = '';
}

$guestEmail = $data['email'];

// ---------------------------------------------------------------------------
// Compose
// ---------------------------------------------------------------------------

$rows = [
    'Name'             => $data['name'],
    'Phone / WhatsApp' => $data['phone'],
    'Email'            => $data['email'],
    'Pick-up'          => $data['pickup'],
    'Drop-off'         => $data['dropoff'],
    'Date'             => $data['date'],
    'Arrival time'     => $data['time'],
    'Flight number'    => $data['flight'],
    'Passengers'       => $data['pax'],
    'Vehicle'          => $data['vehicle'],
    'Notes'            => $data['notes'],
    'Source'           => $data['source'] !== '' ? $data['source'] : 'website',
];

$subject = sprintf(
    'New inquiry — %s · %s to %s',
    $data['name'] !== '' ? $data['name'] : 'Website guest',
    $data['pickup'] !== '' ? $data['pickup'] : '?',
    $data['dropoff'] !== '' ? $data['dropoff'] : '?'
);

$tableRows = '';
foreach ($rows as $label => $value) {
    if ($value === '') {
        continue;
    }
    $tableRows .= sprintf(
        '<tr><td valign="top"><strong>%s</strong></td><td valign="top">%s</td></tr>',
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))
    );
}

/* Plain HTML: a heading, one table, one closing line. The card this used to
   send — background colours, rounded corners, a letter-spaced banner — was a
   wall of inline CSS wrapped around three lines of content, which is the shape
   bulk mail has, and it bought a dispatcher reading an inquiry nothing. There
   are no images, no links and nothing loaded from another host, so there is
   nothing here that a filter can read as tracking. */
$html = '<!doctype html><html><body>'
    . '<p><strong>New website inquiry</strong> — ' . SITE_NAME . '</p>'
    . '<table cellpadding="4" cellspacing="0" border="0">' . $tableRows . '</table>'
    . '<p>Reply within 15 minutes to hold the best-rate guarantee.</p>'
    . '</body></html>';

/* The plain-text alternative carries the whole booking, in the same order and
   aligned, so the message reads properly in a text-only client and does not
   look like an HTML-only blob to a filter. Lines are joined with CRLF because
   quoted-printable only treats a full CRLF as a hard line break — a bare \n
   would be encoded as a literal "=0A". */
$width = 0;
foreach ($rows as $label => $value) {
    if ($value !== '') {
        $width = max($width, strlen($label) + 2);
    }
}

$rule = str_repeat('-', 56);
$text = 'New website inquiry — ' . SITE_NAME . "\r\n" . $rule . "\r\n\r\n";

foreach ($rows as $label => $value) {
    if ($value === '') {
        continue;
    }
    $value = preg_replace('/\r\n|\r|\n/', "\r\n", $value);

    /* Notes usually run to several lines: put the label on its own line and
       indent the text under it, rather than wrapping it around a column. */
    if (strpos($value, "\r\n") !== false) {
        $text .= $label . ":\r\n";
        foreach (explode("\r\n", $value) as $noteLine) {
            $text .= '    ' . $noteLine . "\r\n";
        }
        continue;
    }

    $text .= str_pad($label . ':', $width) . $value . "\r\n";
}

$text .= "\r\n" . $rule . "\r\n"
    . "Reply within 15 minutes to hold the best-rate guarantee.\r\n";

// ---------------------------------------------------------------------------
// Deliver
// ---------------------------------------------------------------------------

/**
 * Hands the message to PHPMailer over authenticated SMTP.
 *
 * mail() dropped the message into the local queue as the web user, so it left
 * unauthenticated and unsigned and Gmail filed it as spam. Submitting it to the
 * mailbox's own SMTP account instead means the server signs it with the
 * domain's DKIM key and sends it as a sender it recognises.
 *
 * The encoding work done for mail() carries over rather than being repeated by
 * hand: PHPMailer encodes both parts quoted-printable (76-column soft breaks,
 * so nothing approaches Exim's 2048-byte line ceiling), normalises every break
 * to CRLF, and folds the RFC 2047 subject onto continuation lines itself.
 */
function send_smtp_mail(string $to, string $subject, string $html, string $text, string $replyTo): bool
{
    if (!class_exists(PHPMailer::class)) {
        error_log('[AirportFirst] PHPMailer missing — upload its src/ folder to ' . __DIR__ . '/PHPMailer/');
        return false;
    }

    if (SMTP_PASS === '') {
        error_log('[AirportFirst] no SMTP password — create ' . dirname(__DIR__)
            . '/airportfirst-smtp.php, a PHP file that returns the mailbox password as a string');
        return false;
    }

    $mail = new PHPMailer(true);   // true = throw on failure instead of returning false

    try {
        $mail->isSMTP();
        $mail->Host        = SMTP_HOST;
        $mail->Port        = SMTP_PORT;
        $mail->SMTPSecure  = SMTP_SECURE;
        $mail->SMTPAutoTLS = SMTP_SECURE !== '';   // on port 25 there is nothing to upgrade to
        $mail->SMTPAuth    = true;
        $mail->Username    = SMTP_USER;
        $mail->Password    = SMTP_PASS;
        $mail->Timeout     = 20;       // a wedged handshake must not hold the guest's browser open

        /* The server's certificate is issued for the domain, not for
           'localhost', so verifying it fails on a connection that never leaves
           the machine. Relaxed for the loopback only — a remote SMTP_HOST is
           still verified in full. */
        if (SMTP_HOST === 'localhost' || SMTP_HOST === '127.0.0.1') {
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];
        }

        /* Any SMTP chatter goes to the PHP error log. It must never reach the
           response body, which has to stay parseable JSON for index.html. */
        $mail->SMTPDebug   = SMTP_DEBUG ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
        $mail->Debugoutput = static function (string $line, int $level): void {
            error_log('[AirportFirst][smtp] ' . rtrim($line));
        };

        $mail->CharSet  = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;

        /* From stays the authenticated mailbox — the address the session logs
           in as, the one SPF and DKIM cover, and nothing a guest can steer. */
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->Sender = MAIL_FROM;              // envelope sender, which is what SPF checks
        $mail->addAddress($to);
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);        // the guest, and only ever the guest
        }
        $mail->XMailer = 'AirportFirst/1.0';

        $mail->Subject = $subject;              // PHPMailer encodes and folds this
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $text;

        return $mail->send();
    } catch (Throwable $e) {
        /* ErrorInfo carries the SMTP reply ("535 Incorrect authentication
           data", "Could not connect"), which is what you need to see in the
           cPanel error log when a deploy comes up silent. */
        error_log('[AirportFirst] SMTP send failed: ' . ($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}

$sent = send_smtp_mail(MAIL_TO, $subject, $html, $text, $guestEmail);

if (!$sent) {
    error_log('[AirportFirst] SMTP would not take the message for ' . MAIL_TO);

    /* Keep a copy on disk so no inquiry is lost when the mail server is down.
       It holds guest contact details, so it is written one level ABOVE the web
       root (outside public_html) where Apache cannot serve it. If that is not
       writable we fall back to this folder, where the shipped .htaccess denies
       access to *.log. */
    $line = date('c') . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (@file_put_contents(dirname(__DIR__) . '/inquiries-fallback.log', $line, FILE_APPEND | LOCK_EX) === false) {
        @file_put_contents(__DIR__ . '/inquiries-fallback.log', $line, FILE_APPEND | LOCK_EX);
    }
    respond(500, ['ok' => false, 'error' => 'Mail server rejected the message']);
}

respond(200, ['ok' => true]);

/* -----------------------------------------------------------------------------
 * If SMTP will not connect
 * -----------------------------------------------------------------------------
 * Set SMTP_DEBUG to true and read the [AirportFirst][smtp] lines in cPanel >
 * Errors, then work down this list:
 *
 *   535 Incorrect authentication data  — the password in
 *       ../airportfirst-smtp.php is wrong, or the mailbox does not exist yet
 *       (cPanel > Email Accounts).
 *   no SMTP password                   — that file is missing or unreadable.
 *   Could not connect to SMTP host     — nothing is listening on SMTP_PORT.
 *       Try port 25 with SMTP_SECURE '', which every cPanel box accepts on the
 *       loopback, or mail.airportfirst.vip on 465 with 'ssl' if the local
 *       server is genuinely off.
 *   SMTP connect() failed after STARTTLS — the local certificate was rejected;
 *       check that SMTP_HOST is spelled 'localhost' exactly, since that is
 *       what relaxes verification above.
 *
 * Port 25 wants SMTP_SECURE '', 587 wants 'tls' and 465 wants 'ssl'. They are
 * not interchangeable — a mismatch just hangs until Timeout.
 */
