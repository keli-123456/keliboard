<?php

declare(strict_types=1);

$sourceRoot = dirname(__DIR__);
$shell = PHP_OS_FAMILY === 'Windows' && is_file('C:/Program Files/Git/bin/bash.exe')
    ? 'C:/Program Files/Git/bin/bash.exe'
    : 'sh';
$tempRoot = sys_get_temp_dir() . '/keli-deploy-integration-' . bin2hex(random_bytes(6));

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        throw new RuntimeException("failed to list temporary directory: {$path}");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child) && !is_link($child)) {
            $removeTree($child);
        } else {
            @chmod($child, 0666);
            if (!unlink($child)) {
                throw new RuntimeException("failed to remove temporary file: {$child}");
            }
        }
    }
    @chmod($path, 0777);
    if (!rmdir($path)) {
        throw new RuntimeException("failed to remove temporary directory: {$path}");
    }
};

$run = static function (array $command, string $cwd, ?int $expectedExit = 0): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start: ' . implode(' ', $command));
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($expectedExit !== null && $exit !== $expectedExit) {
        throw new RuntimeException(
            'unexpected exit ' . $exit . ' for ' . implode(' ', $command)
            . PHP_EOL . $stdout . PHP_EOL . $stderr
        );
    }
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
};

$fakeDocker = <<<'SH'
#!/usr/bin/env sh
set -eu

mkdir -p "$FAKE_STATE_DIR"
printf '%s\n' "$*" >> "$FAKE_STATE_DIR/docker.log"

if [ "${1:-}" = compose ]; then
  shift
  if [ "${1:-}" = version ]; then
    exit 0
  fi
  args=" $* "
  case "$args" in
    *" ps -q "*)
      echo fake-container
      exit 0
      ;;
    *" config --images "*)
      echo "$FAKE_PREVIOUS_IMAGE_REF"
      exit 0
      ;;
    *" exec "*)
      case "$args" in
        *"backup:database"*)
          echo '{"status":"succeeded","record_id":42,"path":"storage/app/backups/release.sql.gz","checksum":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}'
          ;;
        *"backup:restore-drill"*)
          echo '{"ok":true,"record_id":42}'
          ;;
        *)
          echo '{"status":"passed"}'
          ;;
      esac
      exit 0
      ;;
    *" run "*|*" stop "*|*" down "*)
      exit 0
      ;;
    *" up "*)
      case "$args" in
        *" --no-deps web "*)
          exit 0
          ;;
      esac
      count_file="$FAKE_STATE_DIR/current-up-count"
      count=0
      if [ -f "$count_file" ]; then
        count="$(cat "$count_file")"
      fi
      count=$((count + 1))
      printf '%s\n' "$count" > "$count_file"
      if [ "${FAIL_CUTOVER:-0}" = 1 ] && [ "$count" = 1 ]; then
        echo "simulated cutover failure" >&2
        exit 42
      fi
      exit 0
      ;;
  esac
  echo "unsupported fake compose command: $args" >&2
  exit 90
fi

if [ "${1:-}" = inspect ]; then
  case "$*" in
    *".Config.Image"*) echo "$FAKE_PREVIOUS_IMAGE_REF" ;;
    *".State.Running"*) echo true ;;
    *".Image"*) echo "$FAKE_PREVIOUS_IMAGE_ID" ;;
    *) exit 91 ;;
  esac
  exit 0
fi

if [ "${1:-}" = image ] && [ "${2:-}" = inspect ]; then
  echo "$FAKE_TARGET_IMAGE_ID"
  exit 0
fi

if [ "${1:-}" = pull ]; then
  exit 0
fi

echo "unsupported fake docker command: $*" >&2
exit 92
SH;

$wrapper = <<<'SH'
#!/usr/bin/env sh
set -eu
scenario_root="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
export PATH="$scenario_root/bin:$PATH"
export FAKE_STATE_DIR="$scenario_root/state"
export FAKE_PREVIOUS_IMAGE_ID="sha256:1111111111111111111111111111111111111111111111111111111111111111"
export FAKE_PREVIOUS_IMAGE_REF="test/current@sha256:1111111111111111111111111111111111111111111111111111111111111111"
export FAKE_TARGET_IMAGE_ID="sha256:3333333333333333333333333333333333333333333333333333333333333333"
exec sh "$scenario_root/repo/scripts/deploy-release.sh" \
  --no-fetch \
  --ref="$TARGET_GIT_SHA" \
  --image="test/target@sha256:2222222222222222222222222222222222222222222222222222222222222222" \
  --compose-file=compose.yaml
SH;

