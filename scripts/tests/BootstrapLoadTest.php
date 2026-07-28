<?php

declare(strict_types=1);

$container = require dirname(__DIR__, 2) . '/bootstrap.php';

if (!is_array($container) || !isset($container['controllers'])) {
    throw new RuntimeException('Bootstrapcontainer kon niet worden geladen.');
}

echo "BootstrapLoadTest OK\n";
