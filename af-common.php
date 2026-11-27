<?php
/**
 * Airport First — shared helpers for send-mail.php, reviews-api.php and
 * admin-reviews.php: the visitor's real IP (Cloudflare-verified), salted IP
 * hashes, the same-site origin check and booking reference codes.
 *
 * Direct web access to this file is blocked by .htaccess (it prints nothing).
 */

declare(strict_types=1);

/* Cloudflare's published edge ranges, from https://www.cloudflare.com/ips-v4 and
   https://www.cloudflare.com/ips-v6 (fetched 2026-11-26). They change rarely;
   refresh this list if Cloudflare announces new ranges. A missing range is not
   a security hole: those requests are simply keyed on the edge address. */
const CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/* Only this site may post to the endpoints. The server's own hostname is
   accepted too, so a temporary cPanel URL or staging domain works. */
const AF_ALLOWED_HOSTS = ['airportfirst.vip', 'www.airportfirst.vip'];

/** Whether $ip (IPv4 or IPv6) falls inside $cidr. */
function ip_in_cidr(string $ip, string $cidr): bool
{
    $parts  = explode('/', $cidr, 2);
    $ipBin  = @inet_pton($ip);
    $netBin = @inet_pton($parts[0]);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin) || !isset($parts[1])) {
        return false;
    }
    $bits  = (int) $parts[1];
    $bytes = intdiv($bits, 8);
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

/** Whether the TCP connection itself comes from a Cloudflare edge server. */
function from_cloudflare(string $ip): bool
{
    foreach (CLOUDFLARE_RANGES as $range) {
        if (ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * The visitor's address. CF-Connecting-IP is trusted ONLY when the connection
 * really comes from Cloudflare; anyone calling the server directly with a
 * forged header is keyed on their own address. X-Forwarded-For is never used.
 */
function af_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '' && from_cloudflare($ip)) {
        $visitor = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (filter_var($visitor, FILTER_VALIDATE_IP) !== false) {
            $ip = $visitor;
        }
    }
    return $ip;
}

/** IPv6 visitors are grouped by their /64, which one subscriber usually holds. */
function af_ip_bucket(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = inet_pton($ip);
        if ($packed !== false) {
            return bin2hex(substr($packed, 0, 8)) . '::/64';
        }
    }
    return $ip;
}

/** A salted hash of the visitor's address: stored and compared, never the IP itself. */
function af_ip_hash(string $salt): string
{
    return hash('sha256', $salt . '|' . af_ip_bucket(af_client_ip()));
}

/** The host the browser says it is posting from: Origin, or Referer if absent. */
function af_request_host(): string
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

/** True when the request comes from this site's own pages. */
function af_same_site(): bool
{
    $origin  = af_request_host();
    $allowed = AF_ALLOWED_HOSTS;
    $self    = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
    if ($self !== '') {
        $allowed[] = $self;
    }
    return $origin !== '' && in_array($origin, $allowed, true);
}

/** Booking reference such as AF-7KQ3XM (no 0/O/1/I, so it reads well on the phone). */
function af_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'AF-' . $code;
}
