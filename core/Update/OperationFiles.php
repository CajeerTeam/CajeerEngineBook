<?php

declare(strict_types=1);
namespace CajeerEngine\Update;

/** Filesystem primitives shared by backup and update. Never traverse a symbolic link. */
final class OperationFiles
{
    public static function path(string $root, string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|[\\\\:\x00-\x1f\x7f]|//~', $relative)) throw new \RuntimeException('Unsafe relative path');
        $path = rtrim($root, '/');
        if (is_link($path)) throw new \RuntimeException('Symlink root');
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) throw new \RuntimeException('Symlink in path: ' . $relative);
        }
        return $path;
    }

    public static function dir(string $path, int $mode = 0700): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path))) throw new \RuntimeException('Cannot create directory');
    }

    public static function write(string $path, string $data, int $mode = 0600, ?int $uid = null, ?int $gid = null): void
    {
        self::dir(dirname($path));
        if (is_link($path)) throw new \RuntimeException('Refusing symlink');
        $tmp = dirname($path) . '/.tmp-' . bin2hex(random_bytes(12));
        $out = fopen($tmp, 'xb');
        if ($out === false) throw new \RuntimeException('Cannot create temporary file');
        try {
            self::permissions($tmp,$mode,$uid,$gid);
            if (fwrite($out, $data) !== strlen($data) || !fflush($out) || !fsync($out)) throw new \RuntimeException('Cannot write durable file');
            fclose($out); $out = null;
            if (!rename($tmp, $path)) throw new \RuntimeException('Cannot replace file');
            self::syncDir(dirname($path));
        } finally { if (is_resource($out)) fclose($out); if (is_file($tmp)) unlink($tmp); }
    }

    public static function copy(string $source, string $dest, int $mode = 0600, ?int $uid = null, ?int $gid = null): void
    {
        if (is_link($source) || !is_file($source) || is_link($dest)) throw new \RuntimeException('Invalid copy source/target');
        self::dir(dirname($dest), $mode === 0600 ? 0700 : 0755);
        $tmp = dirname($dest) . '/.tmp-' . bin2hex(random_bytes(12));
        $in = fopen($source, 'rb'); $out = fopen($tmp, 'xb');
        try {
            if (!is_resource($in) || !is_resource($out)) throw new \RuntimeException('Cannot open copy streams');
            self::permissions($tmp,$mode,$uid,$gid);
            if (stream_copy_to_stream($in, $out) !== filesize($source) || !fflush($out) || !fsync($out)) throw new \RuntimeException('Copy failed');
            fclose($out); $out = null;
            if (!rename($tmp, $dest)) throw new \RuntimeException('Cannot replace file');
            self::syncDir(dirname($dest));
        } finally { if (is_resource($in)) fclose($in); if (is_resource($out)) fclose($out); if (is_file($tmp)) unlink($tmp); }
    }

    public static function syncDir(string $path): void
    {
        $h=fopen($path,'r');
        if ($h===false) throw new \RuntimeException('Cannot open directory for sync');
        try { if (!fsync($h)) throw new \RuntimeException('Cannot sync directory'); } finally { fclose($h); }
    }
    public static function permissions(string $path, int $mode, ?int $uid=null, ?int $gid=null): void
    {
        if ($uid!==null && fileowner($path)!==$uid && !chown($path,$uid)) throw new \RuntimeException('Cannot preserve file owner');
        if ($gid!==null && filegroup($path)!==$gid && !chgrp($path,$gid)) throw new \RuntimeException('Cannot preserve file group');
        if (!chmod($path,$mode)) throw new \RuntimeException('Cannot set file permissions');
    }
    public static function json(string $path, array $data, int $mode = 0600): void { self::write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", $mode); }
    public static function read(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('Missing state file');
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid state file');
        return $data;
    }
    public static function hash(string $path): string
    {
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('Missing regular file');
        return hash_file('sha256', $path) ?: throw new \RuntimeException('Cannot hash file');
    }
    public static function remove(string $path): void
    {
        if (is_link($path)) throw new \RuntimeException('Cannot remove symlink');
        if (file_exists($path)) { if (!is_file($path)||!unlink($path)) throw new \RuntimeException('Cannot remove file'); self::syncDir(dirname($path)); }
    }
    /** @return list<string> */
    public static function files(string $root, string $relative, ?callable $include = null): array
    {
        $base = self::path($root, $relative);
        if (!file_exists($base)) return [];
        if (is_file($base)) return $include === null || $include($relative) ? [$relative] : [];
        if (!is_dir($base)) throw new \RuntimeException('Invalid snapshot path');
        $result = [];
        foreach (new \DirectoryIterator($base) as $entry) {
            if ($entry->isDot()) continue;
            $name = $relative . '/' . $entry->getFilename();
            if ($include !== null && !$include($name)) continue;
            if ($entry->isLink()) throw new \RuntimeException('Symlink in snapshot: ' . $name);
            foreach (self::files($root, $name, $include) as $file) $result[] = $file;
        }
        sort($result, SORT_STRING); return $result;
    }
}
