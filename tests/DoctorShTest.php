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
function runDoctorSh(string $target, bool $fix = false, ?string $mapAiDir = null): array
{
    $scriptPath = ($mapAiDir ?? dirname(Installer::stubsPath())).'/doctor.sh';
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

/**
 * Same as runDoctorSh() but for --interactive, feeding $stdin (e.g. "y\n" or
 * "n\n" per prompt) to the process the way a developer's terminal input would
 * arrive.
 *
 * @return array{exit: int, output: string}
 */
function runDoctorShInteractive(string $target, string $stdin, ?string $mapAiDir = null): array
{
    $scriptPath = ($mapAiDir ?? dirname(Installer::stubsPath())).'/doctor.sh';
    $cmd = ['bash', $scriptPath, $target, '--interactive'];

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
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

/**
 * Builds a full synthetic map-ai root (doctor.sh/install.sh/lib.sh + a stubs/ copy)
 * so a test can point doctor.sh at stubs with extra content, without touching the
 * real stubs/ directory. doctor.sh resolves its stub path from its own location,
 * so the copy has to be a real sibling directory, not just a stub folder.
 */
function syntheticMapAiRoot(string $tempBase, string $relativeStubFile, string $extraLine): string
{
    $root = $tempBase.'-maproot';
    mkdir($root.'/stubs', 0755, true);

    $mapAiDir = dirname(Installer::stubsPath());
    foreach (['doctor.sh', 'install.sh', 'lib.sh'] as $script) {
        copy($mapAiDir.'/'.$script, $root.'/'.$script);
    }

    $source = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Installer::stubsPath(), RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($source as $item) {
        $dest = $root.'/stubs/'.$source->getSubPathName();
        $item->isDir() ? mkdir($dest, 0755, true) : copy($item->getPathname(), $dest);
    }

    file_put_contents($root.'/stubs/'.$relativeStubFile, file_get_contents($root.'/stubs/'.$relativeStubFile).$extraLine);

    return $root;
}

it('never flags a scaffold file that only has extra content the stub does not', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    file_put_contents(
        $this->tempDir.'/docs/GLOSSARY.md',
        file_get_contents($this->tempDir.'/docs/GLOSSARY.md')."\n| BUG-N | This project's bug ID convention |\n"
    );

    $result = runDoctorSh($this->tempDir);

    expect($result['output'])->not->toContain('docs/GLOSSARY.md');
    expect($result['exit'])->toBe(0);
});

it('flags new stub content as missing-template-updates and patches it in without touching custom content', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    $agents = file_get_contents($this->tempDir.'/AGENTS.md');
    file_put_contents(
        $this->tempDir.'/AGENTS.md',
        str_replace('## Write rules', "Read @docs/JOB_SOURCES.md before proposing a new scraper\n\n## Write rules", $agents)
    );

    $syntheticRoot = syntheticMapAiRoot($this->tempDir, 'AGENTS.md', "Read @docs/FEATURE_FLAGS.md when starting a new feature\n");

    $check = runDoctorSh($this->tempDir, mapAiDir: $syntheticRoot);
    expect($check['output'])->toContain('[FIXABLE]  missing-template-updates AGENTS.md');

    runDoctorSh($this->tempDir, fix: true, mapAiDir: $syntheticRoot);
    $patched = file_get_contents($this->tempDir.'/AGENTS.md');

    expect($patched)->toContain('Read @docs/FEATURE_FLAGS.md when starting a new feature');
    expect($patched)->toContain('Read @docs/JOB_SOURCES.md before proposing a new scraper');

    shell_exec('rm -rf '.escapeshellarg($syntheticRoot));
});

it('produces byte-identical patched output to Doctor.php for the same project and stub state', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    $agents = file_get_contents($this->tempDir.'/AGENTS.md');
    file_put_contents(
        $this->tempDir.'/AGENTS.md',
        str_replace('## Write rules', "Read @docs/JOB_SOURCES.md before proposing a new scraper\n\n## Write rules", $agents)
    );

    $syntheticRoot = syntheticMapAiRoot($this->tempDir, 'AGENTS.md', "Read @docs/FEATURE_FLAGS.md when starting a new feature\n");

    $phpTarget = $this->tempDir.'-php-copy';
    shell_exec('cp -r '.escapeshellarg($this->tempDir).' '.escapeshellarg($phpTarget));
    (new Doctor)->fix($syntheticRoot.'/stubs', $phpTarget);
    $phpPatched = file_get_contents($phpTarget.'/AGENTS.md');

    runDoctorSh($this->tempDir, fix: true, mapAiDir: $syntheticRoot);
    $bashPatched = file_get_contents($this->tempDir.'/AGENTS.md');

    expect($bashPatched)->toBe($phpPatched);

    shell_exec('rm -rf '.escapeshellarg($syntheticRoot).' '.escapeshellarg($phpTarget));
});

