<?php
// BOLT ELITE REDIRECT - end to end tests.
// Run: php tests/test_e2e.php

putenv('BE_DATA_DIR=' . sys_get_temp_dir() . '/be_test_' . bin2hex(random_bytes(4)));
putenv('ADMIN_TOKEN=testtoken');

require __DIR__ . '/../lib.php';
require __DIR__ . '/../include/ua.php';
require __DIR__ . '/../include/geo.php';
require __DIR__ . '/../include/tokens.php';
require __DIR__ . '/../include/templates.php';
require __DIR__ . '/../include/tpl_engine.php';
require __DIR__ . '/../include/ai.php';
require __DIR__ . '/../include/serve.php';
require __DIR__ . '/../include/api.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $label): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}
function section(string $s): void { echo "\n$s\n"; }

section('user agent parsing');
$uas = [
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'Windows', 'Chrome', 'desktop', false],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'iOS', 'Safari', 'mobile', false],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', 'Android', 'Chrome', 'mobile', false],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'macOS', 'Safari', 'desktop', false],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Linux', 'Firefox', 'desktop', false],
    ['Googlebot/2.1 (+http://www.google.com/bot.html)', 'unknown', 'unknown', 'desktop', true],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', 'Windows', 'Edge', 'desktop', false],
];
foreach ($uas as $i => [$ua, $os, $br, $dev, $bot]) {
    $r = be_parse_ua($ua);
    ok($r['os'] === $os && $r['browser'] === $br && $r['device'] === $dev && $r['is_bot'] === $bot, "ua#$i -> {$r['os']}/{$r['browser']}/{$r['device']}" . ($bot ? ' bot' : ''));
}

section('token lifecycle');
$t = be_token_create(['name' => 'Test redirect', 'slug' => 'test-rd', 'target' => 'https://example.com/a', 'mode' => 'redirect', 'alert_tg' => 1, 'param_block' => 'fbclid, gclid']);
ok(!empty($t['id']), 'created with id');
ok($t['slug'] === 'test-rd', 'slug honoured');
ok($t['alert_tg'] === true, 'alert flag is bool');
$g = be_token_get($t['id']);
ok($g['target'] === 'https://example.com/a', 'target stored');
$u = be_token_update($t['id'], ['name' => 'Renamed', 'status' => 'paused']);
ok($u['name'] === 'Renamed' && $u['status'] === 'paused', 'update applied');
$u2 = be_token_update($t['id'], ['status' => 'active']);
ok($u2['status'] === 'active', 'resume works');
$list = be_token_list();
ok(count($list) >= 1, 'list returns tokens');

section('resolution + param forwarding');
$host = '';
$r = be_resolve_token('test-rd', $host);
ok($r !== null && $r['id'] === $t['id'], 'resolve by slug');
$r2 = be_resolve_token($t['id'], $host);
ok($r2 !== null, 'resolve by id');
ok(be_resolve_token('does-not-exist', $host) === null, 'unknown slug -> null');
$bound = be_token_create(['name' => 'Bound', 'slug' => 'bound-1', 'target' => 'https://example.com/b', 'domain' => 'go.only.com']);
ok(be_resolve_token('bound-1', 'go.only.com') !== null, 'domain-bound resolves on its host');
ok(be_resolve_token('bound-1', 'other.com') === null, 'domain-bound blocked on other host');
$lim = be_token_create(['name' => 'Limited', 'slug' => 'lim-1', 'target' => 'https://example.com/c', 'max_hits' => 1]);
be_db()->prepare("UPDATE tokens SET hits=1 WHERE id=?")->execute([$lim['id']]);
ok(be_resolve_token('lim-1', '') === null, 'max_hits reached -> null');

$_GET = ['utm_source' => 'mail', 'email' => 'a@b.c', 'fbclid' => 'xyz', 'slug' => 'test-rd'];
$tk = be_token_get($t['id']);
ok(be_forward_params('https://example.com/x', $tk) === 'https://example.com/x?utm_source=mail&email=a%40b.c', 'merge forwards utm+email, drops fbclid+slug');

