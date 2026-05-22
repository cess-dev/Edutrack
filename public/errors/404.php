<?php
/**
 * EduTrack — 404 Not Found Error Page
 */
defined('EDUTRACK_LOADED') or define('EDUTRACK_LOADED', true);
require_once __DIR__ . '/../../config/config.php';
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 Not Found — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/base.css">
  <style>
    body { display:flex; align-items:center; justify-content:center;
           min-height:100vh; background:var(--color-bg); }
    .error-box { text-align:center; max-width:420px; padding:var(--space-8); }
    .error-code { font-family:var(--font-heading); font-size:6rem;
                  color:var(--color-primary); line-height:1; margin-bottom:var(--space-4); }
    .error-title { font-size:var(--text-2xl); color:var(--color-primary);
                   margin-bottom:var(--space-3); }
    .error-msg { color:var(--color-text-secondary); margin-bottom:var(--space-8); }
  </style>
</head>
<body>
  <div class="error-box">
    <div class="error-code">404</div>
    <h1 class="error-title">Page Not Found</h1>
    <p class="error-msg">
      The page you are looking for doesn't exist or has been moved.
      Please check the URL or return to the home page.
    </p>
    <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">← Back to Home</a>
  </div>
</body>
</html>
