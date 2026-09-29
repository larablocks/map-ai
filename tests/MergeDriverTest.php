<?php

use larablocks\MapAi\Doctor;
use larablocks\MapAi\Installer;

/**
 * Runs stubs/.map/merge.sh the way git would: base, ours, theirs as files, the
 * result read back from "ours".
 *
 * @return array{exit: int, result: string, stderr: string}
 */
function runMergeDriver(string $base, string $ours, string $theirs, string $path = 'docs/STATUS.md', ?string $cwd = null, array $env = []): array
{
    // Never reach a real `claude` from tests — LLM off unless a test opts in with a fake command.
    $env = [...['PATH' => getenv('PATH'), 'HOME' => getenv('HOME'), 'MAP_MERGE_LLM' => '0'], ...$env];
    $dir = sys_get_temp_dir().'/map-ai-merge-driver-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("$dir/O", $base);
    file_put_contents("$dir/A", $ours);
    file_put_contents("$dir/B", $theirs);

    $cmd = ['bash', Installer::stubsPath().'/.map/merge.sh', "$dir/O", "$dir/A", "$dir/B", $path];
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd ?? $dir, $env);
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
    $git = fn (string $args) => shell_exec('cd '.escapeshellarg($repo).' && MAP_MERGE_LLM=0 git -c user.name=t -c user.email=t@t '.$args.' 2>&1');

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

/**
 * A stand-in for `claude -p`: records the prompt it was given and replies with $reply.
 *
 * @return array{command: string, prompt: string, dir: string}
 */
function fakeClaude(string $reply, int $sleep = 0): array
{
    $dir = sys_get_temp_dir().'/map-ai-fake-claude-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents("$dir/reply", $reply);
    file_put_contents("$dir/fake.sh", "cat > ".escapeshellarg("$dir/prompt")."\n".($sleep ? "sleep $sleep\n" : '')."cat ".escapeshellarg("$dir/reply")."\n");

    return ['command' => 'bash '.escapeshellarg("$dir/fake.sh"), 'prompt' => "$dir/prompt", 'dir' => $dir];
}

function removeDir(string $dir): void
{
    shell_exec('rm -rf '.escapeshellarg($dir));
}

$proseConflict = [
    "# S\n_Last updated: 2026-01-01_\n\n## Current phase\nPhase 1\n\n## Notes\n- a\n",
    "# S\n_Last updated: 2026-01-01_\n\n## Current phase\nPhase 2: API\n\n## Notes\n- a\n",
    "# S\n_Last updated: 2026-01-01_\n\n## Current phase\nPhase 2: billing\n\n## Notes\n- a\n",
];

it('keeps what the rules resolved and leaves markers only around the real conflict', function () {
    $base = "# S\n\n## Current phase\nPhase 1\n\n## Log\n### 2026-01-01\nx\n";
    $ours = "# S\n\n## Current phase\nPhase 2 ours\n\n## Log\n### 2026-01-01\nx\n### 2026-02-01\nours entry\n";
    $theirs = "# S\n\n## Current phase\nPhase 2 theirs\n\n## Log\n### 2026-01-01\nx\n### 2026-02-02\ntheirs entry\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->not->toBe(0);
    expect($merge['result'])->toBe(
        "# S\n\n## Current phase\n<<<<<<< ours\nPhase 2 ours\n||||||| base\nPhase 1\n=======\nPhase 2 theirs\n>>>>>>> theirs\n\n"
        ."## Log\n### 2026-01-01\nx\n### 2026-02-01\nours entry\n### 2026-02-02\ntheirs entry\n"
    );
});

