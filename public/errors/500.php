<?php
/**
 * EduTrack — 500 Internal Server Error Page
 * Note: config.php may not be available if error is catastrophic.
 */
http_response_code(500);
$appName = defined('APP_NAME') ? APP_NAME : 'EduTrack';
$baseUrl = defined('BASE_URL') ? BASE_URL : '/edutrack';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>500 Server Error — <?= htmlspecialchars($appName) ?></title>
  <link rel="stylesheet" href="<?= $baseUrl ?>/public/assets/css/base.css">
  <style>
    body { display:flex; align-items:center; justify-content:center;
           min-height:100vh; background:var(--color-bg); }
    .error-box { text-align:center; max-width:480px; padding:var(--space-8); }
    .error-code { font-family:var(--font-heading); font-size:6rem;
                  color:var(--color-amber); line-height:1; margin-bottom:var(--space-4); }
    .error-title { font-size:var(--text-2xl); color:var(--color-primary);
                   margin-bottom:var(--space-3); }
    .error-msg { color:var(--color-text-secondary); margin-bottom:var(--space-8); }
  </style>
</head>
<body>
  <div class="error-box">
    <div class="error-code">500</div>
    <h1 class="error-title">Server Error</h1>
    <p class="error-msg">
      Something went wrong on the server. The error has been logged.
      Please try again or contact the system administrator
      if the problem persists.
    </p>
    <a href="<?= $baseUrl ?>/index.php" class="btn btn-primary">← Back to Home</a>
  </div>
</body>
</html>