$tk2 = be_token_get($t['id']); $tk2['params_mode'] = 'strip';
ok(be_forward_params('https://example.com/x', $tk2) === 'https://example.com/x', 'strip forwards nothing');
$tk3 = be_token_get($t['id']); $tk3['params_mode'] = 'forward_only'; $tk3['param_allow'] = 'email';
ok(be_forward_params('https://example.com/x', $tk3) === 'https://example.com/x?email=a%40b.c', 'forward_only honours allow list');
ok(be_forward_params('https://example.com/x?z=1', $tk) === 'https://example.com/x?z=1&utm_source=mail&email=a%40b.c', 'merges into existing query string');
ok(be_forward_params('https://example.com/x#frag', $tk2) === 'https://example.com/x#frag', 'fragment preserved');

section('hit logging + geo');
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0 Safari/537.36';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-GB,en';
$_SERVER['HTTP_REFERER'] = 'https://mail.google.com/';
$_SERVER['HTTP_HOST'] = 'bolt.test';
$_SERVER['REQUEST_URI'] = '/test-rd?utm_source=mail';
$_SERVER['QUERY_STRING'] = 'utm_source=mail';
$hit = be_log_hit(be_token_get($t['id']));
ok($hit['device'] === 'desktop' && $hit['os'] === 'Windows', 'hit captured device/os');
ok($hit['country'] === 'Local', 'private IP resolved locally (no external call)');
$db = be_db();
$cnt = (int)(be_q1("SELECT COUNT(*) c FROM hits WHERE token_id=?", [$t['id']])['c'] ?? 0);
ok($cnt === 1, 'hit row written');
$after = be_token_get($t['id']);
ok((int)$after['hits'] === 1, 'hit counter incremented');
ok((int)$after['uniq'] === 1, 'unique counter incremented');

section('templates');
$presets = be_tpl_presets();
ok(count($presets) === 32, '32 presets (' . count($presets) . ')');
$fams = [];
foreach ($presets as $p) $fams[$p[1]] = 1;
ok(count($fams) === 10, '10 layout families (' . count($fams) . ': ' . implode(',', array_keys($fams)) . ')');
$bad = [];
foreach (array_keys($presets) as $id) {
    $html = be_tpl_render($id, ['target' => 'https://t.example/dest', 'delay' => 0, 'branding' => []], ['host' => 'h.test', 'target' => 'https://t.example/dest']);
    if (strlen($html) < 900) $bad[] = "$id:short";
    if (strpos($html, 'https://t.example/dest') === false) $bad[] = "$id:no-cta";
    if (stripos($html, 'warning') !== false || stripos($html, 'notice:') !== false) $bad[] = "$id:php-warning";
    if (strpos($html, 'name="viewport"') === false) $bad[] = "$id:no-viewport";
}
ok(!$bad, 'all 32 presets render clean, responsive, with CTA' . ($bad ? ' -> ' . implode(',', array_slice($bad, 0, 6)) : ''));

$chain = be_tpl_render('update', ['target' => 'https://t.example/d', 'delay' => 5, 'branding' => []], ['host' => 'h', 'target' => 'https://t.example/d']);
ok(strpos($chain, 'location.replace') !== false, 'delay > 0 emits auto-forward');

$custom = be_tpl_render('invoice', ['target' => 'https://t.example/d', 'delay' => 0, 'branding' => ['h1' => 'Custom & Headline', 'cta' => 'Go <now>']], ['host' => 'h', 'target' => 'https://t.example/d']);
ok(strpos($custom, 'Custom &amp; Headline') !== false, 'branding override applied');
ok(strpos($custom, 'Go &lt;now&gt;') !== false, 'branding output escaped');

section('ai builder (offline path)');
$r = be_ai_build(['brief' => 'Tell the user their parcel is out for delivery and let them track it']);
ok($r['ok'] === true && $r['source'] === 'offline', 'falls back offline without a key');
ok($r['template'] === 'shipping', 'keyword pick -> shipping (got ' . $r['template'] . ')');
$r2 = be_ai_build(['brief' => 'A data breach notice for customers']);
ok($r2['template'] === 'breach', 'keyword pick -> breach (got ' . $r2['template'] . ')');

section('stats');
$st = be_stats();
ok(isset($st['totals']['hits']) && $st['totals']['hits'] >= 1, 'totals present');
ok(count($st['by_day']) === 14, '14-day series');
ok(is_array($st['devices']) && count($st['devices']) >= 1, 'device breakdown');

section('cleanup');
foreach (be_token_list() as $x) be_token_delete($x['id']);
ok(count(be_token_list()) === 0, 'tokens deleted');
ok(count(be_db()->query("SELECT * FROM hits")->fetchAll(ASSOC)) === 0, 'hits cascade deleted');

echo "\n" . str_repeat('-', 46) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);