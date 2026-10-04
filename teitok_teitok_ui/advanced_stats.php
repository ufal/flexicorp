<?php
/**
 * Compatibility entrypoint for advanced statistics module.
 *
 * Keeps action wiring flexible (`advanced_stats.php` vs `advanced_freqs.php`)
 * while delegating rendering behavior to advanced_freqs.php.
 */

require_once __DIR__ . '/advanced_freqs.php';

