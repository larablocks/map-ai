<?php

use larablocks\MapAi\Doctor;
use larablocks\MapAi\Installer;

/**
 * Runs stubs/.map/merge.sh the way git would: base, ours, theirs as files, the
 * result read back from "ours".
 *
 * @return array{exit: int, result: string, stderr: string}
 */
function runMergeDriver(string $base, string $ours, string $theirs, string $path = 'docs/STATUS.md', ?string $cwd = null): array
{
    $dir = sys_get_temp_dir().'/map-ai-merge-driver-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("$dir/O", $base);
    file_put_contents("$dir/A", $ours);
    file_put_contents("$dir/B", $theirs);

    $cmd = ['bash', Installer::stubsPath().'/.map/merge.sh', "$dir/O", "$dir/A", "$dir/B", $path];
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? $dir);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $result = (string) file_get_contents("$dir/A");
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);

    return ['exit' => $exit, 'result' => $result, 'stderr' => (string) $stderr];
}

it('passes a clean merge through exactly as git would', function () {
    $base = "# S\n\n## A\none\n\n## B\ntwo\n";
    $ours = "# S\n\n## A\none (ours)\n\n## B\ntwo\n";
    $theirs = "# S\n\n## A\none\n\n## B\ntwo (theirs)\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe("# S\n\n## A\none (ours)\n\n## B\ntwo (theirs)\n");
});

it('keeps both entries when both branches append to a log — ours first', function () {
    $base = "# AH\n_x_\n\n### 2026-01-01 — One\nbody1\n\n";
    $ours = $base."### 2026-02-01 — Ours\nbodyA\n\n";
    $theirs = $base."### 2026-02-02 — Theirs\nbodyB\n\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/ARCHITECTURE_HISTORY.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe($base."### 2026-02-01 — Ours\nbodyA\n\n### 2026-02-02 — Theirs\nbodyB\n\n");
});

it('keeps a bug deleted when one side moved it out and the other left it alone', function () {
    $base = "# BUGS\n\n## Open bugs\n\n### BUG-1 — old\n- x\n\n### BUG-2 — kept\n- y\n\n## Fixed bugs\n";
    $ours = "# BUGS\n\n## Open bugs\n\n### BUG-2 — kept\n- y\n\n## Fixed bugs\n";
    $theirs = "# BUGS\n\n## Open bugs\n\n### BUG-1 — old\n- x\n\n### BUG-2 — kept\n- y\n\n### BUG-3 — new\n- z\n\n## Fixed bugs\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/BUGS.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])
        ->not->toContain('BUG-1')
        ->toContain('### BUG-2 — kept')
        ->toContain('### BUG-3 — new');
});

it('renumbers the incoming duplicate BUG-N and keeps ours', function () {
    $base = "# BUGS\n\n## Open bugs\n\n### BUG-1 — old\n- x\n\n## Fixed bugs\n";
    $ours = "# BUGS\n\n## Open bugs\n\n### BUG-1 — old\n- x\n\n### BUG-2 — ours\n- a\n\n## Fixed bugs\n";
    $theirs = "# BUGS\n\n## Open bugs\n\n### BUG-1 — old\n- x\n\n### BUG-2 — theirs\n- b\n\n## Fixed bugs\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/BUGS.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])
        ->toContain('### BUG-2 — ours')
        ->toContain('### BUG-3 — theirs')
        ->not->toContain('### BUG-2 — theirs');
    expect($merge['stderr'])->toContain('renumbered one to BUG-3');
});

it('picks the next free BUG-N across both bug files', function () {
    $dir = sys_get_temp_dir().'/map-ai-merge-cwd-'.uniqid();
    mkdir("$dir/docs", 0755, true);
    file_put_contents("$dir/docs/BUGS_ARCHIVE.md", "# BUGS_ARCHIVE.md\n\n### BUG-9 — archived ✓\n");

    $base = "# BUGS\n\n## Open bugs\n\n## Fixed bugs\n";
    $ours = "# BUGS\n\n## Open bugs\n\n### BUG-10 — ours\n- a\n\n## Fixed bugs\n";
    $theirs = "# BUGS\n\n## Open bugs\n\n### BUG-10 — theirs\n- b\n\n## Fixed bugs\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/BUGS.md', $dir);

    expect($merge['result'])->toContain('### BUG-10 — ours')->toContain('### BUG-11 — theirs');

    unlink("$dir/docs/BUGS_ARCHIVE.md");
    rmdir("$dir/docs");
    rmdir($dir);
});

