<?php

/**
 * Ancestral Origins - a webtrees custom module.
 * Shows a pie chart of where a person's ancestors came from.
 *
 * Copyright (C) 2026 Larry Bislew
 * Licensed under the GNU General Public License v3.0 or later.
 */

declare(strict_types=1);

namespace AncestralOrigins;

require_once __DIR__ . '/src/OriginCalculator.php';
require_once __DIR__ . '/src/AncestralOriginsModule.php';

return new AncestralOriginsModule();
