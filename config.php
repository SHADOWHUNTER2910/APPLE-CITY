<?php
// Local development defaults; do not commit real production secrets.
$DB_CONFIG = [
    // Switch driver to 'sqlite' for a standalone desktop app
    'driver' => 'sqlite',
    // Allow Electron to override DB path via environment for writable userData dir
    'sqlite_path' => getenv('STOCKTRACKER_DB_PATH') ?: (__DIR__ . '/data/stocktracker.sqlite'),

    // MySQL settings kept for backward compatibility (unused when driver=sqlite)
    'host' => '127.0.0.1',
    'name' => 'stocktracker',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
];