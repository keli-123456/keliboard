<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Services\ServerMachine\Cloud\MachineProvisionDiagnostic as Diagnostic;

$data = stream_get_contents(STDIN, 65537);
if (strlen($data) > 65536) exit(1);
$cases = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
if (!is_array($cases) || !array_is_list($cases) || count($cases) > 100) exit(1);
echo json_encode(['classes' => [...Diagnostic::CLASSES, 'other'], 'files' => Diagnostic::FILES,
    'phases' => Diagnostic::PHASES, 'causes' => Diagnostic::CAUSES,
    'accepted' => array_map(static fn ($value) => Diagnostic::safe($value) !== null, $cases)], JSON_THROW_ON_ERROR);
