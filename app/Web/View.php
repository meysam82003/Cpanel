<?php

declare(strict_types=1);

namespace App\Web;

/** Minimal HTML layout and response helpers for the server-rendered pages. */
final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public static function page(string $title, string $body, string $nonce = '', string $script = ''): string
    {
        $scriptTag = $script !== '' ? '<script nonce="' . self::e($nonce) . '">' . $script . '</script>' : '';
        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="color-scheme" content="light dark"><title>' . self::e($title) . '</title><style>' . self::css() . '</style></head><body><main class="shell">' . $body . '</main>' . $scriptTag . '</body></html>';
    }

    /** @param array<string,string> $extraHeaders */
    public static function send(string $html, int $status = 200, string $nonce = '', bool $https = false, array $extraHeaders = []): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        $script = $nonce !== '' ? "'self' 'nonce-" . $nonce . "'" : "'self'";
        header("Content-Security-Policy: default-src 'self'; script-src " . $script . "; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        foreach ($extraHeaders as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $html;
        exit;
    }

    /** @param list<array{name:string,ok:bool,detail_fa:string,detail_en:string}> $steps */
    public static function steps(array $steps): string
    {
        $html = '<ol class="steps">';
        foreach ($steps as $step) {
            $html .= '<li class="' . ($step['ok'] ? 'ok' : 'bad') . '"><b>' . self::e($step['name']) . '</b><span>' . self::e($step['detail_fa']) . '</span><span class="ltr muted">' . self::e($step['detail_en']) . '</span></li>';
        }
        return $html . '</ol>';
    }

    /** @param list<array{fa:string,en:string}> $warnings */
    public static function warnings(array $warnings): string
    {
        $html = '';
        foreach ($warnings as $warning) {
            $html .= '<div class="notice warn">⚠️ ' . self::e($warning['fa']) . '<div class="ltr">' . self::e($warning['en']) . '</div></div>';
        }
        return $html;
    }

    public static function copyField(string $label, string $value): string
    {
        $id = 'c' . bin2hex(random_bytes(4));
        return '<div class="copy"><label for="' . $id . '">' . self::e($label) . '</label><div class="copy-row"><input id="' . $id . '" class="ltr" readonly value="' . self::e($value) . '"><button type="button" class="ghost" data-copy="' . $id . '">کپی · Copy</button></div></div>';
    }

    public static function copyScript(): string
    {
        return "document.addEventListener('click',function(e){var b=e.target.closest('[data-copy]');if(!b)return;var i=document.getElementById(b.getAttribute('data-copy'));if(!i)return;i.select();try{navigator.clipboard.writeText(i.value)}catch(x){document.execCommand('copy')}b.textContent='✓';setTimeout(function(){b.textContent='کپی · Copy'},1500)});";
    }

    private static function css(): string
    {
        return ':root{color-scheme:light dark;--bg:#f3f6f9;--card:#fff;--line:#dbe3ea;--text:#0f1d2a;--muted:#5b6d7c;--accent:#0c8576;--accent-text:#fff;--danger:#c0392b;--danger-bg:#fdecea;--warn-bg:#fff6e0;--warn-line:#f0c36d;--ok:#1e9e5a;--ok-bg:#e8f7ef;--field:#f8fafc}'
            . '@media(prefers-color-scheme:dark){:root{--bg:#07131f;--card:#102333;--line:#26465d;--text:#eef5f9;--muted:#9fb6c4;--accent:#19b89a;--accent-text:#03140f;--danger:#ff7b72;--danger-bg:#3a1d24;--warn-bg:#382c12;--warn-line:#8a6a1f;--ok:#55d68b;--ok-bg:#12352c;--field:#071823}}'
            . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.75 Vazirmatn,Tahoma,system-ui,-apple-system,"Segoe UI",sans-serif;min-height:100vh;padding:24px 16px}'
            . '.shell{max-width:760px;margin:0 auto}.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:clamp(18px,4vw,30px);margin-bottom:16px;box-shadow:0 10px 40px #0000000f}'
            . '.brand{display:flex;align-items:center;gap:12px;margin-bottom:6px}.logo{width:44px;height:44px;border-radius:14px;background:var(--accent);color:var(--accent-text);display:grid;place-items:center;font-weight:900;font-size:22px}'
            . 'h1{font-size:clamp(21px,4.5vw,30px);margin:0}h2{font-size:18px;margin:0 0 12px}.lead,.muted{color:var(--muted)}.lead{margin:6px 0 0}'
            . '.ltr{direction:ltr;text-align:left;unicode-bidi:plaintext}.notice{border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:12px 0}.notice.bad{background:var(--danger-bg);border-color:var(--danger)}.notice.warn{background:var(--warn-bg);border-color:var(--warn-line)}.notice.good{background:var(--ok-bg);border-color:var(--ok)}'
            . '.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:flex;flex-direction:column;gap:6px}.full{grid-column:1/-1}label{font-weight:700;font-size:14px}.hint{font-size:12.5px;color:var(--muted)}'
            . 'input,select{width:100%;border:1px solid var(--line);background:var(--field);color:var(--text);border-radius:12px;padding:12px 13px;font:inherit;direction:ltr;text-align:left}input:focus{outline:3px solid color-mix(in srgb,var(--accent) 30%,transparent);border-color:var(--accent)}'
            . '.row{display:flex;gap:8px;align-items:stretch}.row input{flex:1}button,.button{display:inline-flex;justify-content:center;align-items:center;gap:8px;border:0;border-radius:12px;background:var(--accent);color:var(--accent-text);font:inherit;font-weight:800;padding:12px 18px;cursor:pointer;text-decoration:none}button[disabled]{opacity:.6;cursor:wait}.ghost{background:transparent;color:var(--text);border:1px solid var(--line);font-weight:600}.wide{width:100%;margin-top:18px}.danger-btn{background:var(--danger);color:#fff}'
            . 'details{border:1px dashed var(--line);border-radius:14px;padding:10px 14px;margin-top:14px}summary{cursor:pointer;font-weight:700}'
            . '.checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-top:10px}.check{display:flex;gap:8px;align-items:flex-start;background:var(--field);padding:8px 10px;border-radius:10px;border:1px solid var(--line);font-size:13px}.dot{font-size:11px;margin-top:5px}.yes{color:var(--ok)}.no{color:var(--danger)}'
            . '.kv{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:14px}.kv dt{color:var(--muted)}.kv dd{margin:0;overflow-wrap:anywhere}'
            . '.steps{padding:0;list-style:none;margin:0}.steps li{display:flex;flex-direction:column;padding:9px 12px;border-inline-start:3px solid var(--ok);background:var(--field);border-radius:10px;margin-bottom:8px}.steps li.bad{border-color:var(--danger)}'
            . '.copy{margin-top:12px}.copy-row{display:flex;gap:8px;margin-top:5px}.copy-row input{flex:1;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}'
            . '.badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12.5px;font-weight:700;background:var(--field);border:1px solid var(--line)}.badge.ok{background:var(--ok-bg);color:var(--ok);border-color:var(--ok)}.badge.bad{background:var(--danger-bg);color:var(--danger);border-color:var(--danger)}'
            . '.candidates{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}.candidates button{padding:8px 12px;font-weight:600}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}'
            . '@media(max-width:620px){.grid{grid-template-columns:1fr}.full{grid-column:auto}.kv{grid-template-columns:1fr}.kv dt{margin-top:6px}}';
    }
}
