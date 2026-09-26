<?php
// BOLT ELITE REDIRECT - template engine + responsive shell.
// Included by index.php after templates.php (presets).

function be_tpl_meta(string $id): array {
    $p = be_tpl_presets();
    if (!isset($p[$id])) $id = 'update';
    $d = $p[$id];
    return ['id'=>$id,'name'=>$d[0],'family'=>$d[1],'icon'=>$d[2],'accent'=>$d[3],
            'kicker'=>$d[4],'h1'=>$d[5],'sub'=>$d[6],'body'=>$d[7],'cta'=>$d[8]];
}

function be_tpl_render(string $id, array $tok, array $ctx = []): string {
    $m = be_tpl_meta($id);
    $b = is_array($tok['branding'] ?? null) ? $tok['branding'] : [];
    $pick = function (string $k, string $dflt) use ($b) { return isset($b[$k]) && $b[$k] !== '' ? (string)$b[$k] : $dflt; };
    $d = [
        'id'     => $m['id'],
        'family' => $m['family'],
        'icon'   => $pick('icon', $m['icon']),
        'accent' => $pick('accent', $m['accent']),
        'kicker' => $pick('kicker', $m['kicker']),
        'h1'     => $pick('h1', $m['h1']),
        'sub'    => $pick('sub', $m['sub']),
        'body'   => $pick('body', $m['body']),
        'cta'    => $pick('cta', $m['cta']),
        'brand'  => $pick('brand', (string)($ctx['brand'] ?? '')),
        'target' => (string)($ctx['target'] ?? ($tok['target'] ?? '#')),
        'delay'  => (int)($tok['delay'] ?? 0),
        'host'   => (string)($ctx['host'] ?? ''),
        'year'   => date('Y'),
    ];
    $inner = be_tpl_family($d['family'], $d);
    return be_tpl_shell($d, $inner);
}