it('auto-replaces a reworded italic note line, leaving real content alone, matching Doctor.php byte for byte', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');
    file_put_contents(
        $this->tempDir.'/docs/GLOSSARY.md',
        file_get_contents($this->tempDir.'/docs/GLOSSARY.md')."| BUG-N | This project's bug ID convention |\n"
    );

    $syntheticRoot = $this->tempDir.'-maproot';
    mkdir($syntheticRoot.'/stubs', 0755, true);
    foreach (['doctor.sh', 'install.sh', 'lib.sh'] as $script) {
        copy($this->mapAiDir.'/'.$script, $syntheticRoot.'/'.$script);
    }
    $source = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Installer::stubsPath(), RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($source as $item) {
        $dest = $syntheticRoot.'/stubs/'.$source->getSubPathName();
        $item->isDir() ? mkdir($dest, 0755, true) : copy($item->getPathname(), $dest);
    }
    file_put_contents(
        $syntheticRoot.'/stubs/docs/GLOSSARY.md',
        str_replace(
            '_Claude-maintained — append immediately when a project-specific term is encountered; human reviews for accuracy_',
            '_Human-maintained — add entries when domain-specific language causes confusion_',
            file_get_contents($syntheticRoot.'/stubs/docs/GLOSSARY.md')
        )
    );

    $check = runDoctorSh($this->tempDir, mapAiDir: $syntheticRoot);
    expect($check['output'])->toContain('[FIXABLE]  missing-template-updates docs/GLOSSARY.md');
    expect($check['output'])->not->toContain('[REVIEW]   outdated-scaffold-file  docs/GLOSSARY.md');

    $phpTarget = $this->tempDir.'-php-copy';
    shell_exec('cp -r '.escapeshellarg($this->tempDir).' '.escapeshellarg($phpTarget));
    (new Doctor)->fix($syntheticRoot.'/stubs', $phpTarget);
    $phpPatched = file_get_contents($phpTarget.'/docs/GLOSSARY.md');

    runDoctorSh($this->tempDir, fix: true, mapAiDir: $syntheticRoot);
    $bashPatched = file_get_contents($this->tempDir.'/docs/GLOSSARY.md');

    expect($bashPatched)->toContain('_Human-maintained — add entries when domain-specific language causes confusion_');
    expect($bashPatched)->not->toContain('_Claude-maintained — append immediately');
    expect($bashPatched)->toContain("This project's bug ID convention");
    expect($bashPatched)->toBe($phpPatched);

    shell_exec('rm -rf '.escapeshellarg($syntheticRoot).' '.escapeshellarg($phpTarget));
});

it('--interactive applies a fixable hunk to a file when the developer confirms y', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');

    $syntheticRoot = syntheticMapAiRoot(
        $this->tempDir,
        'docs/GLOSSARY.md',
        ''
    );
    file_put_contents(
        $syntheticRoot.'/stubs/docs/GLOSSARY.md',
        str_replace(
            '_Claude-maintained — append immediately when a project-specific term is encountered; human reviews for accuracy_',
            '_Human-maintained — add entries when domain-specific language causes confusion_',
            file_get_contents($syntheticRoot.'/stubs/docs/GLOSSARY.md')
        )
    );

    $result = runDoctorShInteractive($this->tempDir, "y\n", $syntheticRoot);

    expect($result['output'])->toContain('docs/GLOSSARY.md has new template content');
    expect($result['output'])->toContain('+ _Human-maintained — add entries when domain-specific language causes confusion_');
    expect($result['output'])->toContain('[FIXED]  docs/GLOSSARY.md patched with new template content');
    expect($result['exit'])->toBe(0);

    $patched = file_get_contents($this->tempDir.'/docs/GLOSSARY.md');
    expect($patched)->toContain('_Human-maintained — add entries when domain-specific language causes confusion_');
    expect($patched)->not->toContain('_Claude-maintained — append immediately');

    shell_exec('rm -rf '.escapeshellarg($syntheticRoot));
});

