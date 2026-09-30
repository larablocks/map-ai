<?php

use larablocks\MapAi\Doctor;
use larablocks\MapAi\Installer;

/**
 * Runs stubs/.claude/hooks/map-token-check.sh the way Claude Code would —
 * CLAUDE_PROJECT_DIR set to the project, the hook's JSON payload on stdin.
 *
 * @return array{exit: int, output: string}
 */
function runTokenHook(string $projectDir, string $stdin = ''): array
{
    $cmd = ['bash', Installer::stubsPath().'/.claude/hooks/map-token-check.sh'];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = ['CLAUDE_PROJECT_DIR' => $projectDir, 'PATH' => getenv('PATH')];
    $process = proc_open($cmd, $descriptors, $pipes, null, $env);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return ['exit' => $exit, 'output' => $stdout.$stderr];
}

function postToolUsePayload(string $filePath): string
{
    return json_encode([
        'hook_event_name' => 'PostToolUse',
        'tool_name' => 'Edit',
        'tool_input' => ['file_path' => $filePath],
    ]);
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/map-ai-token-hook-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->overCap = str_repeat(str_repeat('x', 2000)."\n", 10);
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

it('stays silent at session start when AGENTS.md is within the cap', function () {
    copy(Installer::stubsPath().'/AGENTS.md', $this->tempDir.'/AGENTS.md');

    $result = runTokenHook($this->tempDir);

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toBe('');
});

it('injects session start context when AGENTS.md is over the cap', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', $this->overCap);

    $result = runTokenHook($this->tempDir, '{"hook_event_name":"SessionStart"}');
    $json = json_decode($result['output'], true);

    expect($result['exit'])->toBe(0);
    expect($json['hookSpecificOutput']['hookEventName'])->toBe('SessionStart');
    expect($json['hookSpecificOutput']['additionalContext'])
        ->toContain('~5003 tokens')
        ->toContain((string) Doctor::AGENTS_MD_MAX_TOKENS);
});

it('blocks with a reason after an edit pushes AGENTS.md over the cap', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', $this->overCap);

    $result = runTokenHook($this->tempDir, postToolUsePayload($this->tempDir.'/AGENTS.md'));
    $json = json_decode($result['output'], true);

    expect($result['exit'])->toBe(0);
    expect($json['decision'])->toBe('block');
    expect($json['reason'])->toContain('~5003 tokens');
});

it('ignores edits to files other than AGENTS.md', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', $this->overCap);

    $result = runTokenHook($this->tempDir, postToolUsePayload($this->tempDir.'/docs/NOT_AGENTS.md'));

    expect($result['output'])->toBe('');
});

it('stays silent in the MAP template repo itself', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', $this->overCap);
    mkdir($this->tempDir.'/.claude/rules', 0755, true);
    touch($this->tempDir.'/.claude/rules/meta.md');

    expect(runTokenHook($this->tempDir)['output'])->toBe('');
});

it('reports memory files over their caps at session start', function () {
    mkdir($this->tempDir.'/docs/memory', 0755, true);
    file_put_contents($this->tempDir.'/docs/memory/gotchas.md', str_repeat('x', 751 * 4));
    file_put_contents($this->tempDir.'/docs/memory/shared.md', str_repeat('x', 1400 * 4));
    file_put_contents($this->tempDir.'/docs/memory/laravel.md', str_repeat('x', 2501 * 4));

    $context = json_decode(runTokenHook($this->tempDir)['output'], true)['hookSpecificOutput']['additionalContext'];

    expect($context)
        ->toContain('docs/memory/gotchas.md is ~751 tokens (cap 750)')
        ->toContain('docs/memory/laravel.md is ~2501 tokens (cap 2500)')
        ->toContain('Memory files: trim them')
        ->not->toContain('shared.md')
        ->not->toContain('AGENTS.md: propose');
});

it('blocks after an edit pushes a memory file over its cap', function () {
    mkdir($this->tempDir.'/docs/memory', 0755, true);
    file_put_contents($this->tempDir.'/docs/memory/shared.md', str_repeat('x', 1501 * 4));

    $json = json_decode(runTokenHook($this->tempDir, postToolUsePayload($this->tempDir.'/docs/memory/shared.md'))['output'], true);

    expect($json['decision'])->toBe('block');
    expect($json['reason'])->toContain('docs/memory/shared.md is ~1501 tokens (cap 1500)');
});

it('tells Claude to archive old progress entries when STATUS.md is over its cap', function () {
    mkdir($this->tempDir.'/docs', 0755, true);
    file_put_contents($this->tempDir.'/docs/STATUS.md', str_repeat('x', 5001 * 4));
    file_put_contents($this->tempDir.'/docs/STATUS_ARCHIVE.md', str_repeat('x', 50000 * 4));

    $context = json_decode(runTokenHook($this->tempDir)['output'], true)['hookSpecificOutput']['additionalContext'];

    expect($context)
        ->toContain('docs/STATUS.md is ~5001 tokens (cap 5000)')
        ->toContain('to docs/STATUS_ARCHIVE.md verbatim')
        ->not->toContain('STATUS_ARCHIVE.md is')
        ->not->toContain('Memory files: trim them');
});

it('never caps memory example files or the append-only logs', function () {
    mkdir($this->tempDir.'/docs/memory', 0755, true);
    file_put_contents($this->tempDir.'/docs/memory/gotchas.example.md', $this->overCap);
    file_put_contents($this->tempDir.'/docs/ARCHITECTURE_HISTORY.md', $this->overCap);

    expect(runTokenHook($this->tempDir)['output'])->toBe('');
    expect(runTokenHook($this->tempDir, postToolUsePayload($this->tempDir.'/docs/ARCHITECTURE_HISTORY.md'))['output'])->toBe('');
});

it('uses the same cap as Doctor', function () {
    $script = file_get_contents(Installer::stubsPath().'/.claude/hooks/map-token-check.sh');

    expect($script)->toContain('AGENTS_MD_MAX_TOKENS='.Doctor::AGENTS_MD_MAX_TOKENS."\n");
    expect(file_get_contents(dirname(Installer::stubsPath()).'/doctor.sh'))
        ->toContain('AGENTS_MD_MAX_TOKENS='.Doctor::AGENTS_MD_MAX_TOKENS."\n");
});
