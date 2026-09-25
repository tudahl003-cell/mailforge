<?php
// api/compose.php — preview the AI/template composer without sending.
//   POST {subject_base, body_base, tone, recipients, url_target}
//   → up to 5 sample compositions (one per recipient) so you can see the
//     variation before committing.
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../include/ai.php';
require_once __DIR__ . '/../../include/url.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
$in = json_in();
$recips = parse_recipients(req_str($in, 'recipients'));
$recips = array_slice($recips, 0, 5);
if (!$recips) json_out(['ok' => false, 'error' => 'no recipients supplied'], 400);

$camp = [
    'id' => req_int($in, 'id', 0),
    'subject_base' => req_str($in, 'subject_base', 'Quick note'),
    'body_base' => req_str($in, 'body_base', 'Hi, I wanted to share something with you. {{link}}'),
    'tone' => req_str($in, 'tone', 'neutral'),
    'personalization' => req_int($in, 'personalization', 1),
];

// use the real target so the link looks real in previews
$target = req_str($in, 'url_target', 'https://example.com');
$out = [];
foreach ($recips as $rec) {
    $link = url_public(new_token(14));
    [$subj, $body, $html, $engine] = compose_for($camp, $rec, $link);
    $out[] = ['recipient' => $rec['email'], 'engine' => $engine,
              'subject' => $subj, 'body' => $body, 'link' => $link];
}
json_out(['ok' => true, 'engine' => llm_provider() !== 'off' ? 'llm-or-fallback' : 'template',
          'provider' => llm_provider(), 'samples' => $out]);
