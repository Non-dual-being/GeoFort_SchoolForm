<?php
declare(strict_types=1);
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inloggen | GeoFort Onderwijs</title>
  <?= $vite->renderTags('resources/js/admin.ts') ?>
</head>
<body class="admin-page admin-page--auth">
  <main class="admin-shell admin-shell--narrow">
    <section class="admin-auth-card" aria-labelledby="login-title">
      <img class="admin-logo" src="/assets/images/geofort_logo.png" alt="GeoFort">
      <p class="admin-eyebrow">Onderwijsformulier 2.0</p>
      <h1 id="login-title">Beheerlogin</h1>
      <?php foreach ($messages as $message): ?>
        <?php
        $type = in_array($message['type'] ?? '', ['error', 'success', 'info'], true)
            ? $message['type']
            : 'info';
        $role = $type === 'error' ? 'alert' : 'status';
        ?>
        <div
          class="admin-flash admin-flash--<?= $escape($type) ?>"
          role="<?= $role ?>"
          data-flash-type="<?= $escape($type) ?>"
          <?= $type === 'success' ? 'data-auto-dismiss="5000"' : '' ?>
        ><?= $escape($message['message'] ?? '') ?></div>
      <?php endforeach; ?>
      <form class="admin-form" method="post" action="/auth/login-submit.php">
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <label for="email">E-mailadres</label>
        <input id="email" name="email" type="email" autocomplete="email" required>
        <label for="password">Wachtwoord</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button class="admin-button admin-button--primary" type="submit">Inloggen</button>
      </form>
    </section>
  </main>
</body>
</html>
