<?php
// BOLT ELITE REDIRECT - AI redirect builder.
// Turns a plain brief into a ready campaign: preset + copy + accent + CTA.
// Works with any OpenAI-compatible endpoint; degrades to a smart offline
// heuristic when no key is configured or the call fails.

function be_ai_available(): bool { return be_llm_key() !== ''; }

function be_llm_key(): string {
    $k = (string)cfg_get('llm_key', '');
    return $k !== '' ? $k : LLM_API_KEY;
}
function be_llm_base(): string {
    $b = (string)cfg_get('llm_base', '');
    return $b !== '' ? $b : LLM_BASE_URL;
}
function be_llm_model(): string {
    $m = (string)cfg_get('llm_model', '');
    return $m !== '' ? $m : LLM_MODEL;
}

function be_ai_json(string $s): ?array {
    $s = trim($s);
    $s = preg_replace('/^```(?:json)?/i', '', $s);
    $s = preg_replace('/```$/', '', trim($s));
    $i = strpos($s, '{'); $j = strrpos($s, '}');
    if ($i === false || $j === false || $j <= $i) return null;
    $d = json_decode(substr($s, $i, $j - $i + 1), true);
    return is_array($d) ? $d : null;
}

// Keyword -> preset map used for both the offline fallback and as a hint.
function be_ai_pick_template(string $text): string {
    $t = strtolower($text);
    $map = [
        'invoice'      => ['invoice','billing','payment due','amount due','receipt'],
        'payment'      => ['payment sent','paid','payment confirm','transaction'],
        'refund'       => ['refund','money back','reimburse'],
        'shipping'     => ['parcel','shipment','tracking','courier','delivery on','out for delivery'],
        'delivery'     => ['delivery failed','missed you','reschedule','undelivered'],
        'security'     => ['sign-in','sign in','login attempt','new device','suspicious','unusual'],
        'breach'       => ['breach','data leak','compromised','exposed'],
        'reset'        => ['password reset','reset your password','forgot password'],
        'verify'       => ['verify','confirm your email','confirmation','validate'],
        '2fa'          => ['two-factor','2fa','two factor','authenticator'],
        'storage'      => ['storage full','out of space','quota','disk full'],
        'update'       => ['update','upgrade required','out of date','patch'],
        'appupdate'    => ['app update','new version','latest version'],
        'download'     => ['download','file ready','attachment','shared file'],
        'document'     => ['document','doc shared','shared with you','spreadsheet'],
        'photo'        => ['photo','album','images','pictures'],
        'video'        => ['video','watch','playback'],
        'voicemail'    => ['voicemail','voice message','missed call'],
        'meeting'      => ['meeting','calendar','invite','schedule'],
        'interview'    => ['interview','candidate','hiring','recruiter'],
        'offer'        => ['job offer','offer letter','position','role'],
        'contract'     => ['contract','sign','signature','agreement'],
        'payslip'      => ['payslip','salary','payroll','wages'],
        'tax'          => ['tax','irs','statement of earnings'],
        'survey'       => ['survey','feedback','questionnaire','rate us'],
        'gift'         => ['gift card','voucher','gift'],
        'reward'       => ['reward','points','loyalty','cashback'],
        'coupon'       => ['coupon','discount','promo','% off','sale'],
        'event'        => ['event','invitation','rsvp','ticket'],
        'webinar'      => ['webinar','live session','register now'],
        'subscription' => ['subscription','renew','plan','membership'],
        'backup'       => ['backup','restore','archive'],
    ];
    $best = ''; $bestPos = PHP_INT_MAX;
    foreach ($map as $id => $words) {
        foreach ($words as $w) {
            $p = strpos($t, $w);
            if ($p !== false && $p < $bestPos) { $bestPos = $p; $best = $id; }
        }
    }
    return $best !== '' ? $best : 'update';
}

function be_ai_build(array $req): array {
    $brief  = trim((string)($req['brief'] ?? ''));
    $target = trim((string)($req['target'] ?? ''));
    $brand  = trim((string)($req['brand'] ?? ''));
    $tone   = trim((string)($req['tone'] ?? 'professional'));
    $accent = trim((string)($req['accent'] ?? ''));
    $ids    = array_keys(be_tpl_presets());

    if ($brief === '') $brief = 'A short notice inviting the reader to continue.';

    $fallback = function () use ($brief, $ids) {
        $id = be_ai_pick_template($brief);
        $m  = be_tpl_meta($id);
        return ['ok'=>true,'source'=>'offline','template'=>$id,'kicker'=>$m['kicker'],
                'h1'=>$m['h1'],'sub'=>$m['sub'],'body'=>$m['body'],'cta'=>$m['cta'],
                'accent'=>$m['accent'],'brand'=>'','icon'=>$m['icon']];
    };

    if (!be_ai_available()) return $fallback();

    $sys = "You are a senior marketing copywriter for a redirect landing page builder. "
         . "Given a brief, respond with ONLY a JSON object, no prose, with keys: "
         . "template (choose exactly one id from this list: " . implode(', ', $ids) . "), "
         . "kicker (2-3 word uppercase label), h1 (max 60 chars), sub (max 70 chars), "
         . "body (max 140 chars), cta (2-4 word button label), accent (hex colour), brand (short brand name or empty). "
         . "Tone: " . $tone . ". Keep it credible, calm and non-spammy. Output JSON only.";

    $user = "Brief: " . $brief;
    if ($target !== '') $user .= "\nDestination: " . $target;
    if ($brand !== '')  $user .= "\nBrand: " . $brand;
    if ($accent !== '') $user .= "\nPreferred accent: " . $accent;

    $r = be_http_post_json(rtrim(be_llm_base(), '/') . '/chat/completions', [
        'model' => be_llm_model(),
        'temperature' => 0.7,
        'max_tokens' => 400,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
    ], ['Authorization: Bearer ' . be_llm_key()], 45000);

    $txt = $r['choices'][0]['message']['content'] ?? '';
    $j = is_string($txt) && $txt !== '' ? be_ai_json($txt) : null;
    if (!$j) return $fallback();

    $id = in_array(($j['template'] ?? ''), $ids, true) ? $j['template'] : be_ai_pick_template($brief);
    $m  = be_tpl_meta($id);
    $clip = fn($s, $n) => mb_substr(trim((string)$s), 0, $n);
    $acc = (string)($j['accent'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $acc)) $acc = $m['accent'];

    return [
        'ok' => true, 'source' => 'ai', 'template' => $id,
        'kicker' => $clip($j['kicker'] ?? $m['kicker'], 28) ?: $m['kicker'],
        'h1'     => $clip($j['h1'] ?? $m['h1'], 70) ?: $m['h1'],
        'sub'    => $clip($j['sub'] ?? $m['sub'], 90) ?: $m['sub'],
        'body'   => $clip($j['body'] ?? $m['body'], 180) ?: $m['body'],
        'cta'    => $clip($j['cta'] ?? $m['cta'], 28) ?: $m['cta'],
        'accent' => $acc,
        'brand'  => $clip($j['brand'] ?? '', 32),
        'icon'   => $m['icon'],
    ];
}
