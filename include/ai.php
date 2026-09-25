<?php
// ============================================================
//  AI composer — generates a UNIQUE subject + body per recipient
//  so no two emails look templated.
//
//  Primary: any OpenAI-compatible chat API (config-driven:
//  provider = openai | openrouter | anthropic | custom).
//  Fallback: deterministic-but-varied template engine (works with
//  no API key, offline — every combination is distinct).
//  Tokens available in the base template: {{link}}, {{first_name}},
//  {{name}}, {{org}}, and any extra key from the recipient row.
// ============================================================
require_once __DIR__ . '/../lib.php';

// ------------------------------------------------------------
//  Recipient parsing: one email per line, optional "|first last"
//  or "|first|org" suffix for personalization.
//    john@example.com
//    jsmith@example.com|John Smith
//    boss@corp.io|Bob|Acme
// ------------------------------------------------------------
function parse_recipients(string $text): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '|')) {
            $parts = array_map('trim', explode('|', $line));
            $email = array_shift($parts);
            $first = $parts[0] ?? '';
            $org   = $parts[1] ?? '';
            $out[] = ['email' => $email, 'first' => $first, 'name' => $first, 'org' => $org];
        } else {
            $local = strtolower(explode('@', $line)[0] ?? '');
            // crude first-name guess for personalization when not given
            $first = $local !== '' ? ucfirst(preg_replace('/[._-].*$/','', $local)) : '';
            $out[] = ['email' => $line, 'first' => $first, 'name' => $first, 'org' => ''];
        }
    }
    return $out;
}

// ------------------------------------------------------------
//  LLM providers
// ------------------------------------------------------------
function llm_provider(): string {
    $p = (string)cfg_get('llm_provider', 'openai');
    $p = in_array($p, ['openai','openrouter','anthropic','custom','off'], true) ? $p : 'openai';
    return $p;
}
function llm_endpoint(): ?string {
    $base = rtrim((string)cfg_get('llm_base_url', LLM_BASE_URL), '/');
    switch (llm_provider()) {
        case 'openrouter':
            return ($base !== '' ? $base : 'https://openrouter.ai/api/v1') . '/chat/completions';
        case 'anthropic':
            return ($base !== '' ? $base : 'https://api.anthropic.com') . '/v1/messages';
        case 'custom':
        case 'openai':
        default:
            if ($base === '') return null;
            return $base . '/chat/completions';
    }
}
function llm_key(): string {
    $k = (string)cfg_get('llm_api_key', LLM_API_KEY);
    return $k;
}
function llm_model(): string {
    return (string)cfg_get('llm_model', LLM_MODEL);
}

/**
 * Raw chat completion. Returns string content or null on any failure.
 */
function llm_chat(array $messages, float $temp = 0.9, int $max_tokens = 400): ?string {
    $url = llm_endpoint();
    $key = llm_key();
    if ($url === null || $key === '') return null;   // no key -> template engine

    $prov = llm_provider();
    $payload = ['model' => llm_model(), 'messages' => $messages,
                'temperature' => $temp, 'max_tokens' => $max_tokens];

    if ($prov === 'anthropic') {
        $sys = ''; $user = '';
        foreach ($messages as $m) {
            if ($m['role'] === 'system') $sys .= $m['content'] . "\n";
            else $user .= $m['content'] . "\n";
        }
        $payload = ['model' => llm_model(), 'system' => $sys,
                    'messages' => [['role' => 'user', 'content' => $user]],
                    'temperature' => $temp, 'max_tokens' => $max_tokens];
        $headers = "Content-Type: application/json\r\nx-api-key: " . $key
                 . "\r\nanthropic-version: 2023-06-01\r\n";
    } else {
        $headers = "Content-Type: application/json\r\nAuthorization: Bearer " . $key;
        if ($prov === 'openrouter') {
            $headers .= "\r\nHTTP-Referer: " . (APP_BASE ?: 'https://localhost')
                      . "\r\nX-Title: mailforge";
        }
    }

    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 40, 'ignore_errors' => true,
        'header' => $headers,
        'content' => json_encode($payload),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $j = json_decode((string)$body, true);
    if (!is_array($j)) return null;

    if ($prov === 'anthropic') {
        return isset($j['content'][0]['text']) ? (string)$j['content'][0]['text'] : null;
    }
    return isset($j['choices'][0]['message']['content'])
        ? (string)$j['choices'][0]['message']['content'] : null;
}