it('applies a valid Claude resolution but still stops the merge for review', function () use ($proseConflict) {
    $fake = fakeClaude("=== RESOLUTION 1 ===\nPhase 2: API and billing\n=== END ===\n");

    $merge = runMergeDriver(...[...$proseConflict, 'docs/STATUS.md', null, ['MAP_MERGE_LLM' => '1', 'MAP_MERGE_LLM_COMMAND' => $fake['command']]]);

    expect($merge['exit'])->not->toBe(0); // stop-for-review: never auto-completes
    expect($merge['result'])->toBe("# S\n_Last updated: 2026-01-01_\n\n## Current phase\nPhase 2: API and billing\n\n## Notes\n- a\n");
    expect($merge['stderr'])->toContain('Claude resolved 1 conflict(s)')->toContain('git add docs/STATUS.md');

    $prompt = file_get_contents($fake['prompt']);
    expect($prompt)
        ->toContain('File: docs/STATUS.md')
        ->toContain('_Last updated: 2026-01-01_')
        ->toContain("OURS (this branch):\nPhase 2: API")
        ->toContain("BASE (common ancestor):\nPhase 1")
        ->toContain("THEIRS (incoming branch):\nPhase 2: billing")
        ->toContain('=== RESOLUTION 1 ===');

    removeDir($fake['dir']);
});

it('rejects a Claude resolution that drops an entry both sides kept', function () {
    $base = "# H\n\n## Log\n| Date | Note |\n|---|---|\n| 2026-01-01 | a |\n";
    $ours = "# H\n\n## Log\n| Date | Note |\n|---|---|\n| 2026-01-01 | a (ours) |\n| 2026-02-01 | b |\n";
    $theirs = "# H\n\n## Log\n| Date | Note |\n|---|---|\n| 2026-01-01 | a (theirs) |\n| 2026-02-02 | c |\n";
    $fake = fakeClaude("=== RESOLUTION 1 ===\n| 2026-01-01 | a (both) |\n| 2026-02-01 | b |\n=== END ===\n");

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/STATUS.md', null, ['MAP_MERGE_LLM' => '1', 'MAP_MERGE_LLM_COMMAND' => $fake['command']]);

    expect($merge['exit'])->not->toBe(0);
    expect($merge['result'])->toContain('<<<<<<< ours')->toContain('| 2026-02-02 | c |');
    expect($merge['stderr'])->toContain('conflict(s) left');

    removeDir($fake['dir']);
});

it('rejects a Claude resolution that invents a heading', function () use ($proseConflict) {
    $fake = fakeClaude("=== RESOLUTION 1 ===\nPhase 2\n### Made-up section\n=== END ===\n");

    $merge = runMergeDriver(...[...$proseConflict, 'docs/STATUS.md', null, ['MAP_MERGE_LLM' => '1', 'MAP_MERGE_LLM_COMMAND' => $fake['command']]]);

    expect($merge['result'])->toContain('<<<<<<< ours')->not->toContain('Made-up section');

    removeDir($fake['dir']);
});

it('leaves markers when Claude marks a conflict unresolved or replies with markers', function () use ($proseConflict) {
    foreach (["=== UNRESOLVED 1 ===\n=== END ===\n", "=== RESOLUTION 1 ===\n<<<<<<< ours\nx\n=== END ===\n", "I could not do that.\n"] as $reply) {
        $fake = fakeClaude($reply);

        $merge = runMergeDriver(...[...$proseConflict, 'docs/STATUS.md', null, ['MAP_MERGE_LLM' => '1', 'MAP_MERGE_LLM_COMMAND' => $fake['command']]]);

        expect($merge['exit'])->not->toBe(0);
        expect($merge['result'])->toContain("<<<<<<< ours\nPhase 2: API\n||||||| base\nPhase 1\n=======\nPhase 2: billing\n>>>>>>> theirs");

        removeDir($fake['dir']);
    }
});

it('resolves some conflicts with Claude and leaves the rest', function () {
    $base = "# S\n\n## A\none\n\n## B\ntwo\n";
    $ours = "# S\n\n## A\none ours\n\n## B\ntwo ours\n";
    $theirs = "# S\n\n## A\none theirs\n\n## B\ntwo theirs\n";
    $fake = fakeClaude("=== RESOLUTION 1 ===\none merged\n=== UNRESOLVED 2 ===\n=== END ===\n");

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/STATUS.md', null, ['MAP_MERGE_LLM' => '1', 'MAP_MERGE_LLM_COMMAND' => $fake['command']]);

    expect($merge['result'])->toContain("## A\none merged\n")->toContain("<<<<<<< ours\ntwo ours\n");
    expect($merge['stderr'])->toContain('resolved 1 of 2 conflict(s)');

    removeDir($fake['dir']);
});

