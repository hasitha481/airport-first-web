<?php
/**
 * Airport First — database connection (PDO, MySQL / MariaDB)
 * -----------------------------------------------------------------------------
 * This file holds NO passwords and is safe to keep in git. The real values live
 * in ONE private file one level ABOVE public_html, where Apache cannot serve it
 * and no commit can carry it (the same arrangement as the SMTP password):
 *
 *     /home/<cpanel-user>/airportfirst-db.php
 *
 *     <?php return [
 *         'host'                => 'localhost',
 *         'name'                => 'cpaneluser_airportfirst',   // cPanel → MySQL Databases
 *         'user'                => 'cpaneluser_afweb',
 *         'pass'                => 'the database user password',
 *         'admin_password_hash' => '$2y$10$...',               // see DEPLOYMENT.md, step 4
 *         'hash_salt'           => 'any long random string',   // seasons the IP hashes
 *     ];
 *
 * Until that file exists, af_db() returns null: inquiries are still emailed,
 * reviews simply are not loaded, and the error log says why.
 *
 * Direct web access to this file is blocked by .htaccess (it prints nothing
 * anyway).
 */

declare(strict_types=1);

(static function (): void {
    $secrets = [];
    $file = dirname(__DIR__) . '/airportfirst-db.php';
    if (is_readable($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $secrets = $loaded;
        }
    }
    define('DB_HOST', (string) ($secrets['host'] ?? 'localhost'));
    define('DB_NAME', (string) ($secrets['name'] ?? ''));
    define('DB_USER', (string) ($secrets['user'] ?? ''));
    define('DB_PASS', (string) ($secrets['pass'] ?? ''));
    define('ADMIN_PASSWORD_HASH', (string) ($secrets['admin_password_hash'] ?? ''));
    define('AF_HASH_SALT', (string) ($secrets['hash_salt'] ?? 'airport-first/ip-hash'));
})();

/**
 * The shared connection, or null when the database is not configured or not
 * reachable. Callers must treat null as "carry on without the database".
 */
function af_db(): ?PDO
{
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($failed) {
        return null;
    }
    if (DB_NAME === '' || DB_USER === '') {
        $failed = true;
        error_log('[AirportFirst] database not configured — create ' . dirname(__DIR__) . '/airportfirst-db.php');
        return null;
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,       // a stuck database must not hold up a booking
            ]
        );
        // Timestamps in Sri Lanka time, so the desk reads them as they are.
        $pdo->exec("SET time_zone = '+05:30'");
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        $pdo = null;
        error_log('[AirportFirst] database unavailable: ' . $e->getMessage());
        return null;
    }
}