it('--interactive leaves a fixable hunk untouched when the developer declines with n', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');

    $syntheticRoot = syntheticMapAiRoot(
        $this->tempDir,
        'docs/GLOSSARY.md',
        ''
    );
    file_put_contents(
        $syntheticRoot.'/stubs/docs/GLOSSARY.md',
        str_replace(
            '_Claude-maintained — append immediately when a project-specific term is encountered; human reviews for accuracy_',
            '_Human-maintained — add entries when domain-specific language causes confusion_',
            file_get_contents($syntheticRoot.'/stubs/docs/GLOSSARY.md')
        )
    );
    $original = file_get_contents($this->tempDir.'/docs/GLOSSARY.md');

    $result = runDoctorShInteractive($this->tempDir, "n\n", $syntheticRoot);

    expect($result['output'])->toContain('[SKIPPED] docs/GLOSSARY.md left as-is');
    expect($result['output'])->toContain('[FIXABLE]  missing-template-updates docs/GLOSSARY.md');
    expect($result['exit'])->toBe(1);
    expect(file_get_contents($this->tempDir.'/docs/GLOSSARY.md'))->toBe($original);

    shell_exec('rm -rf '.escapeshellarg($syntheticRoot));
});

it('rejects passing both --fix and --interactive', function () {
    $result = (function () {
        $scriptPath = dirname(Installer::stubsPath()).'/doctor.sh';
        $cmd = ['bash', $scriptPath, $this->tempDir, '--fix', '--interactive'];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $stdout.$stderr];
    })();

    expect($result['exit'])->toBe(1);
    expect($result['output'])->toContain('mutually exclusive');
});

it('auto-replaces a reworded HTML comment block, leaving real bug entries alone, matching Doctor.php byte for byte', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');

    $bugsPath = $this->tempDir.'/docs/BUGS.md';
    $reworded = str_replace(
        '<!-- Move here when resolved, then to docs/BUGS_ARCHIVE.md as soon as the fix is verified — do not let this section accumulate -->',
        "<!-- Move here when resolved. Include fix summary and covering test. -->\n<!-- When this section exceeds 20 entries, archive oldest to docs/BUGS_ARCHIVE.md -->",
        file_get_contents($bugsPath)
    );
    $reworded .= "\n### BUG-1 — Something real ✓\n- **Fixed:** 2026-01-01\n- **Fix:** did the thing\n- **Covered by:** SomeTest\n";
    file_put_contents($bugsPath, $reworded);

    $check = runDoctorSh($this->tempDir);
    expect($check['output'])->toContain('[FIXABLE]  missing-template-updates docs/BUGS.md');

    $phpTarget = $this->tempDir.'-php-copy';
    shell_exec('cp -r '.escapeshellarg($this->tempDir).' '.escapeshellarg($phpTarget));
    (new Doctor)->fix($this->mapAiDir.'/stubs', $phpTarget);
    $phpPatched = file_get_contents($phpTarget.'/docs/BUGS.md');

    runDoctorSh($this->tempDir, fix: true);
    $bashPatched = file_get_contents($bugsPath);

    expect($bashPatched)->toContain('<!-- Move here when resolved, then to docs/BUGS_ARCHIVE.md as soon as the fix is verified — do not let this section accumulate -->');
    expect($bashPatched)->not->toContain('When this section exceeds 20 entries');
    expect($bashPatched)->toContain('BUG-1 — Something real ✓');
    expect($bashPatched)->toBe($phpPatched);

    shell_exec('rm -rf '.escapeshellarg($phpTarget));
});

it('auto-replaces a reworded inline trailing comment inside a fenced code block, matching Doctor.php byte for byte', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');

    $setupPath = $this->tempDir.'/docs/SETUP.md';
    $reworded = str_replace(
        'cp docs/memory/framework.example.md docs/memory/[stack].md    # e.g. laravel.md; then update [stack].md ref in docs/MEMORY.md',
        'cp docs/memory/framework.example.md docs/memory/[stack].md    # e.g. laravel.md; then update [stack].md ref in AGENTS.md and docs/MEMORY.md',
        file_get_contents($setupPath)
    );
    file_put_contents($setupPath, $reworded);

    $check = runDoctorSh($this->tempDir);
    expect($check['output'])->toContain('[FIXABLE]  missing-template-updates docs/SETUP.md');

    $phpTarget = $this->tempDir.'-php-copy';
    shell_exec('cp -r '.escapeshellarg($this->tempDir).' '.escapeshellarg($phpTarget));
    (new Doctor)->fix($this->mapAiDir.'/stubs', $phpTarget);
    $phpPatched = file_get_contents($phpTarget.'/docs/SETUP.md');

    runDoctorSh($this->tempDir, fix: true);
    $bashPatched = file_get_contents($setupPath);

    expect($bashPatched)->toContain('cp docs/memory/framework.example.md docs/memory/[stack].md    # e.g. laravel.md; then update [stack].md ref in docs/MEMORY.md');
    expect($bashPatched)->not->toContain('ref in AGENTS.md and docs/MEMORY.md');
    expect($bashPatched)->toBe($phpPatched);

    shell_exec('rm -rf '.escapeshellarg($phpTarget));
});