it('never calls Claude when disabled, and gives up on a slow reply', function () use ($proseConflict) {
    $fake = fakeClaude("=== RESOLUTION 1 ===\nPhase 2\n=== END ===\n", sleep: 5);

    runMergeDriver(...[...$proseConflict, 'docs/STATUS.md', null, ['MAP_MERGE_LLM' => '0', 'MAP_MERGE_LLM_COMMAND' => $fake['command']]]);
    expect(file_exists($fake['prompt']))->toBeFalse();

    $started = microtime(true);
    $merge = runMergeDriver(...[...$proseConflict, 'docs/STATUS.md', null, ['MAP_MERGE_LLM' => '1', 'MAP_MERGE_LLM_COMMAND' => $fake['command'], 'MAP_MERGE_LLM_TIMEOUT' => '1']]);
    expect(microtime(true) - $started)->toBeLessThan(4);
    expect($merge['result'])->toContain('<<<<<<< ours');
    expect($merge['stderr'])->toContain('failed or timed out');

    removeDir($fake['dir']);
});

it('stops a real git merge for review when Claude resolved the conflict — no merge commit', function () {
    $repo = sys_get_temp_dir().'/map-ai-merge-llm-repo-'.uniqid();
    mkdir($repo, 0755, true);
    $fake = fakeClaude("=== RESOLUTION 1 ===\nPhase 2: API and billing\n=== END ===\n");
    $env = 'MAP_MERGE_LLM=1 MAP_MERGE_LLM_COMMAND='.escapeshellarg($fake['command']);
    $git = fn (string $args) => shell_exec('cd '.escapeshellarg($repo).' && '.$env.' git -c user.name=t -c user.email=t@t '.$args.' 2>&1');

    $git('init -q -b main');
    (new Installer)->install(Installer::stubsPath(), $repo);
    $status = $repo.'/docs/STATUS.md';
    file_put_contents($status, "# STATUS.md\n\n## Current phase\nPhase 1\n");
    $git('add -A');
    $git('commit -qm install');

    $git('checkout -qb feature');
    file_put_contents($status, "# STATUS.md\n\n## Current phase\nPhase 2: billing\n");
    $git('commit -qam theirs');
    $git('checkout -q main');
    file_put_contents($status, "# STATUS.md\n\n## Current phase\nPhase 2: API\n");
    $git('commit -qam ours');
    $head = trim((string) $git('rev-parse HEAD'));

    $output = (string) $git('merge --no-edit feature');

    expect($output)->toContain('Claude resolved 1 conflict(s)');
    expect(file_get_contents($status))->toBe("# STATUS.md\n\n## Current phase\nPhase 2: API and billing\n");
    expect(trim((string) $git('ls-files -u docs/STATUS.md')))->not->toBe(''); // still unmerged — awaiting git add
    expect(trim((string) $git('rev-parse HEAD')))->toBe($head);            // no merge commit made

    removeDir($repo);
    removeDir($fake['dir']);
});

