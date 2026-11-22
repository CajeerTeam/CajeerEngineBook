<?php
declare(strict_types=1);
namespace CajeerBook;

/** Bounded local Unix-socket RPC. Never runs host commands or handles Docker credentials. */
final class UpdateAgentClient
{
    private readonly ?\Closure $transport;
    public function __construct(private readonly ?string $socket = null, ?\Closure $transport = null)
    {
        $this->transport = $transport;
    }
    public function configured():bool
    {
        $path = $this->socket ?? (getenv('BOOK_UPDATE_AGENT_SOCKET') ?: '');
        return is_string($path) && $path !== '';
    }
    public function call(string $action, ?array $candidate = null):array
    {
        if (!in_array($action, ['status','recovery-status','history','fetch','preflight','apply'], true)) {
            throw new \InvalidArgumentException('Unsupported host update action');
        }
        $request = ['action' => $action];
        if (!in_array($action, ['status', 'recovery-status', 'history'], true)) {
            if (!is_array($candidate) || array_keys($candidate) !== ['version','commit','manifest_sha256'] ||
                !preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $candidate['version'] ?? '') ||
                !preg_match('/\A[a-f0-9]{40}\z/D', $candidate['commit'] ?? '') ||
                !preg_match('/\A[a-f0-9]{64}\z/D', $candidate['manifest_sha256'] ?? '')) {
                throw new \DomainException('No valid pinned release candidate');
            }
            $request['candidate'] = $candidate;
        }
        $json = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($this->transport) {
            $reply = ($this->transport)($json);
        } else {
            $path = $this->socket ?? (getenv('BOOK_UPDATE_AGENT_SOCKET') ?: '');
            if (!is_string($path) || !str_starts_with($path, '/') || strlen($path) > 200 ||
                str_contains($path, "\0") || preg_match('~(?:^|/)\.\.?/|//~', $path) || !is_file($path) && !file_exists($path)) {
                throw new \RuntimeException('Host update agent is not configured');
            }
            if (!function_exists('stream_socket_client')) throw new \RuntimeException('Unix-socket transport unavailable');
            $stream = @stream_socket_client('unix://' . $path, $errno, $errstr, 2, STREAM_CLIENT_CONNECT);
            if (!is_resource($stream)) throw new \RuntimeException('Host update agent is unavailable');
            try {
                stream_set_timeout($stream, 3);
                $payload = $json . "\n";
                for ($sent = 0; $sent < strlen($payload); ) {
                    $n = @fwrite($stream, substr($payload, $sent));
                    if (!is_int($n) || $n <= 0) throw new \RuntimeException('Host agent request failed');
                    $sent += $n;
                }
                $reply = @fgets($stream, 16385);
                $meta = stream_get_meta_data($stream);
                if ($meta['timed_out'] ?? false) throw new \RuntimeException('Host update agent timed out');
            } finally { fclose($stream); }
        }
        if (!is_string($reply) || strlen($reply) > 16384) throw new \RuntimeException('Invalid host update agent response');
        try { $decoded = json_decode(trim($reply), true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('Invalid host update agent response'); }
        if (!is_array($decoded) || !is_bool($decoded['ok'] ?? null)) throw new \RuntimeException('Invalid host update agent response');
        if (!$decoded['ok']) throw new \DomainException('Host updater rejected the operation; consult host operator logs.');
        if (!is_array($decoded['data'] ?? null) || !is_string($decoded['data']['state'] ?? null))
            throw new \RuntimeException('Invalid host update status');
        return $decoded['data'];
    }
}
