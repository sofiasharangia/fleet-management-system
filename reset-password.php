<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

function read_hidden_password(string $prompt): string {
    fwrite(STDOUT, $prompt);
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    if (!$isWindows && function_exists('shell_exec')) {
        shell_exec('stty -echo');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $value;
    }
    return trim((string) fgets(STDIN));
}

$username = trim((string)($argv[1] ?? ''));
if ($username === '') { fwrite(STDOUT, 'Username: '); $username = trim((string)fgets(STDIN)); }
if ($username === '') { fwrite(STDERR, "Username is required.\n"); exit(1); }

$pdo = avtoparki_db();
$stmt = $pdo->prepare('SELECT id_pk, username, is_admin FROM account WHERE username = :u COLLATE NOCASE LIMIT 1');
$stmt->execute([':u' => $username]);
$user = $stmt->fetch();
if (!$user) {
    fwrite(STDERR, "User not found.\n");
    exit(1);
}

$password = read_hidden_password('New password: ');
$confirm = read_hidden_password('Confirm password: ');
if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
$errors = [];
if (strlen($password) < 8) $errors[] = 'at least 8 characters';
if (!preg_match('/[A-Z]/', $password)) $errors[] = '1 uppercase Latin letter';
if (!preg_match('/[0-9]/', $password)) $errors[] = '1 number';
if (!preg_match('/[^a-zA-Z0-9]/', $password)) $errors[] = '1 special character';
if ($errors) {
    fwrite(STDERR, 'Password must contain: ' . implode(', ', $errors) . ".\n");
    exit(1);
}

// Ordinary users must replace the temporary password after login.
// Admin keeps the existing username and can log in immediately with the reset password.
$mustChange = (int)$user['is_admin'] === 1 ? 0 : 1;
$upd = $pdo->prepare('UPDATE account SET password_hash = :h, must_change = :m WHERE id_pk = :id');
$upd->execute([
    ':h' => password_hash($password, PASSWORD_DEFAULT),
    ':m' => $mustChange,
    ':id' => (int)$user['id_pk'],
]);

fwrite(STDOUT, "Password successfully reset for {$user['username']}.\n");
