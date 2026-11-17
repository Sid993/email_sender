<?php

/**
 * Gives an existing account the admin role (or takes it away).
 * Register on the website first, then on the server:
 *
 *   php bin/make-admin.php you@example.com            make admin
 *   php bin/make-admin.php you@example.com --remove   back to employee
 */

declare(strict_types=1);

use App\Config;
use App\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__));

$email = strtolower(trim((string) ($argv[1] ?? '')));
$role = in_array('--remove', $argv, true) ? 'employee' : 'admin';
if ($email === '') {
    fwrite(STDERR, "Usage: php bin/make-admin.php <email> [--remove]\n");
    exit(1);
}
$st = Database::pdo()->prepare('UPDATE users SET role = ? WHERE email = ?');
$st->execute([$role, $email]);
if ($st->rowCount() === 0) {
    $check = Database::pdo()->prepare('SELECT role FROM users WHERE email = ?');
    $check->execute([$email]);
    $current = $check->fetchColumn();
    if ($current === false) {
        fwrite(STDERR, "No account with the email $email. Register on the website first.\n");
        exit(1);
    }
}
echo "$email is now " . ($role === 'admin' ? 'an admin' : 'an employee') . ".\n";