$createScenario = static function (string $name, bool $failCutover) use (
    $tempRoot,
    $sourceRoot,
    $fakeDocker,
    $wrapper,
    $shell,
    $run
): void {
    $scenario = $tempRoot . DIRECTORY_SEPARATOR . $name;
    $repo = $scenario . DIRECTORY_SEPARATOR . 'repo';
    mkdir($repo . DIRECTORY_SEPARATOR . 'scripts', 0777, true);
    mkdir($scenario . DIRECTORY_SEPARATOR . 'bin', 0777, true);
    mkdir($scenario . DIRECTORY_SEPARATOR . 'state', 0777, true);

    copy($sourceRoot . '/scripts/deploy-release.sh', $repo . '/scripts/deploy-release.sh');
    copy($sourceRoot . '/scripts/release-smoke.php', $repo . '/scripts/release-smoke.php');
    file_put_contents($repo . '/.gitattributes', "*.sh text eol=lf\n");
    file_put_contents($repo . '/.env', "APP_KEY=base64:test\n");
    file_put_contents($repo . '/compose.yaml', "services:\n  web:\n    image: test/current\n  horizon:\n    image: test/current\n  ws-server:\n    image: test/current\n  redis:\n    image: redis:7\n  redis-cache:\n    image: redis:7\n");

    $run(['git', 'init', '-q'], $repo);
    $run(['git', 'config', 'user.email', 'release-test@keli.local'], $repo);
    $run(['git', 'config', 'user.name', 'Keli Release Test'], $repo);
    $run(['git', 'config', 'commit.gpgsign', 'false'], $repo);
    $run(['git', 'add', '.'], $repo);
    $run(['git', 'commit', '-q', '-m', 'previous release'], $repo);
    $previousSha = trim($run(['git', 'rev-parse', 'HEAD'], $repo)['stdout']);
    file_put_contents($repo . '/target-release.txt', "candidate\n");
    $run(['git', 'add', 'target-release.txt'], $repo);
    $run(['git', 'commit', '-q', '-m', 'target release'], $repo);
    $targetSha = trim($run(['git', 'rev-parse', 'HEAD'], $repo)['stdout']);
    $run(['git', 'reset', '--hard', $previousSha], $repo);

    $dockerPath = $scenario . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'docker';
    $wrapperPath = $scenario . DIRECTORY_SEPARATOR . 'run.sh';
    file_put_contents($dockerPath, $fakeDocker);
    file_put_contents($wrapperPath, $wrapper);
    chmod($dockerPath, 0755);
    chmod($wrapperPath, 0755);

    $oldTarget = getenv('TARGET_GIT_SHA');
    $oldFailure = getenv('FAIL_CUTOVER');
    putenv('TARGET_GIT_SHA=' . $targetSha);
    putenv('FAIL_CUTOVER=' . ($failCutover ? '1' : '0'));
    try {
        $result = $run([$shell, $wrapperPath], $scenario, null);
    } finally {
        $oldTarget === false ? putenv('TARGET_GIT_SHA') : putenv('TARGET_GIT_SHA=' . $oldTarget);
        $oldFailure === false ? putenv('FAIL_CUTOVER') : putenv('FAIL_CUTOVER=' . $oldFailure);
    }

    if ($failCutover ? $result['exit'] === 0 : $result['exit'] !== 0) {
        throw new RuntimeException(
            "{$name} returned unexpected status {$result['exit']}"
            . PHP_EOL . $result['stdout'] . PHP_EOL . $result['stderr']
        );
    }

    $receipts = glob($repo . '/storage/app/releases/deployments/*/deployment-receipt.jsonl');
    if (!is_array($receipts) || count($receipts) !== 1) {
        throw new RuntimeException("{$name} did not create exactly one deployment receipt");
    }
    $expected = $failCutover ? 'rolled_back' : 'succeeded';
    $run([
        PHP_BINARY,
        $sourceRoot . '/scripts/verify-deployment-receipt.php',
        '--expect=' . $expected,
        $receipts[0],
    ], $sourceRoot);

    $receipt = (string) file_get_contents($receipts[0]);
    if (!str_contains($receipt, 'backup-drill.json#record=42')) {
        throw new RuntimeException("{$name} receipt is not bound to backup record 42");
    }
    $dockerLog = (string) file_get_contents($scenario . '/state/docker.log');
    if (!str_contains($dockerLog, 'backup:restore-drill --id=42')) {
        throw new RuntimeException("{$name} did not drill the exact backup record");
    }

    $activeOverride = (string) file_get_contents($repo . '/storage/app/releases/active-compose.override.yaml');
    $expectedImage = $failCutover
        ? 'sha256:1111111111111111111111111111111111111111111111111111111111111111'
        : 'sha256:3333333333333333333333333333333333333333333333333333333333333333';
    if (!str_contains($activeOverride, $expectedImage)) {
        throw new RuntimeException("{$name} active image does not match {$expected}");
    }
    $actualSha = trim($run(['git', 'rev-parse', 'HEAD'], $repo)['stdout']);
    $expectedSha = $failCutover ? $previousSha : $targetSha;
    if ($actualSha !== $expectedSha) {
        throw new RuntimeException("{$name} Git SHA does not match {$expected}");
    }
};

mkdir($tempRoot, 0777, true);
try {
    $createScenario('success', false);
    $createScenario('rollback', true);
} finally {
    $removeTree($tempRoot);
}

fwrite(STDOUT, "safe deployment integration tests passed\n");
