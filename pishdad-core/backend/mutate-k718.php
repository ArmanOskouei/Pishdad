<?php

/**
 * K7.18 — mutation verification.
 *
 * A test that cannot fail proves nothing. Each mutation breaks exactly ONE rule
 * on purpose, then runs the test that is supposed to catch it. If the test
 * still passes, it is vacuous and the implementation is unverified.
 *
 * The file is restored from an in-memory copy, so a crash cannot leave the tree
 * broken — but a hard `exit` mid-loop is still avoided by try/finally.
 */

$app = '/var/www/html';

$mutations = [
    [
        'label' => 'install-time pages gate removed entirely',
        'filter' => 'test_the_installer_rejects_a_bad_blocks_list',
        'file' => $app.'/app/Services/Plugins/PluginPackageValidator.php',
        'from' => "foreach (PluginPageContract::check(\$manifest['pages'] ?? null) as \$issue) {\n            \$issues[] = \$issue;\n        }",
        'to' => '',
    ],
    [
        'label' => 'blocks.type allowlist accepts anything',
        'filter' => 'test_an_unknown_block_type_is_rejected',
        'file' => $app.'/app/Services/Plugins/PluginPageContract.php',
        'from' => '! in_array($type, $allowed, true)',
        'to' => 'false',
    ],
    [
        'label' => 'layout allowlist accepts anything',
        'filter' => 'test_layout_rejects_anything_outside_the_allowlist',
        'file' => $app.'/app/Services/Plugins/PluginPageContract.php',
        'from' => "! in_array(\$def['layout'], self::LAYOUTS, true)",
        'to' => 'false',
    ],
    [
        'label' => 'read-time unknown-block filter removed',
        'filter' => 'test_legacy_pages_have_unknown_blocks_filtered_not_the_whole_page',
        'file' => $app.'/app/Services/Plugins/ManifestRegistry.php',
        'from' => '! in_array($type, $allowed, true)',
        'to' => 'false',
    ],
    [
        'label' => 'read-time layout gate removed',
        'filter' => 'test_a_legacy_invalid_layout_drops_the_page_from_the_registry',
        'file' => $app.'/app/Services/Plugins/ManifestRegistry.php',
        'from' => "if (! in_array(\$layout, PluginPageContract::LAYOUTS, true)) {\n            return null;\n        }",
        'to' => '',
    ],
    [
        'label' => 'migration stops repairing an invalid layout',
        'filter' => 'test_the_migration_repairs_a_legacy_layout_in_place',
        'file' => $app.'/database/migrations/2026_10_09_000001_normalize_plugin_page_blocks.php',
        'from' => "\$out[\$key]['layout'] = PluginPageContract::DEFAULT_LAYOUT;",
        'to' => '',
    ],
    [
        'label' => 'migration destroys valid blocks (data loss)',
        'filter' => 'test_the_migration_repairs_a_legacy_layout_in_place',
        'file' => $app.'/database/migrations/2026_10_09_000001_normalize_plugin_page_blocks.php',
        'from' => '$kept[] = $entry;',
        'to' => 'continue;',
    ],
    [
        'label' => 'duplicate page paths allowed',
        'filter' => 'test_two_pages_cannot_claim_the_same_path',
        'file' => $app.'/app/Services/Plugins/PluginPageContract.php',
        'from' => '} elseif (isset($seenPaths[$normalized])) {',
        'to' => '} elseif (false) {',
    ],
    [
        'label' => 'path containment check removed',
        'filter' => 'test_a_path_outside_admin_is_rejected',
        'file' => $app.'/app/Services/Plugins/PluginPageContract.php',
        'from' => "if (! str_starts_with(\$path, '/admin/') || str_contains(\$path, '..') || str_contains(\$path, '//')) {",
        'to' => 'if (false) {',
    ],
    [
        'label' => 'title_fa no longer required',
        'filter' => 'test_title_is_required',
        'file' => $app.'/app/Services/Plugins/PluginPageContract.php',
        'from' => "if (! is_string(\$def['title_fa'] ?? null) || trim((string) (\$def['title_fa'] ?? '')) === '') {",
        'to' => 'if (false) {',
    ],
    [
        'label' => 'empty core registry silently accepts blocks',
        'filter' => 'test_when_the_core_registry_is_empty_blocks_are_an_error',
        'file' => $app.'/app/Services/Plugins/PluginPageContract.php',
        'from' => 'if ($allowed === []) {',
        'to' => 'if (false) {',
    ],
    [
        'label' => 'per-page block cap removed',
        'filter' => 'test_the_block_cap_is_enforced',
        'file' => $app.'/app/Services/Plugins/PluginPageContract.php',
        'from' => 'if (count($blocks) > self::MAX_BLOCKS_PER_PAGE) {',
        'to' => 'if (false) {',
    ],
    [
        'label' => 'migration keeps rewriting on every run (not idempotent)',
        'filter' => 'test_the_migration_is_idempotent',
        'file' => $app.'/database/migrations/2026_10_09_000001_normalize_plugin_page_blocks.php',
        // A non-idempotent migration here would keep touching the row. The
        // mutation forces `$dirty` to stay true forever, so every run writes.
        'from' => 'if (! $dirty) {',
        'to' => 'if (false) {',
    ],
    [
        'label' => 'migration left the invalid layout unrepaired but reported done',
        'filter' => 'test_the_migration_repairs_a_legacy_layout_in_place',
        'file' => $app.'/database/migrations/2026_10_09_000001_normalize_plugin_page_blocks.php',
        'from' => "\$out[\$key]['layout'] = PluginPageContract::DEFAULT_LAYOUT;",
        'to' => "\$out[\$key]['layout'] = \$def['layout'];",
    ],
];

$red = "\033[31m"; $green = "\033[32m"; $off = "\033[0m";

echo "\n  K7.18 mutation verification\n";
echo "  ────────────────────────────────────────────────────────\n";

$pass = 0; $fail = 0; $skipped = 0;

foreach ($mutations as $m) {
    $original = @file_get_contents($m['file']);
    if ($original === false) {
        printf("  %sSKIP%s    %s — file unreadable\n", $yellow ?? $red, $off, $m['label']);
        $skipped++;
        continue;
    }

    if (! str_contains($original, $m['from'])) {
        printf("  %sNO-OP%s   %s — anchor not found; the mutation did not apply\n", $red, $off, $m['label']);
        $fail++;
        file_put_contents($m['file'], $original);
        continue;
    }

    file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));

    $out = shell_exec(
        'cd /var/www/html && vendor/bin/phpunit --filter '.escapeshellarg($m['filter']).' 2>&1'
    );

    file_put_contents($m['file'], $original);

    $survived = str_contains((string) $out, 'OK, but') || str_contains((string) $out, "\nOK");

    if ($survived) {
        printf("  %sSURVIVED%s  %s\n", $red, $off, $m['label']);
        $fail++;
    } else {
        printf("  %skilled%s    %s\n", $green, $off, $m['label']);
        $pass++;
    }
}

echo "\n  ────────────────────────────────────────────────────────\n";
printf("  killed: %d   survived: %d   skipped: %d\n", $pass, $fail, $skipped);
echo "\n";

exit($fail === 0 ? 0 : 1);
