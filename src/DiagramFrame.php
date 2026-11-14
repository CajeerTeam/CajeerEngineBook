<?php
declare(strict_types=1);
namespace CajeerBook;

/** Dedicated opaque-origin frame. Never serve author content in this response. */
final class DiagramFrame
{
    public static function serve(string $method): void
    {
        if (!in_array($method, ['GET','HEAD'], true)) {
            http_response_code(405); header('Allow: GET, HEAD'); return;
        }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; img-src data: blob:; font-src 'none'; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox allow-scripts");
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if ($method === 'HEAD') return;
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mermaid diagram</title><style>html,body{margin:0;padding:0;min-height:100%;font:14px system-ui,sans-serif}body{padding:12px;box-sizing:border-box}#diagram{width:max-content;min-width:100%;display:flex;justify-content:center}svg{display:block}#message{white-space:pre-wrap;overflow-wrap:anywhere}</style><script defer src="/assets/mermaid/mermaid.min.js"></script><script defer src="/assets/diagram-frame.js"></script></head><body><div id="diagram" aria-label="Diagram"></div><p id="message" role="status"></p></body></html>';
    }
}