it('--resolve re-runs the rules on a file git left conflicted, without staging it', function () {
    $repo = sys_get_temp_dir().'/map-ai-merge-resolve-'.uniqid();
    mkdir($repo, 0755, true);
    $git = fn (string $args) => shell_exec('cd '.escapeshellarg($repo).' && git -c user.name=t -c user.email=t@t '.$args.' 2>&1');

    // Plain git, no driver registered: the append-at-end conflict lands as markers.
    $git('init -q -b main');
    mkdir($repo.'/docs');
    mkdir($repo.'/.map');
    copy(Installer::stubsPath().'/.map/merge.sh', $repo.'/.map/merge.sh');
    $history = $repo.'/docs/ARCHITECTURE_HISTORY.md';
    file_put_contents($history, "# AH\n\n### 2026-01-01 — One\nx\n");
    $git('add -A');
    $git('commit -qm base');
    $git('checkout -qb feature');
    file_put_contents($history, "# AH\n\n### 2026-01-01 — One\nx\n\n### 2026-02-02 — Theirs\ny\n");
    $git('commit -qam theirs');
    $git('checkout -q main');
    file_put_contents($history, "# AH\n\n### 2026-01-01 — One\nx\n\n### 2026-02-01 — Ours\nz\n");
    $git('commit -qam ours');
    $git('merge --no-edit feature');
    expect(file_get_contents($history))->toContain('<<<<<<<');

    $process = proc_open(
        ['bash', '.map/merge.sh', '--resolve', 'docs/ARCHITECTURE_HISTORY.md'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repo,
        ['PATH' => getenv('PATH'), 'HOME' => getenv('HOME'), 'MAP_MERGE_LLM' => '0'],
    );
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0);
    expect(file_get_contents($history))->toBe("# AH\n\n### 2026-01-01 — One\nx\n\n### 2026-02-01 — Ours\nz\n\n### 2026-02-02 — Theirs\ny\n");
    expect(trim((string) $git('ls-files -u docs/ARCHITECTURE_HISTORY.md')))->not->toBe(''); // never git-adds

    removeDir($repo);
});

it('merges a list both sides edited — removals stay removed, additions kept, renumbered', function () {
    $base = "# S\n\n## What's next\n1. Webhooks\n2. Refunds\n3. Dunning\n";
    $ours = "# S\n\n## What's next\n1. Refunds\n2. Dunning\n3. Retries\n";
    $theirs = "# S\n\n## What's next\n1. Webhooks\n2. Dunning\n3. Partials\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe("# S\n\n## What's next\n1. Dunning\n2. Retries\n3. Partials\n");
});

it('merges bullet lists without numbering them', function () {
    $base = "# S\n\n## Notes\n- a\n- b\n";
    $ours = "# S\n\n## Notes\n- a\n- ours\n";
    $theirs = "# S\n\n## Notes\n- b\n- theirs\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe("# S\n\n## Notes\n- ours\n- theirs\n");
});

it('leaves a conflict when both sides reorder the same list differently', function () {
    $base = "# S\n\n## What's next\n1. A\n2. B\n3. C\n";
    $ours = "# S\n\n## What's next\n1. C\n2. A\n3. B\n4. D\n";
    $theirs = "# S\n\n## What's next\n1. B\n2. A\n3. C\n4. E\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->not->toBe(0);
    expect($merge['result'])->toContain('<<<<<<< ours');
});

$snapshot = '<!-- map-merge: snapshot -->';

it('takes the newer side for a table row both sides changed in a snapshot block', function () use ($snapshot) {
    $table = "| Indicator | Status |\n|---|---|\n";
    $base = "# S\n_Last updated: 2026-09-01_\n\n## Project health\n$snapshot\n$table| Tests | 120 |\n| Coverage | 81% |\n| Build | ok |\n";
    $ours = "# S\n_Last updated: 2026-09-10_\n\n## Project health\n$snapshot\n$table| Tests | 131 |\n| Coverage | 83% |\n| Build | ok |\n";
    $theirs = "# S\n_Last updated: 2026-09-12_\n\n## Project health\n$snapshot\n$table| Tests | 128 |\n| Coverage | 81% |\n| Build | broken |\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->toBe(0);
    // Tests: both changed → newer (theirs); Coverage/Build: one side changed → that side.
    expect($merge['result'])->toBe("# S\n_Last updated: 2026-09-12_\n\n## Project health\n$snapshot\n$table| Tests | 128 |\n| Coverage | 83% |\n| Build | broken |\n");
    expect($merge['stderr'])->toContain('1 snapshot value(s) changed on both branches')->toContain('re-verify');
});

