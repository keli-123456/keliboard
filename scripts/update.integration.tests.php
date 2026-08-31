<?php

declare(strict_types=1);

$sourceRoot = dirname(__DIR__);
$shell = PHP_OS_FAMILY === 'Windows' && is_file('C:/Program Files/Git/bin/bash.exe')
    ? 'C:/Program Files/Git/bin/bash.exe'
    : 'sh';
$tempRoot = sys_get_temp_dir() . '/keli-update-integration-' . bin2hex(random_bytes(6));

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child) && !is_link($child)) {
            $removeTree($child);
        } else {
            @chmod($child, 0666);
            @unlink($child);
        }
    }
    @chmod($path, 0777);
    @rmdir($path);
};

$run = static function (array $command, string $cwd, int $expectedExit = 0): array {
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
    if ($exit !== $expectedExit) {
        throw new RuntimeException(
            'unexpected exit ' . $exit . ' for ' . implode(' ', $command)
            . PHP_EOL . $stdout . PHP_EOL . $stderr
        );
    }

    return ['stdout' => $stdout, 'stderr' => $stderr];
};

try {
    $remote = $tempRoot . '/remote.git';
    $producer = $tempRoot . '/producer';
    $deployed = $tempRoot . '/deployed';
    $bin = $tempRoot . '/bin';
    $state = $tempRoot . '/state';
    $home = $tempRoot . '/home';
    mkdir($producer, 0777, true);
    mkdir($bin, 0777, true);
    mkdir($state, 0777, true);
    mkdir($home, 0777, true);

    $run(['git', 'init', '--bare', $remote], $tempRoot);
    $run(['git', 'init', '-b', 'main'], $producer);
    $run(['git', 'config', 'user.email', 'tests@example.com'], $producer);
    $run(['git', 'config', 'user.name', 'Keli Tests'], $producer);
    copy($sourceRoot . '/update.sh', $producer . '/update.sh');
    file_put_contents($producer . '/compose.yaml', "services:\n  web:\n    image: test/keliboard:main\n");
    file_put_contents($producer . '/release.txt', "old\n");
    $run(['git', 'add', '.'], $producer);
    $run(['git', 'commit', '-m', 'old release'], $producer);
    $run(['git', 'remote', 'add', 'origin', $remote], $producer);
    $run(['git', 'push', '-u', 'origin', 'main'], $producer);
    file_put_contents($producer . '/release.txt', "new\n");
    $run(['git', 'add', 'release.txt'], $producer);
    $run(['git', 'commit', '-m', 'new release'], $producer);
    $run(['git', 'push'], $producer);

    $run(['git', 'clone', '--branch', 'main', $remote, $deployed], $tempRoot);
    $run(['git', 'reset', '--hard', 'HEAD^'], $deployed);
    file_put_contents($deployed . '/.env', "APP_KEY=base64:stable-key\n");
    mkdir($deployed . '/storage/app/releases', 0777, true);
    file_put_contents(
        $deployed . '/storage/app/releases/active-compose.override.yaml',
        "services:\n  web:\n    image: sha256:old\n"
    );

    $fakeDocker = <<<'SH'
#!/usr/bin/env sh
set -eu
printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
if [ "${1:-}" = compose ]; then
  shift
  [ "${1:-}" = version ] && exit 0
  args=" $* "
  case "$args" in
    *" ps -q "*) echo fake-container; exit 0 ;;
    *" pull "*|*" up "*|*" run "*|*" exec "*) exit 0 ;;
  esac
  echo "unsupported compose command: $args" >&2
  exit 90
fi
if [ "${1:-}" = inspect ]; then
  echo true
  exit 0
fi
echo "unsupported docker command: $*" >&2
exit 91
SH;
    file_put_contents($bin . '/docker', $fakeDocker);
    @chmod($bin . '/docker', 0755);

    $wrapper = <<<'SH'
#!/usr/bin/env sh
set -eu
root="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
export PATH="$root/bin:$PATH"
export HOME="$root/home"
export FAKE_DOCKER_LOG="$root/state/docker.log"
cd "$root/deployed"
exec sh "$root/deployed/update.sh"
SH;
    file_put_contents($tempRoot . '/run-update.sh', $wrapper);
    @chmod($tempRoot . '/run-update.sh', 0755);

    $result = $run([$shell, $tempRoot . '/run-update.sh'], $tempRoot);
    $output = $result['stdout'] . $result['stderr'];
    if (!str_contains($output, 'Keli update succeeded:')) {
        throw new RuntimeException("success marker missing\n{$output}");
    }
    if (trim($run(['git', 'rev-parse', 'HEAD'], $deployed)['stdout'])
        !== trim($run(['git', 'rev-parse', 'origin/main'], $deployed)['stdout'])) {
        throw new RuntimeException('deployed checkout did not advance to origin/main');
    }
    if (is_file($deployed . '/storage/app/releases/active-compose.override.yaml')) {
        throw new RuntimeException('stale safe-deployment image override was not removed');
    }
    if (trim((string) file_get_contents($deployed . '/.env')) !== 'APP_KEY=base64:stable-key') {
        throw new RuntimeException('APP_KEY changed during update');
    }

    $dockerLog = (string) file_get_contents($state . '/docker.log');
    foreach ([
        'pull' => 'image pull',
        'composer install --no-dev' => 'locked dependency install',
        'php artisan xboard:update' => 'database update',
        'up -d --remove-orphans' => 'service recreation',
    ] as $needle => $label) {
        if (!str_contains($dockerLog, $needle)) {
            throw new RuntimeException("update did not perform {$label}");
        }
    }

    fwrite(STDOUT, "direct update integration test passed\n");
} finally {
    $removeTree($tempRoot);
}
