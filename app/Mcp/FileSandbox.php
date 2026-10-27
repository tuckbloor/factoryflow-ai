<?php

namespace App\Mcp;

use FilesystemIterator;
use RuntimeException;

class FileSandbox
{
    public const MAX_BYTES = 1048576;

    public static function validFilename(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 100
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._ -]*\.(txt|md|json|csv|log)\z/iD', $name)
            && ! preg_match('/\A(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])\./i', $name);
    }

    private function root(): string
    {
        $directory = config('mcp-files.directory');
        clearstatcache();
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($directory)
            || strcasecmp(rtrim(str_replace('\\', '/', $directory), '/'), str_replace('\\', '/', $root)) !== 0) {
            throw new RuntimeException('MCP files directory is unavailable or not allowed.');
        }

        return $root;
    }

    private function allowed(string $root, string $path): bool
    {
        clearstatcache(true, $path);
        $resolved = realpath($path);

        return ! is_link($path) && is_file($path) && $resolved !== false
            && strcasecmp($resolved, $path) === 0 && dirname($resolved) === $root;
    }

    public function files(): array
    {
        $root = $this->root();
        $files = [];
        $scanned = 0;
        try {
            foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$scanned > 1000) {
                    throw new RuntimeException('Directory exceeds the 1000 entry limit.');
                }
                $name = $entry->getFilename();
                if (self::validFilename($name) && $this->allowed($root, $entry->getPathname())) {
                    $size = @filesize($entry->getPathname());
                    if ($size === false) {
                        throw new RuntimeException('File metadata is unavailable.');
                    }
                    $files[] = ['name' => $name, 'size_bytes' => $size];
                }
            }
        } catch (\UnexpectedValueException $e) {
            throw new RuntimeException('MCP files directory is unavailable or not allowed.');
        }
        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['name'], $b['name']));

        return $files;
    }

    public function read(string $name): string
    {
        $root = $this->root();
        $path = $root.DIRECTORY_SEPARATOR.$name;
        if (! $this->allowed($root, $path)) {
            throw new RuntimeException('File not found or not allowed.');
        }
        $before = @lstat($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('File not readable.');
        }
        try {
            $opened = fstat($handle);
            if (! $this->allowed($root, $path) || $before === false || $opened === false
                || $before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev']
                || ($opened['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('File not found or not allowed.');
            }
            $text = stream_get_contents($handle, self::MAX_BYTES + 1);
            if ($text === false) {
                throw new RuntimeException('File not readable.');
            }
            if (strlen($text) > self::MAX_BYTES) {
                throw new RuntimeException('File exceeds the 1 MiB limit.');
            }
            if (! mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                throw new RuntimeException('File must contain UTF-8 text without binary control characters.');
            }

            return $text;
        } finally {
            fclose($handle);
        }
    }
}
