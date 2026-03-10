<?php

declare(strict_types=1);

// Backward-compat wrapper. Use fetch_forecast.php going forward.
fwrite(STDOUT, "Deprecated: use php src/cli/fetch_forecast.php [--force]\n");
require __DIR__ . '/fetch_forecast.php';
