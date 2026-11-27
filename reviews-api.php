<?php
/**
 * Airport First — guest reviews API (MySQL)
 * -----------------------------------------------------------------------------
 * GET  reviews-api.php   Approved reviews, newest first (max 50), plus the exact
 *                        count and average rating of ALL approved reviews.
 * POST reviews-api.php   A new review from the website form. Saved with
 *                        is_approved = 0; it appears only after approval in
 *                        admin-reviews.php.
 *
 * POST is guarded like send-mail.php: this site's own origin only, a size cap,
 * strict validation, a honeypot field, and at most 2 reviews per visitor per
 * hour (the visitor's IP is Cloudflare-verified and stored only as a salted hash).
 */

declare(strict_types=1);

require_once __DIR__ . '/af-common.php';
require_once __DIR__ . '/db-config.php';

const REVIEW_MAX_BODY    = 131072;   // the optional photo is ~30 KB as base64; text is tiny
const REVIEW_PER_HOUR    = 2;
const REVIEW_TRIP_TYPES  = ['', 'Airport transfer', 'Private tour', 'Corporate travel', 'Hotel transfer', 'Group travel', 'Other'];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function reviews_respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[AirportFirst] reviews-api: ' . get_class($e) . ': ' . $e->getMessage());
    reviews_respond(500, ['ok' => false, 'error' => 'Reviews are unavailable right now']);
});

/** Trim, strip control characters (keeping line breaks only where allowed), cap length. */
function review_field(array $src, string $key, int $max, bool $multiline = false): string
{
    $value = isset($src[$key]) && is_scalar($src[$key]) ? (string) $src[$key] : '';
    if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $strip = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
    $value = trim(preg_replace($strip, ' ', $value) ?? '');
    if ($multiline) {
        $value = preg_replace("/\r\n?/", "\n", $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';
    }
    return mb_substr($value, 0, $max);
}

function review_is_clean_line(string $value): bool
{
    return strpos($value, '<') === false && strpos($value, '>') === false && stripos($value, '://') === false;
}

/** Only the small inline image the page's own compressor makes. */
function review_avatar(array $src): ?string
{
    $value = isset($src['avatar']) && is_string($src['avatar']) ? $src['avatar'] : '';
    if ($value === '' || strlen($value) > 60000) {
        return null;
    }
    return preg_match('~^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/]+={0,2}$~', $value) === 1 ? $value : null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = af_db();

// ---------------------------------------------------------------------------
// GET — approved reviews
// ---------------------------------------------------------------------------
if ($method === 'GET') {
    if ($pdo === null) {
        reviews_respond(503, ['ok' => false, 'reviews' => [], 'count' => 0, 'average' => null]);
    }
    header('Cache-Control: public, max-age=60');   // a new approval shows within a minute

    $stats = $pdo->query('SELECT COUNT(*) AS c, AVG(rating) AS a FROM guest_reviews WHERE is_approved = 1')->fetch();
    $rows  = $pdo->query(
        'SELECT id, guest_name, country, rating, trip_type, review_text, avatar_data, created_at
           FROM guest_reviews
          WHERE is_approved = 1
          ORDER BY created_at DESC, id DESC
          LIMIT 50'
    )->fetchAll();

    $reviews = array_map(static function (array $r): array {
        $avatar = (string) ($r['avatar_data'] ?? '');
        return [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['guest_name'],
            'country'    => (string) $r['country'],
            'rating'     => (int) $r['rating'],
            'trip_type'  => (string) $r['trip_type'],
            'review'     => (string) $r['review_text'],
            'avatar'     => preg_match('~^data:image/(jpeg|png|webp);base64,~', $avatar) === 1 ? $avatar : null,
            'created_at' => (string) $r['created_at'],
        ];
    }, $rows);

    $count = (int) ($stats['c'] ?? 0);
    reviews_respond(200, [
        'ok'      => true,
        'reviews' => $reviews,
        'count'   => $count,
        'average' => $count > 0 ? round((float) $stats['a'], 1) : null,
    ]);
}

// ---------------------------------------------------------------------------
// POST — a new review, pending moderation
// ---------------------------------------------------------------------------
if ($method !== 'POST') {
    header('Allow: GET, POST');
    reviews_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

header('Cache-Control: no-store');

if (!af_same_site()) {
    error_log('[AirportFirst] reviews-api refused a post from origin "' . substr(preg_replace('/[^\x20-\x7E]/', '.', af_request_host()) ?? '', 0, 120) . '"');
    reviews_respond(403, ['ok' => false, 'error' => 'Request rejected']);
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > REVIEW_MAX_BODY) {
    reviews_respond(413, ['ok' => false, 'error' => 'Request too large']);
}
if ($raw === '' || !mb_check_encoding($raw, 'UTF-8')) {
    reviews_respond(400, ['ok' => false, 'error' => 'Malformed request']);
}
$input = json_decode($raw, true, 4);
if (!is_array($input)) {
    reviews_respond(400, ['ok' => false, 'error' => 'Malformed request']);
}

/* Honeypot: answer as if it worked and keep nothing. */
if (trim((string) ($input['website'] ?? '')) !== '') {
    error_log('[AirportFirst] honeypot caught a review');
    reviews_respond(201, ['ok' => true, 'pending' => true]);
}

if ($pdo === null) {
    reviews_respond(503, ['ok' => false, 'error' => 'Reviews cannot be received right now — please try again later']);
}

$name     = review_field($input, 'name', 80);
$country  = review_field($input, 'country', 80);
$tripType = review_field($input, 'trip_type', 60);
$text     = review_field($input, 'review', 600, true);
$rating   = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
$avatar   = review_avatar($input);

if (mb_strlen($name) < 2 || !review_is_clean_line($name)) {
    reviews_respond(422, ['ok' => false, 'error' => 'Please add your name']);
}
if ($country !== '' && !review_is_clean_line($country)) {
    reviews_respond(422, ['ok' => false, 'error' => 'Please check the country']);
}
if ($rating === false) {
    reviews_respond(422, ['ok' => false, 'error' => 'Please choose a rating from 1 to 5']);
}
if (!in_array($tripType, REVIEW_TRIP_TYPES, true)) {
    $tripType = 'Other';
}
if (mb_strlen($text) < 12) {
    reviews_respond(422, ['ok' => false, 'error' => 'Please write a little more about your journey']);
}
if (strpos($text, '<') !== false || preg_match_all('~(https?://|www\.)~i', $text) > 1) {
    reviews_respond(422, ['ok' => false, 'error' => 'Please describe your journey without links or markup']);
}

/* At most REVIEW_PER_HOUR reviews per visitor per hour. */
$ipHash = af_ip_hash(AF_HASH_SALT);
$recent = $pdo->prepare('SELECT COUNT(*) FROM guest_reviews WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
$recent->execute([$ipHash]);
if ((int) $recent->fetchColumn() >= REVIEW_PER_HOUR) {
    header('Retry-After: 3600');
    reviews_respond(429, ['ok' => false, 'error' => 'Thank you — we already have your review. Please try again later.']);
}

$insert = $pdo->prepare(
    'INSERT INTO guest_reviews (guest_name, country, rating, trip_type, review_text, avatar_data, is_approved, ip_hash)
     VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
);
$insert->execute([$name, $country, $rating, $tripType, $text, $avatar, $ipHash]);

reviews_respond(201, ['ok' => true, 'pending' => true]);