it('ignores BUG-N headings inside HTML comments', function () {
    $template = "<!-- Format:\n### BUG-[N] — [Short title]\n### BUG-1 — example\n-->\n";
    $base = "# BUGS\n\n## Open bugs\n$template\n## Fixed bugs\n";
    $ours = "# BUGS\n\n## Open bugs\n$template\n### BUG-1 — real\n- a\n\n## Fixed bugs\n";
    $theirs = "# BUGS\n\n## Open bugs\n$template\n### BUG-2 — other\n- b\n\n## Fixed bugs\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/BUGS.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toContain("### BUG-1 — example\n-->")->toContain('### BUG-1 — real')->toContain('### BUG-2 — other');
    expect($merge['stderr'])->not->toContain('renumbered');
});

it('merges table rows by key and keeps the newest Last updated date', function () {
    $base = "# FF\n_Last updated: 2026-01-01_\n\n## Active flags\n| Flag | Purpose |\n|---|---|\n| a | x |\n";
    $ours = "# FF\n_Last updated: 2026-03-01_\n\n## Active flags\n| Flag | Purpose |\n|---|---|\n| a | x |\n| ours | y |\n";
    $theirs = "# FF\n_Last updated: 2026-02-01_\n\n## Active flags\n| Flag | Purpose |\n|---|---|\n| a | changed |\n| theirs | z |\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/FEATURE_FLAGS.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe("# FF\n_Last updated: 2026-03-01_\n\n## Active flags\n| Flag | Purpose |\n|---|---|\n| a | changed |\n| ours | y |\n| theirs | z |\n");
});

it('leaves a conflict when both sides change the same table row differently', function () {
    $base = "# FF\n\n## Active flags\n| Flag | Purpose |\n|---|---|\n| a | x |\n";
    $ours = "# FF\n\n## Active flags\n| Flag | Purpose |\n|---|---|\n| a | ours |\n";
    $theirs = "# FF\n\n## Active flags\n| Flag | Purpose |\n|---|---|\n| a | theirs |\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/FEATURE_FLAGS.md');

    expect($merge['exit'])->not->toBe(0);
    expect($merge['result'])->toContain('<<<<<<< ours')->toContain('>>>>>>> theirs');
});

it('keeps both one-line entries appended to shared.md', function () {
    $base = "# memory/shared.md\n_x_\n\n2026-01-01 — first — do x\n";
    $ours = $base."2026-02-01 — ours — do y\n";
    $theirs = $base."2026-02-02 — theirs — do z\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/memory/shared.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe($base."2026-02-01 — ours — do y\n2026-02-02 — theirs — do z\n");
});

it('keeps both entries whole when both branches add the same heading', function () {
    $base = "# METRICS\n\n## Entry format\n";
    $ours = $base."\n### 2026-09-21\n| Metric | Value |\n|---|---|\n| Tests | 10 |\n";
    $theirs = $base."\n### 2026-09-21\n| Metric | Value |\n|---|---|\n| Tests | 12 |\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/METRICS_HISTORY.md');

    expect($merge['exit'])->toBe(0);
    expect(substr_count($merge['result'], '### 2026-09-21'))->toBe(2);
    expect($merge['result'])->toContain("| Tests | 10 |\n### 2026-09-21");
});

it('does not treat a # line inside a code fence as a heading', function () {
    $base = "# T\n\n## How\n```bash\n# run\nx\n```\nend\n";
    $ours = "# T\n\n## How\n```bash\n# run\nx\n```\nend\nours line\n";
    $theirs = "# T\n\n## How\n```bash\n# run\nx\n```\nend\ntheirs line\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/TESTING_COVERAGE.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe("# T\n\n## How\n```bash\n# run\nx\n```\nend\nours line\ntheirs line\n");
});

it('leaves git\'s conflict markers for the same prose rewritten on both sides', function () {
    $base = "# S\n\n## Current phase\nPhase 1\n";
    $ours = "# S\n\n## Current phase\nPhase 2 ours\n";
    $theirs = "# S\n\n## Current phase\nPhase 2 theirs\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->not->toBe(0);
    expect($merge['result'])->toBe("# S\n\n## Current phase\n<<<<<<< ours\nPhase 2 ours\n||||||| base\nPhase 1\n=======\nPhase 2 theirs\n>>>>>>> theirs\n");
});

it('leaves a conflict when an entry is edited on one side and deleted on the other', function () {
    $base = "# B\n\n## Open bugs\n\n### BUG-1 — a\n- x\n\n### BUG-2 — b\n- y\n";
    $ours = "# B\n\n## Open bugs\n\n### BUG-2 — b\n- y\n- ours edit\n";
    $theirs = "# B\n\n## Open bugs\n\n### BUG-1 — a\n- x\n- theirs edit\n\n### BUG-2 — b\n- y\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/BUGS.md');

    expect($merge['exit'])->not->toBe(0);
    expect($merge['result'])->toContain('<<<<<<< ours')->toContain('- theirs edit');
});

