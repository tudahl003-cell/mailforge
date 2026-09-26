<?php
// BOLT ELITE REDIRECT - visitor intelligence (device / OS / browser)
// Pure-PHP UA parser: OS, browser, device class, bot class.
// No external deps.

function be_parse_ua(?string $ua): array {
    $ua = (string)$ua;
    $out = [
        'os'      => 'unknown',
        'os_ver'  => '',
        'browser' => 'unknown',
        'br_ver'  => '',
        'engine'  => '',
        'device'  => 'desktop',
        'is_bot'  => false,
        'bot'     => '',
    ];

    // ---- Bots / crawlers (checked first) ----
    $bots = [
        'googlebot'      => ['Googlebot', 'search'],
        'bingbot'        => ['bingbot', 'search'],
        'duckduckbot'    => ['duckduckbot/bot', 'search'],
        'baiduspider'    => ['baiduspider', 'search'],
        'yandexbot'      => ['yandexbot', 'search'],
        'facebookexternalhit' => ['facebookexternalhit', 'preview'],
        'facebookbot'    => ['facebookbot', 'preview'],
        'twitterbot'     => ['twitterbot', 'preview'],
        'whatsapp'       => ['whatsapp', 'preview'],
        'telegrambot'    => ['telegrambot', 'preview'],
        'discordbot'     => ['discordbot', 'preview'],
        'linkedinbot'    => ['linkedinbot', 'preview'],
        'slackbot'       => ['slackbot', 'preview'],
        'applebot'       => ['applebot', 'preview'],
        'linkedin'       => ['linkedinbot', 'preview'],
        'slurp'          => ['slurp', 'search'],
        'crawler'        => ['crawler', 'crawl'],
        'spider'         => ['spider', 'crawl'],
        'curl'           => ['curl', 'tool'],
        'wget'           => ['wget', 'tool'],
        'python-requests'=> ['python-requests', 'tool'],
        'python-urllib'  => ['python-urllib', 'tool'],
        'go-http-client' => ['go-http-client', 'tool'],
        'headlesschrome' => ['headless-chrome', 'automation'],
        'puppeteer'      => ['puppeteer', 'automation'],
        'selenium'       => ['selenium', 'automation'],
        'playwright'     => ['playwright', 'automation'],
        'httpclient'     => ['http-client', 'tool'],
    ];
    $l = strtolower($ua);
    foreach ($bots as $name => $pat) {
        foreach ((array)$pat[0] as $needle) {
            if ($needle && stripos($l, $needle) !== false) {
                $out['is_bot'] = true;
                $out['bot'] = $name;
                $out['bot_class'] = $pat[1];
                break 2;
            }
        }
    }

    // ---- OS ----
    if (preg_match('/Windows NT ([0-9.]+)/', $ua, $m)) {
        $v = $m[1];
        $map = ['10.0'=>'10/11','6.3'=>'8.1','6.2'=>'8','6.1'=>'7','6.0'=>'Vista'];
        $out['os'] = 'Windows';
        $out['os_ver'] = $map[$v] ?? $v;
    } elseif (preg_match('/Android ([0-9.]+)/', $ua, $m)) {
        $out['os'] = 'Android'; $out['os_ver'] = $m[1];
    } elseif (preg_match('/iPhone OS ([0-9_]+)/', $ua, $m)) {
        $out['os'] = 'iOS'; $out['os_ver'] = str_replace('_','.',$m[1]);
    } elseif (preg_match('/iPad; CPU OS ([0-9_]+)/', $ua, $m)) {
        $out['os'] = 'iPadOS'; $out['os_ver'] = str_replace('_','.',$m[1]);
    } elseif (preg_match('/Mac OS X ([0-9_.]+)/', $ua, $m)) {
        $out['os'] = 'macOS'; $out['os_ver'] = str_replace('_','.',$m[1]);
    } elseif (preg_match('/CrOS/', $ua)) {
        $out['os'] = 'ChromeOS';
    } elseif (preg_match('/(Linux|Ubuntu|Fedora|Debian|Arch|CentOS|openSUSE)/i', $ua)) {
        $out['os'] = 'Linux';
    } elseif (preg_match('/Windows (Phone|RT)/', $ua)) {
        $out['os'] = 'Windows Phone';
    }

    // ---- Browser ----
    if (preg_match('/Edg\/([0-9.]+)/', $ua, $m)) {
        $out['browser'] = 'Edge'; $out['br_ver'] = $m[1]; $out['engine'] = 'Blink';
    } elseif (preg_match('/OPR\/([0-9.]+)/', $ua, $m)) {
        $out['browser'] = 'Opera'; $out['br_ver'] = $m[1]; $out['engine'] = 'Blink';
    } elseif (preg_match('/SamsungBrowser\/([0-9.]+)/', $ua, $m)) {
        $out['browser'] = 'Samsung Internet'; $out['br_ver'] = $m[1]; $out['engine'] = 'Blink';
    } elseif (preg_match('/Version\/([0-9.]+).*Safari/', $ua) && preg_match('/Chrome\//', $ua) && !preg_match('/Chrom\//', $ua)) {
        if (preg_match('/Version\/([0-9.]+)/', $ua, $m)) { $out['browser'] = 'Safari'; $out['br_ver'] = $m[1]; $out['engine'] = 'WebKit'; }
    } elseif (preg_match('/Chrome\/([0-9.]+)/', $ua, $m)) {
        $out['browser'] = 'Chrome'; $out['br_ver'] = $m[1]; $out['engine'] = 'Blink';
    } elseif (preg_match('/Firefox\/([0-9.]+)/', $ua, $m)) {
        $out['browser'] = 'Firefox'; $out['br_ver'] = $m[1]; $out['engine'] = 'Gecko';
    } elseif (preg_match('/MSIE ([0-9.]+)/', $ua, $m)) {
        $out['browser'] = 'Internet Explorer'; $out['br_ver'] = $m[1]; $out['engine'] = 'Trident';
    } elseif (preg_match('/Trident\//', $ua)) {
        $out['browser'] = 'Internet Explorer'; $out['engine'] = 'Trident';
    } elseif (preg_match('/Safari\//', $ua, $m)) {
        $out['browser'] = 'Safari'; $out['engine'] = 'WebKit';
    }

    // ---- Device class ----
    $mobile = (bool)preg_match('/Mobi|iPhone|iPod|Android(?!.*Chrome\/.*Windows)|IEMobile|BlackBerry|Opera Mini|Opera Mobi|Kindle|Silk|Windows Phone/i', $ua);
    if ($out['os'] === 'iOS' || $out['os'] === 'iPadOS') $mobile = true;
    if ($out['os'] === 'Android') $mobile = true;
    $out['device'] = $mobile ? 'mobile' : 'desktop';

    return $out;
}