function be_tpl_css(string $accent): string {
    return "
*{box-sizing:border-box;margin:0;padding:0}
:root{--ac:" . $accent . ";--ink:#111418;--mut:#5b6472;--line:#e3e6ec;--bg:#f6f7f9;--card:#fff}
@media(prefers-color-scheme:dark){:root{--ink:#eef1f5;--mut:#9aa4b2;--line:#262b33;--bg:#0e1116;--card:#151920}}
html,body{height:100%}
body{font:16px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
color:var(--ink);background:var(--bg);display:flex;align-items:center;justify-content:center;padding:20px;-webkit-font-smoothing:antialiased}
.wrap{width:100%;max-width:560px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:32px;box-shadow:0 6px 24px rgba(16,24,40,.06)}
.ico{width:56px;height:56px;border-radius:14px;display:grid;place-items:center;font-size:26px;background:color-mix(in srgb,var(--ac) 12%,transparent);color:var(--ac);margin-bottom:18px}
.kick{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ac);margin-bottom:10px}
h1{font-size:23px;line-height:1.25;letter-spacing:-.01em;margin-bottom:10px}
.sub{color:var(--mut);font-size:15px;margin-bottom:8px}
.body{color:var(--mut);font-size:15px;margin-bottom:22px}
.btn{display:block;width:100%;text-align:center;text-decoration:none;background:var(--ac);color:#fff;font-weight:600;
padding:14px 20px;border-radius:10px;border:0;font-size:16px;cursor:pointer}
.btn:hover{filter:brightness(1.08)}
.alt{display:block;text-align:center;margin-top:14px;color:var(--mut);font-size:13px;text-decoration:none}
.foot{margin-top:16px;text-align:center;color:var(--mut);font-size:12px;line-height:1.6}
.note{margin-top:14px;padding:12px 14px;border-radius:10px;background:color-mix(in srgb,var(--ac) 9%,transparent);
color:var(--mut);font-size:13px;display:flex;gap:10px;align-items:center}
.dot{width:8px;height:8px;border-radius:50%;background:var(--ac);flex:none}
.bar{height:6px;border-radius:99px;background:var(--line);overflow:hidden;margin-top:16px}
.bar>i{display:block;height:100%;background:var(--ac);width:0;transition:width 1s linear}
@media(max-width:480px){.card{padding:22px}h1{font-size:20px}.ico{width:48px;height:48px;font-size:22px}}
";
}

function be_tpl_family(string $fam, array $d): string {
    $ico  = '<div class="ico">' . be_esc($d['icon']) . '</div>';
    $kick = $d['kicker'] !== '' ? '<div class="kick">' . be_esc($d['kicker']) . '</div>' : '';
    $cta  = '<a class="btn" href="' . be_esc($d['target']) . '">' . be_esc($d['cta']) . '</a>';
    $h1   = '<h1>' . be_esc($d['h1']) . '</h1>';
    $sub  = $d['sub'] !== '' ? '<div class="sub">' . be_esc($d['sub']) . '</div>' : '';
    $body = $d['body'] !== '' ? '<div class="body">' . be_esc($d['body']) . '</div>' : '';

    switch ($fam) {
        case 'split':
            return '<div class="card">' . $kick . $h1 . $sub . $body . $cta
                 . '<div class="note"><span class="dot"></span><span>' . be_esc($d['brand']) . '</span></div></div>';
        case 'steps':
            return '<div class="card">' . $ico . $kick . $h1 . $sub . $body . $cta
                 . '<div class="bar"><i id="p"></i></div><div class="foot">Preparing your secure link</div></div>';
        case 'banner':
            return '<div class="card" style="border-top:5px solid var(--ac)">' . $kick . $h1 . $sub . $body . $cta . '</div>';
        case 'doc':
            return '<div class="card"><div style="display:flex;gap:14px;align-items:flex-start">'
                 . $ico . '<div style="flex:1">' . $kick . $h1 . $sub . '</div></div>'
                 . '<div style="margin-top:14px">' . $body . '</div>' . $cta . '</div>';
        case 'app':
            return '<div class="card" style="border-radius:22px">' . $ico . $h1 . $sub . $body . $cta
                 . '<div class="foot">Open in browser - no install needed</div></div>';
        case 'table':
            return '<div class="card">' . $kick . $h1 . $sub
                 . '<div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;margin:14px 0">'
                 . '<div style="display:flex;justify-content:space-between;padding:11px 14px;border-bottom:1px solid var(--line)"><span style="color:var(--mut);font-size:14px">Status</span><b style="font-size:14px">Ready</b></div>'
                 . '<div style="display:flex;justify-content:space-between;padding:11px 14px;border-bottom:1px solid var(--line)"><span style="color:var(--mut);font-size:14px">Reference</span><b style="font-size:14px">' . be_esc(strtoupper(substr($d['id'], 0, 4))) . '-4821</b></div>'
                 . '<div style="display:flex;justify-content:space-between;padding:11px 14px"><span style="color:var(--mut);font-size:14px">Action</span><b style="font-size:14px;color:var(--ac)">Required</b></div>'
                 . '</div>' . $body . $cta . '</div>';
        case 'alert':
            return '<div class="card" style="border-left:5px solid var(--ac)">' . $kick . $h1 . $sub
                 . '<div class="note" style="margin:14px 0"><span class="dot"></span><span>' . be_esc($d['body']) . '</span></div>'
                 . $cta . '</div>';
        case 'media':
            return '<div class="card"><div style="height:150px;border-radius:12px;display:grid;place-items:center;font-size:44px;background:color-mix(in srgb,var(--ac) 12%,transparent);margin-bottom:18px">'
                 . be_esc($d['icon']) . '</div>' . $kick . $h1 . $sub . $body . $cta . '</div>';
        case 'form':
            return '<div class="card">' . $kick . $h1 . $sub
                 . '<div style="margin:14px 0"><input disabled placeholder="you@example.com" style="width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:transparent;color:var(--ink);font-size:15px"></div>'
                 . $body . $cta . '</div>';
        case 'card':
        default:
            return '<div class="card">' . $ico . $kick . $h1 . $sub . $body . $cta . '</div>';
    }
}

function be_tpl_shell(array $d, string $inner): string {
    $t = be_esc($d['h1']) . ' - ' . be_esc($d['brand'] !== '' ? $d['brand'] : $d['host']);
    $auto = '';
    if ($d['delay'] > 0) {
        $ms = (int)$d['delay'] * 1000;
        $auto = '<script>(function(){var b=document.getElementById("p");var t=0,ms=' . $ms . ';'
              . 'var iv=setInterval(function(){t+=100;if(b)b.style.width=Math.min(100,t/ms*100)+"%";'
              . 'if(t>=ms){clearInterval(iv);location.replace(' . json_encode($d['target']) . ');}},100);})();</script>';
    }
    return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . $t . '</title><style>' . be_tpl_css($d['accent']) . '</style></head>'
        . '<body><div class="wrap">' . $inner
        . '<div class="foot">' . ($d['brand'] !== '' ? be_esc($d['brand']) . ' &middot; ' : '')
        . '&copy; ' . $d['year'] . '</div></div>' . $auto . '</body></html>';
}
