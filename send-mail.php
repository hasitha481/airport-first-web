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
 * PASSWORD: SMTP_PASS below is a placeholder. Paste the mailbox password from
 * cPanel > Email Accounts before uploading, and keep the filled-in copy out of
 * git. PHP source is executed rather than served, so the password is not
 * readable over the web unless PHP itself stops running.
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
   mailbox. Port 465 pairs with 'ssl', port 587 with 'tls'; the two are not
   interchangeable. */
const SMTP_HOST   = 'mail.airportfirst.vip';   // 'localhost' if DNS or the certificate misbehaves
const SMTP_PORT   = 465;
const SMTP_SECURE = 'ssl';
const SMTP_USER   = MAIL_FROM;                 // the mailbox authenticates as the address it sends from
const SMTP_PASS   = 'PUT-MAILBOX-PASSWORD-HERE';
const SMTP_DEBUG  = false;                     // true logs the whole SMTP conversation to the PHP error log

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

/** Send a JSON response and stop. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

/* Accepts the JSON body the page sends, and falls back to a normal form POST
   so the endpoint also works from a plain <form> without JavaScript. */
$raw   = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input) || $input === []) {
    respond(400, ['ok' => false, 'error' => 'Empty request']);
}

/**
 * Trim, cap length and strip control characters from a submitted value.
 * Line breaks are removed from every field except the notes, so nothing that
 * reaches a mail header can carry a CR/LF (the header-injection vector).
 */
function field(array $src, string $key, int $max = 300, bool $multiline = false): string
{
    $value = isset($src[$key]) && is_scalar($src[$key]) ? (string) $src[$key] : '';
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
    respond(422, ['ok' => false, 'error' => 'Name and a phone or email are required']);
}

$guestEmail = filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? $data['email'] : '';

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
        '<tr>'
        . '<td style="padding:8px 14px;color:#64748B;font-size:13px;white-space:nowrap;vertical-align:top">%s</td>'
        . '<td style="padding:8px 14px;color:#0F172A;font-size:14px;font-weight:600">%s</td>'
        . '</tr>',
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))
    );
}

$html = '<!doctype html><html><body style="margin:0;background:#FAFAFB;font-family:Arial,Helvetica,sans-serif">'
    . '<div style="max-width:620px;margin:24px auto;background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:16px;overflow:hidden">'
    . '<div style="background:#C8102E;padding:20px 24px">'
    . '<div style="color:#fff;font-size:19px;font-weight:bold;font-style:italic">' . SITE_NAME . '</div>'
    . '<div style="color:rgba(255,255,255,.75);font-size:10px;letter-spacing:.30em;text-transform:uppercase;margin-top:4px">Feel the Ambience</div>'
    . '</div>'
    . '<div style="padding:22px 10px">'
    . '<p style="margin:0 14px 14px;color:#0F172A;font-size:15px;font-weight:bold">New website inquiry</p>'
    . '<table style="width:100%;border-collapse:collapse">' . $tableRows . '</table>'
    . '<p style="margin:18px 14px 0;color:#64748B;font-size:12px">'
    . 'Reply within 15 minutes to hold the best-rate guarantee.</p>'
    . '</div></div></body></html>';

/* Plain-text alternative, for clients that will not render HTML. Lines are
   joined with CRLF because quoted_printable_encode() only treats a full CRLF
   as a hard line break — a bare \n would be encoded as a literal "=0A". */
$text = "New website inquiry\r\n\r\n";
foreach ($rows as $label => $value) {
    if ($value !== '') {
        $text .= $label . ': ' . preg_replace('/\r\n|\r|\n/', "\r\n", $value) . "\r\n";
    }
}

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

    $mail = new PHPMailer(true);   // true = throw on failure instead of returning false

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->Timeout    = 20;        // a wedged handshake must not hold the guest's browser open

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
 *   535 Incorrect authentication data  — SMTP_PASS is wrong, or the mailbox
 *       does not exist yet (cPanel > Email Accounts).
 *   Could not connect to SMTP host     — the hostname resolves off-server or
 *       the port is firewalled. Try SMTP_HOST 'localhost', which stays inside
 *       the machine, before trying the IP.
 *   certificate verify failed          — the hostname does not match the
 *       server's certificate. 'localhost' avoids this too.
 *
 * Port 465 wants SMTP_SECURE 'ssl'; port 587 wants 'tls'. They are not
 * interchangeable — a mismatch just hangs until Timeout.
 */