// ------------------------------------------------------------
//  Composition
// ------------------------------------------------------------
/**
 * Compose one unique email for one recipient.
 * $camp: subject_base, body_base, tone, personalization
 * $rec : [email, first, name, org, ...]
 * $link: the rotating URL to inject
 * Returns [subject, body, body_html?, engine] where engine is
 * 'llm' | 'template'.
 */
function compose_for(array $camp, array $rec, string $link): array {
    $recEmail = $rec['email'];
    $recName  = (string)($rec['name'] ?? '');
    $recFirst = (string)($rec['first'] ?? '');
    $recOrg   = (string)($rec['org'] ?? '');
    $tone     = (string)($camp['tone'] ?? 'neutral');

    // ---- LLM path ----
    if (llm_provider() !== 'off') {
        $sys = "You write cold business emails that read as 100% human and "
             . "completely unique. Rules: match the requested tone; vary sentence "
             . "length, word choice and structure on every single call — never reuse "
             . "a phrasing; keep it short (2-6 sentences); include exactly one URL "
             . "where the placeholder {{link}} is; use the recipient's first name "
             . "naturally when given; no emojis, no ALL CAPS, no fake urgency, no "
             . "exclamation marks unless the tone is upbeat. Output ONLY this JSON: "
             . '{"subject":"...","body":"..."}';
        $user = "Tone: $tone\n"
              . "Recipient: " . ($recName !== '' ? $recName . ' ' : '')
              . "($recEmail)" . ($recOrg !== '' ? " at $recOrg" : '') . "\n"
              . "The email must contain this link exactly: $link\n"
              . "Draft subject (reword it, don't copy it): " . (string)($camp['subject_base'] ?? '') . "\n"
              . "Draft body (rewrite it fully, preserve the link):\n"
              . (string)($camp['body_base'] ?? '');
        $raw = llm_chat([['role'=>'system','content'=>$sys],['role'=>'user','content'=>$user]], 0.95);
        if ($raw !== null) {
            $j = json_decode(preg_replace('/^```json\s*|\s*```$/','',trim($raw)), true);
            if (is_array($j) && !empty($j['subject']) && !empty($j['body'])) {
                $subj = inject_tokens((string)$j['subject'], $rec, $link);
                $body = inject_tokens((string)$j['body'], $rec, $link);
                return [$subj, $body, null, 'llm'];
            }
        }
        // LLM failed -> fall through to template engine (never blocks sending)
    }

    // ---- Template fallback: varied, still unique ----
    return compose_template($camp, $rec, $link);
}

function inject_tokens(string $text, array $rec, string $link): string {
    $map = [
        '{{link}}'       => $link,
        '{{first_name}}' => (string)($rec['first'] ?? ''),
        '{{name}}'       => (string)($rec['name'] ?? ''),
        '{{org}}'        => (string)($rec['org'] ?? ''),
        '{{email}}'      => (string)($rec['email'] ?? ''),
    ];
    foreach ($map as $k => $v) $text = str_replace($k, $v, $text);
    // any leftover {{token}} with a value from the recipient row
    $text = preg_replace_callback('/\{\{([a-zA-Z_]+)\}\}/', function ($m) use ($rec) {
        return isset($rec[$m[1]]) ? (string)$rec[$m[1]] : $m[0];
    }, $text);
    return trim($text);
}

