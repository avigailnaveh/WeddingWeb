<?php
/**
 * Bootstrap file to load environment variables from .env
 */

// Load Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load .env file from project root (two levels up from config/)
$dotenvPath = __DIR__ . '/../../';

if (file_exists($dotenvPath . '.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
    $dotenv->load();
} else {
    // Fallback: try loading from myyiiapp directory
    $dotenvPath = __DIR__ . '/../';
    if (file_exists($dotenvPath . '.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable($dotenvPath);
        $dotenv->load();
    }
}