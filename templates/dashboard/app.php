<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GeoFort Onderwijs Dashboard</title>
  <?= $vite->renderTags('resources/js/admin.ts') ?>
</head>
<body class="admin-page">
  <script id="admin-bootstrap-data" type="application/json"><?= $bootstrapJson ?></script>
  <div id="admin-app"></div>
</body>
</html>
