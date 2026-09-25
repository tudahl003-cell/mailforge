<?php
// api/settings.php — runtime settings (LLM provider, app base, alerting)
require_once __DIR__ . '/../../lib.php';
require_auth();
$in = json_in();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $c = cfg();
    $eff = effective_admin_token();
    $fromEnv = (ADMIN_TOKEN !== '');
    json_out(['ok' => true, 'settings' => [
        'llm_provider'  => $c['llm_provider'] ?? 'openai',
        'llm_api_key'   => (isset($c['llm_api_key']) && $c['llm_api_key'] !== '') ? '••••••••' : '',
        'llm_base_url'  => $c['llm_base_url'] ?? LLM_BASE_URL,
        'llm_model'     => $c['llm_model'] ?? LLM_MODEL,
        'app_base'      => $c['app_base'] ?? APP_BASE,
        'admin_token'   => $fromEnv ? '•••••••• (env)' : ($eff !== '' ? $eff : ''),
        'admin_from_env'=> $fromEnv,
        'tg_bot_token'  => (TG_BOT_TOKEN !== '') ? '••••••••' : '',
        'tg_chat_id'    => TG_CHAT_ID,
        'data_dir'      => DATA_DIR,
    ], 'defaults' => ['llm_model' => LLM_MODEL, 'llm_base_url' => LLM_BASE_URL]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patch = [];
    $keys = ['llm_provider','llm_api_key','llm_base_url','llm_model','app_base'];
    foreach ($keys as $k) {
        if (array_key_exists($k, $in)) {
            // don't overwrite a real key with the masked placeholder
            if ($k === 'llm_api_key' && str_starts_with((string)$in[$k], '•')) continue;
            $patch[$k] = $in[$k];
        }
    }
    // create / rotate the settings-level admin token
    if (array_key_exists('admin_token', $in)) {
        $new = (string)$in['admin_token'];
        if ($new === 'generate' || $new === '') $new = new_token(12);
        if ($new === '') $new = new_token(12);
        $patch['admin_token'] = $new;
        $patch['admin_token_shown'] = 1; // UI shows it this once
    }
    cfg_set($patch);
    json_out(['ok' => true, 'saved' => array_keys($patch),
              'admin_token' => $patch['admin_token'] ?? null]);
}

json_out(['ok' => false, 'error' => 'GET or POST only'], 405);
