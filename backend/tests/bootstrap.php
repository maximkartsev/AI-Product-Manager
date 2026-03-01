<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * Resolve DB host mismatch for local Windows runs where ".env" points to
 * Docker host "mariadb" but tests execute outside the Docker network.
 * Keep CI/container environments unchanged when hostname resolves normally.
 */
$dbHost = getenv('DB_HOST');
if (is_string($dbHost) && trim($dbHost) !== '') {
    $candidate = trim($dbHost);
    $resolved = gethostbyname($candidate);
    if ($resolved === $candidate && strcasecmp($candidate, 'mariadb') === 0) {
        putenv('DB_HOST=127.0.0.1');
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_SERVER['DB_HOST'] = '127.0.0.1';
    }
}

if (!defined('FILEINFO_NONE')) {
    define('FILEINFO_NONE', 0);
}

if (!defined('FILEINFO_MIME_TYPE')) {
    define('FILEINFO_MIME_TYPE', 16);
}

if (!class_exists('finfo')) {
    /**
     * Lightweight fallback for environments where ext-fileinfo is unavailable.
     * Used only in tests to keep Flysystem mime probing functional.
     */
    class finfo
    {
        public function __construct(int $flags = FILEINFO_NONE, ?string $magicFile = null)
        {
        }

        public function file(string $filename, int $flags = FILEINFO_NONE, mixed $context = null): string|false
        {
            $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

            return match ($ext) {
                'json' => 'application/json',
                'txt', 'log', 'md' => 'text/plain',
                'csv' => 'text/csv',
                'html', 'htm' => 'text/html',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                'mkv' => 'video/x-matroska',
                'avi' => 'video/x-msvideo',
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                'zip' => 'application/zip',
                'gz' => 'application/gzip',
                'tar' => 'application/x-tar',
                default => 'application/octet-stream',
            };
        }

        public function buffer(string $string, int $flags = FILEINFO_NONE, mixed $context = null): string|false
        {
            $trimmed = ltrim($string);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                return 'application/json';
            }

            if ($trimmed !== '' && preg_match('/^[\x09\x0A\x0D\x20-\x7E]+$/', substr($trimmed, 0, 256)) === 1) {
                return 'text/plain';
            }

            return 'application/octet-stream';
        }
    }
}
