<?php
// ---------------------------------------------------------------------------
// Secret-protected one-time migration runner (Option B workaround).
//
// Usage:  https://yourdomain.com/admin/run_migrations.php?key=<MIGRATION_KEY>
//
// Set the MIGRATION_KEY environment variable in DigitalOcean App Platform
// before visiting this URL.  The fallback value 'nadia' is only used when
// the environment variable is not set at all.
//
// After the migration succeeds, delete this file or change the key so the
// endpoint cannot be re-triggered by a third party.
// ---------------------------------------------------------------------------

// Prevent this script from being included by other files.
if (!isset($_SERVER['REQUEST_METHOD'])) {
    exit(1);
}

// Read expected key from environment; fall back to 'nadia' only if unset.
$expectedKey = getenv('MIGRATION_KEY');
if ($expectedKey === false || $expectedKey === '') {
    $expectedKey = 'nadia';
}

// Validate the supplied key using a timing-safe comparison.
$suppliedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
if (!hash_equals($expectedKey, $suppliedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: invalid or missing key.\n";
    exit;
}

// Load the shared PDO connection (defined in $pdo).
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

// Prefer the SQL file that ships with the repo.
$sqlFile = __DIR__ . '/../migrations/001_telegram_sessions.sql';

if (file_exists($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        http_response_code(500);
        echo "Error: could not read migration file.\n";
        exit;
    }
} else {
    // Inline fallback — keeps the endpoint self-contained even if the
    // migrations/ directory is missing.
    $sql = "
CREATE TABLE IF NOT EXISTS `telegram_sessions` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `telegram_user_id` BIGINT        NOT NULL,
    `step`             VARCHAR(50)   NOT NULL DEFAULT '',
    `data_json`        TEXT          NULL,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_telegram_user` (`telegram_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
}

try {
    $pdo->exec($sql);
    http_response_code(200);
    echo "OK: migration applied successfully.\n";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage() . "\n";
}
