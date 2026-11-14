<?php
require dirname(__DIR__, 3) . '/app/Services/ServerMachine/Cloud/CloudException.php';

use App\Services\ServerMachine\Cloud\CloudException;

$out = [];
foreach (CloudException::REPLACEMENT_CHECKS as $guard => $names) {
    $checks = array_fill_keys($names, true); $checks[$names[0]] = false;
    $checks['NEVER_EXPORT_PRIVATE_DATA'] = 'NEVER_EXPORT_PRIVATE_DATA';
    $error = CloudException::replacementEvidence($guard, $checks);
    $out[$guard] = CloudException::safeReplacementDiagnostic($error->replacementDiagnostic());
}
echo json_encode($out, JSON_THROW_ON_ERROR);
