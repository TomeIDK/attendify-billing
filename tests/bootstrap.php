<?php
// 1) Composer autoloader
require __DIR__ . '/vendor/autoload.php';

// 2) Load environment variables
\Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
