<?php
declare(strict_types=1);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
session_start();
// Preserve authenticated sessions across page refreshes.

require_once __DIR__ . '/config.php';
avtoparki_session_migrate();
avtoparki_enforce_idle_timeout(true);
avtoparki_db(); // Initialize the schema on the first visit.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
<title>Fleet Management Journal</title>
<link rel="stylesheet" href="assets/app.css?v=<?= rawurlencode(AVTOPARKI_VERSION) ?>-<?= (int) @filemtime(__DIR__ . '/assets/app.css') ?>">
<script src="assets/xlsx.full.min.js"></script>
</head>
<body>
<?php include __DIR__ . '/view-app.php'; ?>
<script>
    window.AVTOPARKI = { api: 'api.php' };
</script>
<script src="assets/app.js?v=<?= rawurlencode(AVTOPARKI_VERSION) ?>-<?= (int) @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