// ------------------------------------------------------------
//  Offline variation engine
// ------------------------------------------------------------
function compose_template(array $camp, array $rec, string $link): array {
    mt_srand(crc32((string)$rec['email'] . '|' . (string)($camp['id'] ?? '0')));
    $tone = (string)($camp['tone'] ?? 'neutral');
    $first = (string)($rec['first'] ?? '');
    $name  = (string)($rec['name'] ?? '');
    $linkTok = '{{link}}';

    $openers = [
        'neutral' => [
            "Hi $first,","Hello $first,","Hi there,","Good day,","Hi $first,",
            "Hello,","Hi $name,","Hi,"],
        'friendly' => [
            "Hey $first!","Hi $first —","Hey there,","Hi $first,","Hey $name —"],
        'formal' => [
            "Dear $name,","Dear $first,","Good morning,","To whom it may concern,","Dear sir or madam,"],
        'upbeat' => [
            "Great news, $first!","Hi $first — quick one,","Hey $first,","Good to reach you, $first!"],
    ];
    if (!isset($openers[$tone])) $openers = $openers['neutral'];
    $opener = $openers[$tone][mt_rand(0, count($openers[$tone]) - 1)];

    // Base text: strip the {{link}} placeholder out of the campaign body,
    // keep everything else; we re-insert the link at a natural spot.
    $baseBody = str_replace('{{link}}', '', (string)($camp['body_base'] ?? ''));
    $baseBody = preg_replace('/\s+/', ' ', $baseBody);
    $baseBody = inject_tokens($baseBody, $rec, $link);
    $baseBody = trim($baseBody);

    $linkLine = [
        "You can see it here: $linkTok.",
        "Here's the link: $linkTok.",
        "Full details at $linkTok.",
        "I put everything together here — $linkTok.",
        "The details are at $linkTok.",
    ];
    $ll = $linkLine[mt_rand(0, count($linkLine) - 1)];

    $closures = [
        'neutral' => ["Let me know if you have questions.","Happy to answer anything.","Cheers,"],
        'friendly' => ["No pressure at all!","Let me know what you think!","Talk soon,"],
        'formal' => ["I look forward to hearing from you.","Please do not hesitate to contact me.","Regards,"],
        'upbeat' => ["Can't wait to hear back!","Let's make it happen!","Cheers!"],
    ];
    $close = ($closures[$tone] ?? $closures['neutral'])[mt_rand(0, 2)];

    // Assemble with light reordering so identical bodies don't collide
    $leadIn = [
        "I'm reaching out because", "I got in touch about", "I wanted to share",
        "Quick note about", "Just reaching out regarding",
    ][mt_rand(0, 4)];

    $subjectBase = inject_tokens((string)($camp['subject_base'] ?? 'Quick note'), $rec, $link);
    $subject = subject_variant($subjectBase, $tone, $first);

    $body = $opener . "\n\n"
          . $leadIn . ": " . $baseBody . "\n\n"
          . $ll . "\n\n"
          . $close . "\n"
          . "[your name]";
    $body = inject_tokens($body, $rec, $link);
    // final safety: ensure the actual link is present exactly once
    if (!str_contains($body, $link) && $link !== '') {
        $body .= "\n\n$link";
    }
    return [$subject, $body, null, 'template'];
}

function subject_variant(string $base, string $tone, string $first): string {
    $base = trim($base);
    $prefix = [
        'neutral' => ["", "", "Quick note", "For you"],
        'friendly' => ["Hi", "Hey", "Quick question"],
        'formal' => ["Regarding", "Inquiry", "Notice"],
        'upbeat' => ["Good news", "Update", "Exciting"],
    ];
    $p = $prefix[$tone] ?? $prefix['neutral'];
    $x = $p[mt_rand(0, count($p) - 1)];
    $s = $x !== '' ? "$x: $base" : $base;
    return function_exists('mb_substr') ? mb_substr($s, 0, 90) : substr($s, 0, 90);
}
