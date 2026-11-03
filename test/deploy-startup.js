const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const bash = process.env.NEXO_TEST_BASH || (process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'sh');
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vent-as-startup-'));
const slash = value => value.replaceAll('\\', '/');
const log = path.join(root, 'startup.log');
const script = path.join(root, 'scripts/start-dokploy.sh');
const source = path.join(__dirname, '../scripts/start-dokploy.sh');
const base = { ...process.env, START_LOG: slash(log), NIXPACKS_ASSETS_DIR: slash(path.join(root, 'assets')), NIXPACKS_NGINX_CONFIG: slash(path.join(root, 'nginx-generated.conf')) };
delete base.AUTO_MIGRATE;
delete base.FAIL_MIGRATION;
const pathKey = Object.keys(base).find(key => key.toLowerCase() === 'path') || 'PATH';
base[pathKey] = path.join(root, 'bin') + path.delimiter + (base[pathKey] || '');

try {
    for (const dir of ['scripts', 'php', 'bin', 'assets/scripts']) fs.mkdirSync(path.join(root, dir), { recursive: true });
    fs.copyFileSync(source, script);
    fs.writeFileSync(path.join(root, 'php/migrate.php'), '<?php');
    const commands = {
        php: '#!/bin/sh\nprintf "migrate\\n" >> "$START_LOG"\nexit "${FAIL_MIGRATION:-0}"\n',
        node: '#!/bin/sh\nprintf "render %s\\n" "$2" >> "$START_LOG"\n: > "$3"\n',
        'php-fpm': '#!/bin/sh\nprintf "fpm\\n" >> "$START_LOG"\n',
        nginx: '#!/bin/sh\nprintf "nginx\\n" >> "$START_LOG"\n',
    };
    for (const [name, body] of Object.entries(commands)) fs.writeFileSync(path.join(root, 'bin', name), body, { mode: 0o755 });
    const run = extra => {
        fs.writeFileSync(log, '');
        const result = spawnSync(bash, [script], { env: { ...base, ...extra }, encoding: 'utf8', timeout: 15000 });
        if (result.error) throw result.error;
        return { ...result, events: fs.readFileSync(log, 'utf8').trim().split('\n').filter(Boolean) };
    };
    const normal = run({});
    assert.equal(normal.status, 0, normal.stderr);
    assert.equal(normal.events[0], 'migrate');
    assert.ok(normal.events.some(event => event.startsWith('render ')));
    assert.ok(normal.events.includes('nginx'));
    const failed = run({ FAIL_MIGRATION: '23' });
    assert.equal(failed.status, 23);
    assert.deepEqual(failed.events, ['migrate'], 'Un fallo de migración debe impedir iniciar el servidor.');
    const skipped = run({ AUTO_MIGRATE: '0' });
    assert.equal(skipped.status, 0, skipped.stderr);
    assert.ok(!skipped.events.includes('migrate') && skipped.events.includes('nginx'));
    const invalid = run({ AUTO_MIGRATE: 'incorrecto' });
    assert.equal(invalid.status, 1);
    assert.deepEqual(invalid.events, []);
    fs.writeFileSync(path.join(root, 'nginx.template.conf'), '# custom template');
    const custom = run({});
    assert.equal(custom.status, 0, custom.stderr);
    assert.ok(custom.events.some(event => event.includes('nginx.template.conf') && !event.includes('/assets/')));
    console.log('PASS: arranque con migraciones primero, fallo impide servidor, desactivación explícita, configuración inválida y plantilla Nginx personalizada.');
} finally {
    assert.equal(path.dirname(path.resolve(root)), path.resolve(os.tmpdir()));
    assert.ok(path.basename(root).startsWith('vent-as-startup-'));
    fs.rmSync(root, { recursive: true, force: true });
}
