<?php

use larablocks\MapAi\Installer;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/map-ai-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->stubsPath = Installer::stubsPath();
    $this->installer = new Installer;
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

it('resolves a valid stubs path', function () {
    expect(Installer::stubsPath())->toBeDirectory();
});

it('stubs path contains all expected files', function () {
    foreach (Installer::FILES as $file) {
        expect(Installer::stubsPath().'/'.$file)->toBeFile();
    }
});

it('copies all files to the target directory', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);

    expect($this->tempDir.'/AGENTS.md')->toBeFile();
    expect($this->tempDir.'/CLAUDE.md')->toBeFile();
    expect($this->tempDir.'/GEMINI.md')->toBeFile();
    expect($this->tempDir.'/docs/STATUS.md')->toBeFile();
    expect($this->tempDir.'/docs/BUGS.md')->toBeFile();
    expect($this->tempDir.'/.claude/rules/security.md')->toBeFile();
    expect($this->tempDir.'/.claude/rules/testing.md')->toBeFile();
    expect($this->tempDir.'/.github/copilot-instructions.md')->toBeFile();
    expect($this->tempDir.'/docs/memory/gotchas.example.md')->toBeFile();
});

it('reports copy actions for new files', function () {
    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $actions = array_column($result['files'], 'action');
    expect($actions)->not->toContain('skip');
    expect($actions)->not->toContain('missing');
    expect($actions)->toContain('copy');
});

it('skips existing files without force', function () {
    $agentsPath = $this->tempDir.'/AGENTS.md';
    file_put_contents($agentsPath, 'custom content');

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $skipped = array_filter($result['files'], fn ($f) => $f['file'] === 'AGENTS.md');
    expect(array_values($skipped)[0]['action'])->toBe('skip');
    expect(file_get_contents($agentsPath))->toBe('custom content');
});

it('overwrites existing files with force', function () {
    $agentsPath = $this->tempDir.'/AGENTS.md';
    file_put_contents($agentsPath, 'custom content');

    $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    expect(file_get_contents($agentsPath))->not->toBe('custom content');
});

it('reports update action when overwriting with force', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'old');

    $result = $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    $updated = array_filter($result['files'], fn ($f) => $f['file'] === 'AGENTS.md');
    expect(array_values($updated)[0]['action'])->toBe('update');
});

it('creates nested directories', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);

    expect($this->tempDir.'/docs/memory')->toBeDirectory();
    expect($this->tempDir.'/.claude/rules')->toBeDirectory();
    expect($this->tempDir.'/.github')->toBeDirectory();
});

it('appends map entries to an empty gitignore', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');

    expect($gitignore)
        ->toContain('HANDOFF.md')
        ->toContain('.claude/settings.local.json')
        ->toContain('CLAUDE.local.md')
        ->toContain('docs/MEMORY.md')
        ->toContain('docs/memory/*.md')
        ->toContain('!docs/memory/*.example.md')
        ->toContain('!docs/memory/shared.md');
});

it('appends map entries after existing gitignore content', function () {
    $existing = "node_modules\n.env\n";
    file_put_contents($this->tempDir.'/.gitignore', $existing);

    $this->installer->install($this->stubsPath, $this->tempDir);

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');

    expect($gitignore)
        ->toStartWith($existing)
        ->toContain('HANDOFF.md');
});

it('does not duplicate gitignore entries on re-install', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);
    $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');
    expect(substr_count($gitignore, 'HANDOFF.md'))->toBe(1);
});

it('returns updated for a new gitignore', function () {
    $result = $this->installer->install($this->stubsPath, $this->tempDir);
    expect($result['gitignore'])->toBe('updated');
});

it('returns skipped when gitignore entries already present', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);
    $result = $this->installer->install($this->stubsPath, $this->tempDir, force: true);
    expect($result['gitignore'])->toBe('skipped');
});

it('does not set backed_up for new copies', function () {
    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $copied = array_filter($result['files'], fn ($f) => $f['action'] === 'copy');
    foreach ($copied as $file) {
        expect($file['backed_up'])->toBeFalse();
    }
});

it('reports identical and skips backup when file content matches stub', function () {
    $stubContent = file_get_contents(Installer::stubsPath().'/AGENTS.md');
    file_put_contents($this->tempDir.'/AGENTS.md', $stubContent);

    $result = $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    $entry = array_values(array_filter($result['files'], fn ($f) => $f['file'] === 'AGENTS.md'))[0];
    expect($entry['action'])->toBe('identical');
    expect($entry['backed_up'])->toBeFalse();
    expect($this->tempDir.'/AGENTS.md.bak')->not->toBeFile();
});

it('creates a backup when force-overwriting an existing file', function () {
    $agentsPath = $this->tempDir.'/AGENTS.md';
    file_put_contents($agentsPath, 'original content');

    $result = $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    $updated = array_filter($result['files'], fn ($f) => $f['file'] === 'AGENTS.md');
    expect(array_values($updated)[0]['backed_up'])->toBeTrue();
    expect($this->tempDir.'/AGENTS.md.bak')->toBeFile();
    expect(file_get_contents($this->tempDir.'/AGENTS.md.bak'))->toBe('original content');
});

it('does not set backed_up for skipped files', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $skipped = array_filter($result['files'], fn ($f) => $f['file'] === 'AGENTS.md');
    expect(array_values($skipped)[0]['backed_up'])->toBeFalse();
});

it('root template files match stubs', function () {
    $rootPath = dirname($this->stubsPath);
    $normalize = fn (string $s): string => str_replace("\r\n", "\n", $s);

    foreach (Installer::FILES as $file) {
        $rootFile = $rootPath.'/'.$file;
        $stubFile = $this->stubsPath.'/'.$file;

        expect($rootFile)->toBeFile();
        expect($normalize((string) file_get_contents($rootFile)))->toBe(
            $normalize((string) file_get_contents($stubFile)),
        );
    }
});
