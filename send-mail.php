<?php
/**
 * Airport First — inquiry mailer
 * -----------------------------------------------------------------------------
 * Receives the JSON payload posted by index.html and emails it to the dispatch
 * desk. Runs on stock cPanel PHP: no Composer, no API key, no third-party
 * account.
 *
 * UPLOAD: place this file in the same folder as index.html (usually
 * public_html/). Nothing else to configure beyond the two constants below.
 *
 * FROM ADDRESS: currently the tester's own Gmail, for dev convenience.
 * BEFORE GO-LIVE set MAIL_FROM back to a mailbox on the site's own domain
 * (create inquiries@airportfirst.lk in cPanel > Email Accounts). Sending "from"
 * a gmail.com address fails Gmail's SPF/DMARC checks, so live mail sent this
 * way is very likely to be filtered as spoofed.
 *
 * SMTP: PHP mail() is fine on most cPanel plans. If your host disables it, or
 * delivery to Gmail is unreliable, install PHPMailer and swap send_plain_mail()
 * for the SMTP block sketched at the bottom of this file.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const MAIL_TO   = 'mhasitha481@gmail.com';
const MAIL_FROM = 'mhasitha481@gmail.com';        // TESTING — restore inquiries@airportfirst.lk for go-live
const SITE_NAME = 'Airport First';

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
 * Sends a multipart/alternative message with PHP mail().
 *
 * Both parts go out quoted-printable. The HTML template is built as one
 * unbroken string well past 3000 characters, and Exim — the MTA behind cPanel —
 * bounces anything with a line over 2048 bytes ("message has lines too long
 * for transport"). quoted_printable_encode() soft-wraps at 76 columns, which
 * keeps every wire line far inside that limit.
 *
 * The encoding is declared on each MIME part rather than on the multipart
 * container: a multipart body must itself stay 7bit/8bit (RFC 2045 §6.4), and
 * encoding the whole thing would bury the boundaries where no client can see
 * them.
 */
function send_plain_mail(string $to, string $subject, string $html, string $text, string $replyTo): bool
{
    $boundary = 'af-' . bin2hex(random_bytes(8));

    /* header_safe() runs over every finished line, so nothing a guest typed can
       smuggle in a CR/LF and split the header block. Joined with CRLF, with no
       trailing break — mail() adds the blank line that ends the headers. */
    $headers = array_map('header_safe', [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . SITE_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . ($replyTo !== '' ? $replyTo : MAIL_FROM),
        'X-Mailer: AirportFirst/1.0',
    ]);

    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($text) . "\r\n\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode($html) . "\r\n\r\n"
        . "--$boundary--\r\n";

    /* RFC 2047 encoded-word, folded across continuation lines. A guest can
       fill 520 characters between the name and the two addresses, and once
       base64 has grown a subject that long it becomes a single header line
       past Exim's 2048-byte limit — the same bounce the body now avoids. The
       indent is the width of the "Subject: " that mail() puts in front. */
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n", strlen('Subject: '));

    // -f sets the envelope sender, which stops cPanel stamping the message as
    // coming from the web user and greatly improves Gmail deliverability.
    //
    // Deliberately silenced: on a host with display_errors on, a mail()
    // warning would print raw HTML ahead of our JSON and corrupt the response.
    // The boolean return plus error_log() below carry the failure instead.
    return @mail(
        $to,
        $encodedSubject,
        $body,
        implode("\r\n", $headers),
        '-f' . MAIL_FROM
    );
}

try {
    $sent = send_plain_mail(MAIL_TO, $subject, $html, $text, $guestEmail);
} catch (Throwable $e) {
    error_log('[AirportFirst] mail() threw: ' . $e->getMessage());
    $sent = false;
}

if (!$sent) {
    error_log('[AirportFirst] mail() refused the message for ' . MAIL_TO);

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
 * SMTP alternative
 * -----------------------------------------------------------------------------
 * If mail() is disabled on your plan, upload PHPMailer and replace the
 * send_plain_mail() call above with:
 *
 *   require __DIR__ . '/PHPMailer/src/PHPMailer.php';
 *   require __DIR__ . '/PHPMailer/src/SMTP.php';
 *   require __DIR__ . '/PHPMailer/src/Exception.php';
 *
 *   $mail = new PHPMailer\PHPMailer\PHPMailer(true);
 *   $mail->isSMTP();
 *   $mail->Host       = 'mail.airportfirst.lk';   // cPanel > Email Accounts > Connect Devices
 *   $mail->SMTPAuth   = true;
 *   $mail->Username   = MAIL_FROM;
 *   $mail->Password   = 'the mailbox password';   // keep outside the web root if you can
 *   $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
 *   $mail->Port       = 465;
 *   $mail->setFrom(MAIL_FROM, SITE_NAME);
 *   $mail->addAddress(MAIL_TO);
 *   if ($guestEmail !== '') { $mail->addReplyTo($guestEmail); }
 *   $mail->Subject = $subject;
 *   $mail->isHTML(true);
 *   $mail->Body    = $html;
 *   $mail->AltBody = $text;
 *   $mail->send();
 */
