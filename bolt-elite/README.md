# BOLT ELITE REDIRECT

Advanced redirect infrastructure: token redirects, landing templates, visitor
intelligence and alerts in one durable PHP + SQLite service.

## Features

- Token redirect system - instant redirect (301/302/303/307/308) or landing page, plus chain mode (landing then auto-forward).
- 32 responsive landing templates across 10 layout families.
- Dynamic domains and custom slugs - bind a link to a host, or let it work anywhere.
- Visitor intelligence - OS, browser, device class, bot detection (pure PHP UA parser) and country/region/city/ISP via a cached keyless geo lookup with offline fallback.
- Analytics dashboard - KPIs, 14-day trend, top countries, devices, OS, browsers, ISPs, referrers and recent visits.
- Telegram access alerts - optional per link, fired on every visit with the full intel.
- Smart parameter forwarding - merge all params, forward only an allow-list, or strip everything; deny-list supported.
- AI redirect builder - describe a campaign in plain words; the builder picks a template and writes the copy. Falls back to a keyword-driven heuristic when no API key is configured.
- Fully responsive - mobile-first templates and admin.

## Layout

    index.php              front controller (routes /api/*, /admin, /{slug}, /r/{slug}, /t/{id})
    lib.php                core: SQLite schema, config, auth, HTTP + alert helpers
    include/ua.php         user-agent parser
    include/geo.php        cached geo / IP intelligence
    include/tokens.php     token model + resolution
    include/templates.php  32 template presets
    include/tpl_engine.php template engine + responsive shell
    include/serve.php      public serving path (hit logging, params, redirect/landing)
    include/api.php        JSON API
    include/ai.php         AI redirect builder
    include/admin.php      admin SPA shell
    public/static/         admin css + js

## Environment

- `ADMIN_TOKEN` - admin/API token. If unset, a token can be stored in settings.
- `TG_BOT_TOKEN` - optional Telegram bot token for alerts.
- `LLM_API_KEY`, `LLM_BASE_URL`, `LLM_MODEL` - optional, for the AI builder.
- `PORT` - listen port (defaults to 8080).

## Tests

    # unit / integration
    php tests/test_e2e.php

    # http end-to-end (boots a real server)
    bash tests/http_test.sh

Both suites must pass before deploying.

## API

All endpoints require the admin token via `X-Admin-Token` header (or `?token=`).

    GET  /api/ping          health + capability probe (public)
    GET  /api/templates     list the 32 presets
    GET  /api/tokens        list links (+ base url)
    POST /api/tokens/save   create or update a link
    POST /api/tokens/delete delete a link and its hits
    POST /api/tokens/pause  /api/tokens/resume
    GET  /api/stats         dashboard aggregates
    GET  /api/hits          recent visits (?token_id=&limit=)
    POST /api/hits/clear
    POST /api/ai/build      AI copy + template suggestion
    GET  /api/settings      read settings   POST /api/settings - update
    POST /api/alert/test    send a Telegram test alert