it('still leaves a conflict for the same row change outside a snapshot block', function () use ($snapshot) {
    $base = "# S\n_Last updated: 2026-09-01_\n\n## Health\n| K | V |\n|---|---|\n| Tests | 120 |\n\n## Metrics\n$snapshot\nx\n";
    $ours = "# S\n_Last updated: 2026-09-10_\n\n## Health\n| K | V |\n|---|---|\n| Tests | 131 |\n\n## Metrics\n$snapshot\nx\n";
    $theirs = "# S\n_Last updated: 2026-09-12_\n\n## Health\n| K | V |\n|---|---|\n| Tests | 128 |\n\n## Metrics\n$snapshot\nx\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->not->toBe(0);
    expect($merge['result'])->toContain('<<<<<<< ours');
});

it('takes the newer side for prose in a snapshot block, by the block\'s own date first', function () use ($snapshot) {
    $base = "# C\n\n## Backend\n$snapshot\n**Suite:** 120 tests.\n**Coverage:** 81% (measured 2026-09-01).\n";
    $ours = "# C\n\n## Backend\n$snapshot\n**Suite:** 131 tests.\n**Coverage:** 83% (measured 2026-09-20).\n";
    $theirs = "# C\n\n## Backend\n$snapshot\n**Suite:** 128 tests.\n**Coverage:** 82% (measured 2026-09-12).\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/TESTING_COVERAGE.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe($ours);
});

it('treats a marker under the title as covering every section of the file', function () use ($snapshot) {
    $head = "# C\n\n$snapshot\n\n## Area 1\n| File | % |\n|---|---|\n";
    $base = "{$head}| a.php | 80% |\n| b.php | 0% |\n\n## Runs\n| Date | % |\n|---|---|\n| 2026-09-01 | 81% |\n";
    $ours = "{$head}| a.php | 85% |\n| b.php | 92% |\n\n## Runs\n| Date | % |\n|---|---|\n| 2026-09-01 | 81% |\n| 2026-09-10 | 83% |\n";
    $theirs = "{$head}| a.php | 100% |\n| b.php | 0% |\n\n## Runs\n| Date | % |\n|---|---|\n| 2026-09-01 | 81% |\n| 2026-09-12 | 82% |\n";

    $merge = runMergeDriver($base, $ours, $theirs, 'docs/TESTING_COVERAGE.md');

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe("{$head}| a.php | 100% |\n| b.php | 92% |\n\n## Runs\n| Date | % |\n|---|---|\n| 2026-09-01 | 81% |\n| 2026-09-10 | 83% |\n| 2026-09-12 | 82% |\n");
});

it('keeps a snapshot marker the older side added while the newer side changed the values', function () use ($snapshot) {
    $base = "# S\n_Last updated: 2026-09-01_\n\n## Health\nTests: 120\n";
    $ours = "# S\n_Last updated: 2026-09-01_\n\n## Health\n$snapshot\nTests: 120\n"; // e.g. doctor --fix on main
    $theirs = "# S\n_Last updated: 2026-09-12_\n\n## Health\nTests: 128\n";

    $merge = runMergeDriver($base, $ours, $theirs);

    expect($merge['exit'])->toBe(0);
    expect($merge['result'])->toBe("# S\n_Last updated: 2026-09-12_\n\n## Health\n$snapshot\nTests: 128\n");
});

it('ships snapshot markers on the re-measured sections of STATUS.md and TESTING_COVERAGE.md', function () {
    $status = (string) file_get_contents(Installer::stubsPath().'/docs/STATUS.md');
    $coverage = (string) file_get_contents(Installer::stubsPath().'/docs/TESTING_COVERAGE.md');

    expect($status)->toMatch('/## Project health\n<!-- map-merge: snapshot/')
        ->toMatch('/## Metrics snapshot\n<!-- map-merge: snapshot/');
    expect(strstr($coverage, "\n## ", true))->toContain('<!-- map-merge: snapshot');
});
