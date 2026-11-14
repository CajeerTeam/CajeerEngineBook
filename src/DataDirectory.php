<?php
declare(strict_types=1);
namespace CajeerBook;

/** Resolve existing ancestors before creating anything, including non-existent descendants. */
final class DataDirectory
{
    public static function resolve(string $path, string $public): string
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) || str_contains($path, '\\')) throw new \RuntimeException('Invalid BOOK_DATA_DIR');
        if (!str_starts_with($path, '/')) $path = getcwd().'/'.$path;
        $web = realpath($public);
        if ($web === false) throw new \RuntimeException('Public directory unavailable');
        $parts = explode('/', $path); $resolved = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            // Reject traversal rather than trying to interpret it around missing directories/symlinks.
            if ($part === '..') throw new \RuntimeException('BOOK_DATA_DIR must not contain parent segments');
            $candidate = $resolved.'/'.$part;
            if ($candidate===$web || str_starts_with($candidate,$web.'/')) throw new \RuntimeException('BOOK_DATA_DIR must be outside public');
            if (file_exists($candidate) || is_link($candidate)) {
                $real = realpath($candidate);
                if ($real === false || !is_dir($real)) throw new \RuntimeException('Invalid data directory ancestor');
                $resolved = rtrim($real, '/');
                if ($resolved===$web || str_starts_with($resolved,$web.'/')) throw new \RuntimeException('BOOK_DATA_DIR must be outside public');
            } else $resolved = $candidate;
        }
        if ($resolved === '' || $resolved === $web || str_starts_with($resolved, $web.'/')) {
            throw new \RuntimeException('BOOK_DATA_DIR must be outside public');
        }
        return $resolved;
    }
}
