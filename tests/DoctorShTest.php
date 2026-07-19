<?php

use larablocks\MapAi\Doctor;
use larablocks\MapAi\Installer;

/**
 * Executes doctor.sh against a real temp directory and returns its output/exit code.
 * Verifies the bash port behaves identically to Doctor.php, since the two are
 * maintained independently (bash has no PHP runtime to fall back on).
 *
 * @return array{exit: int, output: string}
 */
function runDoctorSh(string $target, bool $fix = false): array
{
    $scriptPath = dirname(Installer::stubsPath()).'/doctor.sh';
    $cmd = ['bash', $scriptPath, $target];
    if ($fix) {
        $cmd[] = '--fix';
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return ['exit' => $exit, 'output' => $stdout.$stderr];
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/map-ai-doctor-sh-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->mapAiDir = dirname(Installer::stubsPath());
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tempDir);
});

it('exits 1 and lists every stub as fixable missing-file on an empty project', function () {
    $result = runDoctorSh($this->tempDir);

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('[FIXABLE]  missing-file');
    expect($result['output'])->toContain('AGENTS.md');
    expect($result['output'])->toContain('docs/BUGS.md');
});

it('exits 0 clean right after --fix on an empty project', function () {
    runDoctorSh($this->tempDir, fix: true);
    $result = runDoctorSh($this->tempDir);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('Clean');
});

it('never touches an out-of-date scaffold file, in check or fix mode', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    file_put_contents($this->tempDir.'/docs/BUGS.md', "# stale project bugs\ncustom content\n");

    $check = runDoctorSh($this->tempDir);
    expect($check['output'])->toContain('[REVIEW]   outdated-scaffold-file  docs/BUGS.md');

    runDoctorSh($this->tempDir, fix: true);
    expect(file_get_contents($this->tempDir.'/docs/BUGS.md'))->toBe("# stale project bugs\ncustom content\n");
});

it('flags AGENTS.md over the line cap as review-only, not fixable', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    $bloated = str_repeat("- extra line\n", 110);
    file_put_contents($this->tempDir.'/AGENTS.md', $bloated);

    $result = runDoctorSh($this->tempDir);

    expect($result['output'])->toContain('[REVIEW]   agents-md-too-long');
});

it('regenerates copilot-instructions.md when the only difference is added AGENTS.md content', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    file_put_contents(
        $this->tempDir.'/AGENTS.md',
        file_get_contents($this->tempDir.'/AGENTS.md')."\n## Project hard rules\n- No calls to the widget-frobnicator API from a dev session.\n"
    );

    $check = runDoctorSh($this->tempDir);
    expect($check['output'])->toContain('[FIXABLE]  copilot-out-of-sync');

    runDoctorSh($this->tempDir, fix: true);
    expect(file_get_contents($this->tempDir.'/.github/copilot-instructions.md'))->toContain('widget-frobnicator');
});

it('leaves copilot-instructions.md untouched when regenerating would drop a hand-added line', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    $copilotPath = $this->tempDir.'/.github/copilot-instructions.md';
    $original = file_get_contents($copilotPath)."\n- Hand-added Copilot-only note that lives nowhere else\n";
    file_put_contents($copilotPath, $original);

    $check = runDoctorSh($this->tempDir);
    expect($check['output'])->toContain('[REVIEW]   copilot-out-of-sync');

    runDoctorSh($this->tempDir, fix: true);
    expect(file_get_contents($copilotPath))->toBe($original);
});

it('produces byte-identical copilot-instructions.md output to Doctor.php for the same project state', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    file_put_contents(
        $this->tempDir.'/AGENTS.md',
        file_get_contents($this->tempDir.'/AGENTS.md')."\n## Project hard rules\n- No calls to the widget-frobnicator API from a dev session.\n"
    );

    $phpRegenerated = (new Doctor)->regenerateCopilotInstructions($this->tempDir);

    runDoctorSh($this->tempDir, fix: true);
    $bashRegenerated = file_get_contents($this->tempDir.'/.github/copilot-instructions.md');

    expect($bashRegenerated)->toBe($phpRegenerated);
});
