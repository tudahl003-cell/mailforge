<?php
// BOLT ELITE REDIRECT - token model: create, resolve, forward, track hits.
//
// A token is a redirect unit:
//   slug/token  ->  mode: redirect (301/302/303/meta-refresh) | landing (template)
//                  | chain (redirect with delay + param merge)
// Resolution is Host-aware: a token may be bound to a domain, or '*' for any.

function be_token_create(array $f): array {
    $db = be_db();
    $id = be_new_token();

    $name = trim((string)($f['name'] ?? ''));
    if ($name === '') $name = 'token-' . substr($id, 0, 6);
    $slug = trim((string)($f['slug'] ?? ''));
    if ($slug !== '') {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
        if ($slug === '') $slug = substr($id, 0, 8);
    } else {
        $slug = substr($id, 0, 10);
    }

    $mode = (string)($f['mode'] ?? 'redirect');
    if (!in_array($mode, ['redirect','landing','chain'], true)) $mode = 'redirect';
    $status = (string)($f['status'] ?? 'active');
    if (!in_array($status, ['active','paused'], true)) $status = 'active';
    $redirect_code = (int)($f['redirect_code'] ?? 302);
    if (!in_array($redirect_code, [301,302,303,307,308], true)) $redirect_code = 302;

    $target = trim((string)($f['target'] ?? ''));
    if ($target === '') be_err('target required');
    if (!preg_match('#^https?://#i', $target)) $target = 'https://' . $target;

    $domain = strtolower(trim((string)($f['domain'] ?? '*')));
    if ($domain === '') $domain = '*';

    $template = (string)($f['template'] ?? 'update');
    $branding = be_json($f['branding'] ?? []);
    if (!is_array($branding)) $branding = [];

    $params_mode = (string)($f['params_mode'] ?? 'merge');
    if (!in_array($params_mode, ['merge','strip','forward_only'], true)) $params_mode = 'merge';
    $param_allow = (string)($f['param_allow'] ?? '');
    $param_block = (string)($f['param_block'] ?? '');

    $alert_tg = !empty($f['alert_tg']) ? 1 : 0;
    $delay = max(0, (int)($f['delay'] ?? 0));
    $max_hits = (int)($f['max_hits'] ?? 0);

    $notes = (string)($f['notes'] ?? '');
    $created = time();

    $db->prepare("INSERT INTO tokens (id,name,slug,domain,mode,status,redirect_code,target,template,branding,params_mode,param_allow,param_block,alert_tg,delay,max_hits,notes,created_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$id,$name,$slug,$domain,$mode,$status,$redirect_code,$target,$template,
                  json_encode($branding),$params_mode,$param_allow,$param_block,$alert_tg,$delay,$max_hits,$notes,$created]);

    return be_token_get($id);
}

function be_token_get(string $id): array {
    $db = be_db();
    $t = be_q1("SELECT * FROM tokens WHERE id=?", [$id]);
    if (!$t) be_err('token not found', 404);
    return be_token_hydrate($t);
}

function be_token_hydrate(array $t): array {
    $t['branding'] = be_json($t['branding']);
    if (!is_array($t['branding'])) $t['branding'] = [];
    $t['alert_tg'] = (bool)$t['alert_tg'];
    $t['max_hits'] = (int)$t['max_hits'];
    $t['delay'] = (int)$t['delay'];
    $t['redirect_code'] = (int)$t['redirect_code'];
    $t['hits'] = (int)(be_q1("SELECT COUNT(*) c FROM hits WHERE token_id=?", [$t['id']])['c'] ?? 0);
    $t['last_hit'] = (int)(be_q1("SELECT MAX(ts) m FROM hits WHERE token_id=?", [$t['id']])['m'] ?? 0);
    return $t;
}

function be_token_list(): array {
    $db = be_db();
    $rows = $db->query("SELECT * FROM tokens ORDER BY created_at DESC")->fetchAll(ASSOC);
    $out = [];
    foreach ($rows as $t) $out[] = be_token_hydrate($t);
    return $out;
}

function be_token_update(string $id, array $f): array {
    $db = be_db();
    $cur = be_q1("SELECT * FROM tokens WHERE id=?", [$id]);
    if (!$cur) be_err('token not found', 404);
    $up = []; $vals = [];
    $map = ['name','domain','mode','redirect_code','target','template','params_mode','param_allow','param_block','delay','max_hits','notes','slug'];
    foreach ($map as $k) if (array_key_exists($k, $f)) { $up[] = "$k=?"; $vals[] = $f[$k]; }
    if (array_key_exists('branding', $f)) { $up[] = "branding=?"; $vals[] = is_array($f['branding']) ? json_encode($f['branding']) : (string)$f['branding']; }
    if (array_key_exists('status', $f)) {
        $newStatus = in_array(($f['status'] ?? ''), ['active', 'paused'], true) ? (string)$f['status'] : (string)$cur['status'];
        $up[] = "status=?"; $vals[] = $newStatus;
        $up[] = "paused_at=?"; $vals[] = $newStatus === 'paused' ? time() : 0;
    }
    if (array_key_exists('alert_tg', $f)) { $up[] = "alert_tg=?"; $vals[] = $f['alert_tg'] ? 1 : 0; }
    if ($up) { $vals[] = $id; $db->prepare("UPDATE tokens SET " . implode(',', $up) . " WHERE id=?")->execute($vals); }
    return be_token_get($id);
}

function be_token_delete(string $id): void {
    $db = be_db();
    $db->exec("DELETE FROM hits WHERE token_id=" . $db->quote($id));
    $db->exec("DELETE FROM tokens WHERE id=" . $db->quote($id));
}