it('does not auto-replace a trailing # reference outside a fenced code block', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');

    $glossaryPath = $this->tempDir.'/docs/GLOSSARY.md';
    file_put_contents(
        $glossaryPath,
        file_get_contents($glossaryPath)."\nSee tracking discussion #123 for context\n"
    );

    $syntheticRoot = $this->tempDir.'-maproot';
    mkdir($syntheticRoot.'/stubs', 0755, true);
    foreach (['doctor.sh', 'install.sh', 'lib.sh'] as $script) {
        copy($this->mapAiDir.'/'.$script, $syntheticRoot.'/'.$script);
    }
    $source = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Installer::stubsPath(), RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($source as $item) {
        $dest = $syntheticRoot.'/stubs/'.$source->getSubPathName();
        $item->isDir() ? mkdir($dest, 0755, true) : copy($item->getPathname(), $dest);
    }
    file_put_contents(
        $syntheticRoot.'/stubs/docs/GLOSSARY.md',
        str_replace(
            'See tracking discussion #123 for context',
            'See tracking discussion #124 for context',
            file_get_contents($glossaryPath)
        )
    );

    $check = runDoctorSh($this->tempDir, mapAiDir: $syntheticRoot);
    expect($check['output'])->toContain('[REVIEW]   outdated-scaffold-file  docs/GLOSSARY.md');
    expect($check['output'])->not->toContain('[FIXABLE]  missing-template-updates docs/GLOSSARY.md');

    runDoctorSh($this->tempDir, fix: true, mapAiDir: $syntheticRoot);
    expect(file_get_contents($glossaryPath))->toContain('#123');

    shell_exec('rm -rf '.escapeshellarg($syntheticRoot));
});

it('never replaces a filled-in placeholder with the stub still showing [PLACEHOLDER] — AGENTS.md project/stack/date', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');

    $agentsPath = $this->tempDir.'/AGENTS.md';
    file_put_contents(
        $agentsPath,
        str_replace(
            [
                '_Project: [PROJECT NAME] | Stack: [e.g. Laravel 13, PHP 8.5, PostgreSQL 16, Redis]_',
                '_MAP v1.0 | Last updated: [DATE]_',
            ],
            [
                '_Project: archer | Stack: Laravel 13, PHP 8.5, MySQL, Redis_',
                '_MAP v1.0 | Last updated: 2026-06-22_',
            ],
            file_get_contents($agentsPath)
        )
    );

    $check = runDoctorSh($this->tempDir);
    expect($check['output'])->toContain('[REVIEW]   outdated-scaffold-file  AGENTS.md');
    expect($check['output'])->not->toContain('[FIXABLE]  missing-template-updates AGENTS.md');

    runDoctorSh($this->tempDir, fix: true);
    $content = file_get_contents($agentsPath);

    expect($content)->toContain('_Project: archer | Stack: Laravel 13, PHP 8.5, MySQL, Redis_');
    expect($content)->not->toContain('[PROJECT NAME]');
});

it('never replaces a filled-in placeholder with the stub still showing YYYY-MM-DD — docs/STATUS.md, matching Doctor.php', function () {
    shell_exec('bash '.escapeshellarg($this->mapAiDir.'/install.sh').' '.escapeshellarg($this->tempDir).' 2>&1');

    $statusPath = $this->tempDir.'/docs/STATUS.md';
    file_put_contents(
        $statusPath,
        str_replace(
            '_Last updated: YYYY-MM-DD by Claude_',
            '_Last updated: 2026-07-10 by Claude_',
            file_get_contents($statusPath)
        )
    );

    $phpTarget = $this->tempDir.'-php-copy';
    shell_exec('cp -r '.escapeshellarg($this->tempDir).' '.escapeshellarg($phpTarget));
    (new Doctor)->fix($this->mapAiDir.'/stubs', $phpTarget);
    $phpStatus = file_get_contents($phpTarget.'/docs/STATUS.md');

    runDoctorSh($this->tempDir, fix: true);
    $bashStatus = file_get_contents($statusPath);

    expect($bashStatus)->toContain('_Last updated: 2026-07-10 by Claude_');
    expect($bashStatus)->toBe($phpStatus);

    shell_exec('rm -rf '.escapeshellarg($phpTarget));
});
