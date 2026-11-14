<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/tools/machine-acceptance/cli_diagnostics.php';

$input = json_decode(stream_get_contents(STDIN, 65537), true, 64, JSON_THROW_ON_ERROR);
if (!is_array($input) || !array_is_list($input) || count($input) > 100) exit(1);
$results = [];
foreach ($input as $item) {
    $value = $item['value'] ?? null;
    $results[] = is_array($value) && match ($item['kind'] ?? null) {
        'window' => keli_acceptance_source_window_diagnostic($value) !== null,
        'fields' => keli_acceptance_cli_fields($value) !== null,
        default => false,
    };
}
echo json_encode($results, JSON_THROW_ON_ERROR);