it('resolves a real git merge end to end once installed', function () {
    $repo = sys_get_temp_dir().'/map-ai-merge-repo-'.uniqid();
    mkdir($repo, 0755, true);
    $git = fn (string $args) => shell_exec('cd '.escapeshellarg($repo).' && git -c user.name=t -c user.email=t@t '.$args.' 2>&1');

    $git('init -q -b main');
    (new Installer)->install(Installer::stubsPath(), $repo);
    $git('add -A');
    $git('commit -qm install');

    $history = $repo.'/docs/ARCHITECTURE_HISTORY.md';
    $original = file_get_contents($history);

    $git('checkout -qb feature');
    file_put_contents($history, $original."\n### 2026-09-02 — Theirs decision\n**Decision:** b\n");
    $git('commit -qam theirs');

    $git('checkout -q main');
    file_put_contents($history, $original."\n### 2026-09-01 — Ours decision\n**Decision:** a\n");
    $git('commit -qam ours');

    $output = (string) $git('merge -q --no-edit feature');
    $merged = file_get_contents($history);

    expect($output)->not->toContain('CONFLICT');
    expect($merged)
        ->toContain('### 2026-09-01 — Ours decision')
        ->toContain('### 2026-09-02 — Theirs decision')
        ->not->toContain('<<<<<<<');
    expect(strpos($merged, 'Ours decision'))->toBeLessThan(strpos($merged, 'Theirs decision'));

    shell_exec('rm -rf '.escapeshellarg($repo));
});

it('reports an unregistered merge driver as fixable, and fix() registers it', function () {
    $repo = sys_get_temp_dir().'/map-ai-merge-doctor-'.uniqid();
    mkdir($repo, 0755, true);
    shell_exec('git -C '.escapeshellarg($repo).' init -q 2>&1');
    (new Installer)->install(Installer::stubsPath(), $repo);
    shell_exec('git -C '.escapeshellarg($repo).' config --unset merge.map-ai.driver');

    $doctor = new Doctor;
    $findings = array_values(array_filter(
        $doctor->check(Installer::stubsPath(), $repo),
        fn (array $f) => $f['id'] === 'merge-driver-not-registered',
    ));
    expect($findings)->toHaveCount(1);
    expect($findings[0]['fixable'])->toBeTrue();

    $doctor->fix(Installer::stubsPath(), $repo);
    expect(Installer::mergeDriverRegistered($repo))->toBeTrue();

    shell_exec('rm -rf '.escapeshellarg($repo));
});

it('doctor.sh reports an unregistered merge driver, and --fix registers it', function () {
    $repo = sys_get_temp_dir().'/map-ai-merge-doctorsh-'.uniqid();
    mkdir($repo, 0755, true);
    $mapAiDir = dirname(Installer::stubsPath());
    shell_exec('git -C '.escapeshellarg($repo).' init -q 2>&1');
    shell_exec('bash '.escapeshellarg($mapAiDir.'/install.sh').' '.escapeshellarg($repo).' 2>&1');
    expect(Installer::mergeDriverRegistered($repo))->toBeTrue();

    shell_exec('git -C '.escapeshellarg($repo).' config --unset merge.map-ai.driver');
    expect((string) shell_exec('bash '.escapeshellarg($mapAiDir.'/doctor.sh').' '.escapeshellarg($repo).' 2>&1'))
        ->toContain('[FIXABLE]  merge-driver-not-registered');

    shell_exec('bash '.escapeshellarg($mapAiDir.'/doctor.sh').' '.escapeshellarg($repo).' --fix 2>&1');
    expect(Installer::mergeDriverRegistered($repo))->toBeTrue();

    shell_exec('rm -rf '.escapeshellarg($repo));
});

it('SessionStart hook registers the merge driver in a fresh clone', function () {
    $repo = sys_get_temp_dir().'/map-ai-merge-hook-'.uniqid();
    mkdir($repo, 0755, true);
    shell_exec('git -C '.escapeshellarg($repo).' init -q 2>&1');
    (new Installer)->install(Installer::stubsPath(), $repo);
    shell_exec('git -C '.escapeshellarg($repo).' config --unset merge.map-ai.driver');

    $process = proc_open(
        ['bash', Installer::stubsPath().'/.claude/hooks/map-first-run-check.sh'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['CLAUDE_PROJECT_DIR' => $repo, 'PATH' => getenv('PATH'), 'HOME' => getenv('HOME')],
    );
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect(Installer::mergeDriverRegistered($repo))->toBeTrue();

    shell_exec('rm -rf '.escapeshellarg($repo));
});
