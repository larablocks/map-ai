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
    foreach ([...Installer::MANAGED_FILES, ...Installer::SCAFFOLD_FILES] as $file) {
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
    expect($this->tempDir.'/docs/COMPLIANCE.md')->toBeFile();
    expect($this->tempDir.'/docs/METRICS_HISTORY.md')->toBeFile();
    expect($this->tempDir.'/docs/DESIGN.md')->toBeFile();
    expect($this->tempDir.'/docs/COMMANDS.md')->toBeFile();
    expect($this->tempDir.'/.claude/rules/security.md')->toBeFile();
    expect($this->tempDir.'/.claude/rules/testing.md')->toBeFile();
    expect($this->tempDir.'/.claude/skills/example-skill/SKILL.md')->toBeFile();
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
    expect($this->tempDir.'/.claude/skills/example-skill')->toBeDirectory();
    expect($this->tempDir.'/.github')->toBeDirectory();
});

it('appends map entries to an empty gitignore', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');

    expect($gitignore)
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
        ->toContain('.claude/settings.local.json');
});

it('does not duplicate gitignore entries on re-install', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);
    $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');
    expect(substr_count($gitignore, '.claude/settings.local.json'))->toBe(1);
});

it('appends only the missing gitignore group when another group is already present', function () {
    $partial = "docs/MEMORY.md\n";
    file_put_contents($this->tempDir.'/.gitignore', $partial);

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');
    expect(substr_count($gitignore, 'docs/MEMORY.md'))->toBe(1);
    expect($gitignore)->toContain('.claude/settings.local.json');
    expect($gitignore)->toContain('CLAUDE.local.md');
    expect($gitignore)->toContain('docs/memory/*.md');
    expect($result['gitignore'])->toBe('updated');
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

it('appends merge=map-ai entries to an empty gitattributes', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);

    $gitattributes = file_get_contents($this->tempDir.'/.gitattributes');

    foreach (Installer::GITATTRIBUTES_ENTRIES as $entry) {
        expect($gitattributes)->toContain($entry);
    }
    expect($gitattributes)
        ->toContain('docs/BUGS.md merge=map-ai')
        ->not->toContain('merge=union')
        ->toContain('docs/COMPLIANCE.md merge=map-ai')
        ->toContain('AGENTS.md merge=map-ai');
});

it('appends gitattributes entries after existing content', function () {
    $existing = "* text=auto eol=lf\n";
    file_put_contents($this->tempDir.'/.gitattributes', $existing);

    $this->installer->install($this->stubsPath, $this->tempDir);

    $gitattributes = file_get_contents($this->tempDir.'/.gitattributes');

    expect($gitattributes)
        ->toStartWith($existing)
        ->toContain('docs/BUGS.md merge=map-ai');
});

it('does not duplicate gitattributes entries or the header on re-install', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);
    $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    $gitattributes = file_get_contents($this->tempDir.'/.gitattributes');
    expect(substr_count($gitattributes, 'docs/BUGS.md merge=map-ai'))->toBe(1);
    expect(substr_count($gitattributes, '# MAP — structured markdown merge driver'))->toBe(1);
});

it('appends only the missing gitattributes entries when some already exist', function () {
    $partial = "docs/BUGS.md merge=map-ai\ndocs/BUGS_ARCHIVE.md merge=map-ai\n";
    file_put_contents($this->tempDir.'/.gitattributes', $partial);

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $gitattributes = file_get_contents($this->tempDir.'/.gitattributes');
    expect(substr_count($gitattributes, 'docs/BUGS.md merge=map-ai'))->toBe(1);
    expect(substr_count($gitattributes, 'docs/BUGS_ARCHIVE.md merge=map-ai'))->toBe(1);
    expect($gitattributes)->toContain('docs/METRICS_HISTORY.md merge=map-ai');
    expect($result['gitattributes'])->toBe('updated');
});

it('replaces the legacy merge=union entries and leaves the project\'s own attributes alone', function () {
    $legacy = "* text=auto eol=lf\n\n# MAP — merge-friendly append-only logs\n"
        .implode("\n", Installer::LEGACY_GITATTRIBUTES_ENTRIES)."\n"
        ."docs/custom.md merge=union\n";
    file_put_contents($this->tempDir.'/.gitattributes', $legacy);

    $this->installer->install($this->stubsPath, $this->tempDir);

    $gitattributes = file_get_contents($this->tempDir.'/.gitattributes');
    foreach (Installer::LEGACY_GITATTRIBUTES_ENTRIES as $entry) {
        expect($gitattributes)->not->toContain($entry);
    }
    expect($gitattributes)
        ->toStartWith("* text=auto eol=lf\n")
        ->toContain('docs/custom.md merge=union')
        ->toContain('docs/BUGS.md merge=map-ai');
});

it('skips registering the merge driver outside a git repository', function () {
    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    expect($result['mergeDriver'])->toBe('not-a-repo');
});

it('registers the merge driver in a git repository, once', function () {
    shell_exec('git -C '.escapeshellarg($this->tempDir).' init -q 2>&1');

    expect($this->installer->install($this->stubsPath, $this->tempDir)['mergeDriver'])->toBe('updated');
    expect(Installer::mergeDriverRegistered($this->tempDir))->toBeTrue();
    expect(trim((string) shell_exec('git -C '.escapeshellarg($this->tempDir).' config --get merge.map-ai.driver')))
        ->toBe(Installer::MERGE_DRIVER_COMMAND);

    expect($this->installer->install($this->stubsPath, $this->tempDir)['mergeDriver'])->toBe('skipped');
});

it('keeps lib.sh, the SessionStart hook, and Installer on the same merge driver', function (string $subdir) {
    $lib = file_get_contents(dirname($this->stubsPath).'/lib.sh');
    expect($lib)->toContain("MERGE_DRIVER_COMMAND='".Installer::MERGE_DRIVER_COMMAND."'");
    foreach (Installer::GITATTRIBUTES_ENTRIES as $entry) {
        expect($lib)->toContain('"'.$entry.'"');
    }

    // A project at the repo root, or in a subdirectory of a monorepo: all three
    // must register the same command, pointing at that project's .map/merge.sh.
    shell_exec('git init -q '.escapeshellarg($this->tempDir));
    $project = $subdir === '' ? $this->tempDir : "$this->tempDir/$subdir";
    @mkdir("$project/.map", 0755, true);
    copy($this->stubsPath.'/.map/merge.sh', "$project/.map/merge.sh");

    $expected = Installer::mergeDriverCommand($project);
    expect($expected)->toContain('/'.($subdir === '' ? '' : "$subdir/").'.map/merge.sh"');

    $fromLib = trim((string) shell_exec('bash -c '.escapeshellarg('source '.escapeshellarg(dirname($this->stubsPath).'/lib.sh').' && merge_driver_command '.escapeshellarg($project))));
    expect($fromLib)->toBe($expected);

    shell_exec('cd '.escapeshellarg($project).' && CLAUDE_PROJECT_DIR=. bash '.escapeshellarg($this->stubsPath.'/.claude/hooks/map-first-run-check.sh').' >/dev/null 2>&1');
    expect(trim((string) shell_exec('git -C '.escapeshellarg($project).' config --get merge.map-ai.driver')))->toBe($expected);
    expect(Installer::mergeDriverRegistered($project))->toBeTrue();
})->with(['repo root' => '', 'monorepo subdirectory' => 'packages/my app']);

it('merges MAP docs through the driver when installed in a monorepo subdirectory', function () {
    $repo = $this->tempDir;
    $project = "$repo/packages/app";
    mkdir($project, 0755, true);
    $git = fn (string $args) => (string) shell_exec('cd '.escapeshellarg($repo).' && MAP_MERGE_LLM=0 git -c user.name=t -c user.email=t@t '.$args.' 2>&1');
    $git('init -q -b main');
    $this->installer->install($this->stubsPath, $project);
    $git('add -A');
    $git('commit -qm install');

    $glossary = "$project/docs/GLOSSARY.md";
    $original = (string) file_get_contents($glossary);
    $row = '| [Term] | [Plain English definition — include how it is used specifically in this project] |';
    $git('checkout -qb feature');
    file_put_contents($glossary, str_replace($row, "$row\n| Theirs | b |", $original));
    $git('commit -qam theirs');
    $git('checkout -q main');
    file_put_contents($glossary, str_replace($row, "$row\n| Ours | a |", $original));
    $git('commit -qam ours');

    $output = $git('merge --no-edit feature');

    expect($output)->not->toContain('CONFLICT')->not->toContain('No such file');
    expect((string) file_get_contents($glossary))->toContain('| Ours | a |')->toContain('| Theirs | b |')->not->toContain('<<<<<<<');

    // BUG-N renumbering reads the project's own bug files, not the repo root's.
    $bugs = "$project/docs/BUGS.md";
    $original = (string) file_get_contents($bugs);
    $bug = fn (string $title) => str_replace("\n## Fixed bugs", "\n### BUG-1 — $title\n- x\n\n## Fixed bugs", $original);
    $git('checkout -qb feature2');
    file_put_contents($bugs, $bug('Theirs'));
    $git('commit -qam theirs2');
    $git('checkout -q main');
    file_put_contents("$project/docs/BUGS_ARCHIVE.md", file_get_contents("$project/docs/BUGS_ARCHIVE.md")."\n### BUG-1 — 2026-09-10 — Ours, archived\n");
    file_put_contents($bugs, str_replace("\n## Fixed bugs", "\n### BUG-2 — Ours\n- y\n\n## Fixed bugs", $original));
    $git('commit -qam ours2');

    $output = $git('merge --no-edit feature2');

    expect($output)->not->toContain('CONFLICT');
    expect((string) file_get_contents($bugs))->toContain('### BUG-2 — Ours')->toContain('### BUG-3 — Theirs');
    exec('cd '.escapeshellarg($project).' && bash .map/merge.sh --check-bugs', $out, $exit);
    expect($exit)->toBe(0);
});

it('returns updated for new gitattributes and skipped once present', function () {
    $result = $this->installer->install($this->stubsPath, $this->tempDir);
    expect($result['gitattributes'])->toBe('updated');

    $result = $this->installer->install($this->stubsPath, $this->tempDir, force: true);
    expect($result['gitattributes'])->toBe('skipped');
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

it('returns out-of-date scaffold files that differ from stubs', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $outOfDate = $this->installer->scaffoldOutOfDate($this->stubsPath, $this->tempDir);

    expect($outOfDate)->toContain('AGENTS.md');
});

it('excludes scaffold files that match the stub', function () {
    $stubContent = file_get_contents($this->stubsPath.'/AGENTS.md');
    file_put_contents($this->tempDir.'/AGENTS.md', $stubContent);

    $outOfDate = $this->installer->scaffoldOutOfDate($this->stubsPath, $this->tempDir);

    expect($outOfDate)->not->toContain('AGENTS.md');
});

it('excludes scaffold files that do not exist in the project', function () {
    $outOfDate = $this->installer->scaffoldOutOfDate($this->stubsPath, $this->tempDir);

    expect($outOfDate)->not->toContain('AGENTS.md');
});

it('updates managed files without force', function () {
    $rulesPath = $this->tempDir.'/.cursor/rules/agents.mdc';
    mkdir(dirname($rulesPath), 0755, true);
    file_put_contents($rulesPath, 'old content');

    $this->installer->install($this->stubsPath, $this->tempDir);

    expect(file_get_contents($rulesPath))->not->toBe('old content');
});

it('reports update action for changed managed files without force', function () {
    mkdir($this->tempDir.'/.cursor/rules', 0755, true);
    file_put_contents($this->tempDir.'/.cursor/rules/agents.mdc', 'old content');

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $entry = array_values(array_filter($result['files'], fn ($f) => $f['file'] === '.cursor/rules/agents.mdc'))[0];
    expect($entry['action'])->toBe('update');
    expect($entry['backed_up'])->toBeFalse();
    expect($this->tempDir.'/.cursor/rules/agents.mdc.bak')->not->toBeFile();
});

it('reports identical for managed files with unchanged content', function () {
    $stubContent = file_get_contents($this->stubsPath.'/.cursor/rules/agents.mdc');
    mkdir($this->tempDir.'/.cursor/rules', 0755, true);
    file_put_contents($this->tempDir.'/.cursor/rules/agents.mdc', $stubContent);

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $entry = array_values(array_filter($result['files'], fn ($f) => $f['file'] === '.cursor/rules/agents.mdc'))[0];
    expect($entry['action'])->toBe('identical');
});

it('backs up security.md, testing.md, and copilot-instructions.md as scaffold files on force-overwrite', function () {
    $paths = [
        '.claude/rules/security.md',
        '.claude/rules/testing.md',
        '.github/copilot-instructions.md',
    ];
    foreach ($paths as $path) {
        $fullPath = $this->tempDir.'/'.$path;
        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        file_put_contents($fullPath, 'customized content');
    }

    $result = $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    foreach ($paths as $path) {
        $entry = array_values(array_filter($result['files'], fn ($f) => $f['file'] === $path))[0];
        expect($entry['action'])->toBe('update');
        expect($entry['backed_up'])->toBeTrue();
        expect($this->tempDir.'/'.$path.'.bak')->toBeFile();
        expect(file_get_contents($this->tempDir.'/'.$path.'.bak'))->toBe('customized content');
    }
});

it('skips security.md, testing.md, and copilot-instructions.md without force', function () {
    $paths = [
        '.claude/rules/security.md',
        '.claude/rules/testing.md',
        '.github/copilot-instructions.md',
    ];
    foreach ($paths as $path) {
        $fullPath = $this->tempDir.'/'.$path;
        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        file_put_contents($fullPath, 'customized content');
    }

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    foreach ($paths as $path) {
        $entry = array_values(array_filter($result['files'], fn ($f) => $f['file'] === $path))[0];
        expect($entry['action'])->toBe('skip');
        expect(file_get_contents($this->tempDir.'/'.$path))->toBe('customized content');
    }
});

it('skips scaffold files without force', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $result = $this->installer->install($this->stubsPath, $this->tempDir);

    $entry = array_values(array_filter($result['files'], fn ($f) => $f['file'] === 'AGENTS.md'))[0];
    expect($entry['action'])->toBe('skip');
    expect(file_get_contents($this->tempDir.'/AGENTS.md'))->toBe('custom content');
});

it('root template files match stubs', function () {
    $rootPath = dirname($this->stubsPath);
    $normalize = fn (string $s): string => str_replace("\r\n", "\n", $s);

    foreach ([...Installer::MANAGED_FILES, ...Installer::SCAFFOLD_FILES] as $file) {
        $rootFile = $rootPath.'/'.$file;
        $stubFile = $this->stubsPath.'/'.$file;

        expect($rootFile)->toBeFile();
        expect($normalize((string) file_get_contents($rootFile)))->toBe(
            $normalize((string) file_get_contents($stubFile)),
        );
    }
});

it('excludes framework.example.md from PERSONAL_FILES since it needs a project-specific rename', function () {
    expect(Installer::PERSONAL_FILES)->not->toHaveKey('docs/memory/framework.example.md');
});

it('bootstraps personal files by copying each example when the personal file is missing', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);

    $results = $this->installer->bootstrapPersonalFiles($this->tempDir);

    $actions = array_column($results, 'action');
    expect($actions)->each->toBe('copy');
    foreach (Installer::PERSONAL_FILES as $personal) {
        expect($this->tempDir.'/'.$personal)->toBeFile();
    }
});

it('skips bootstrapping a personal file that already exists', function () {
    $this->installer->install($this->stubsPath, $this->tempDir);
    file_put_contents($this->tempDir.'/docs/memory/gotchas.md', 'existing personal notes');

    $results = $this->installer->bootstrapPersonalFiles($this->tempDir);

    $entry = array_values(array_filter($results, fn ($r) => $r['file'] === 'docs/memory/gotchas.md'))[0];
    expect($entry['action'])->toBe('skip');
    expect(file_get_contents($this->tempDir.'/docs/memory/gotchas.md'))->toBe('existing personal notes');
});

it('reports missing when bootstrapping before the example files are installed', function () {
    $results = $this->installer->bootstrapPersonalFiles($this->tempDir);

    $actions = array_column($results, 'action');
    expect($actions)->each->toBe('missing');
});

it('keeps lib.sh MANAGED_FILES and SCAFFOLD_FILES in sync with Installer.php', function () {
    $libShPath = dirname(Installer::stubsPath()).'/lib.sh';
    $libSh = file_get_contents($libShPath);

    $extractArray = function (string $name) use ($libSh): array {
        preg_match('/^'.$name.'=\((.*?)^\)/ms', $libSh, $matches);

        return array_values(array_filter(array_map('trim', explode("\n", $matches[1] ?? ''))));
    };

    expect($extractArray('MANAGED_FILES'))->toBe(Installer::MANAGED_FILES);
    expect($extractArray('SCAFFOLD_FILES'))->toBe(Installer::SCAFFOLD_FILES);
});

it('keeps lib.sh PERSONAL_FILES in sync with Installer.php', function () {
    $libShPath = dirname(Installer::stubsPath()).'/lib.sh';
    $libSh = file_get_contents($libShPath);

    preg_match('/^PERSONAL_FILES=\((.*?)^\)/ms', $libSh, $matches);
    $pairs = array_values(array_filter(array_map('trim', explode("\n", $matches[1] ?? ''))));

    $extracted = [];
    foreach ($pairs as $pair) {
        // Each entry is a quoted "example:personal" string.
        [$example, $personal] = explode(':', trim($pair, '"'), 2);
        $extracted[$example] = $personal;
    }

    expect($extracted)->toBe(Installer::PERSONAL_FILES);
});

it('never writes through a symlinked destination, even with force', function () {
    $outside = sys_get_temp_dir().'/map-ai-symlink-target-'.uniqid();
    file_put_contents($outside, 'precious data outside the target directory');
    symlink($outside, $this->tempDir.'/AGENTS.md');

    $result = $this->installer->install($this->stubsPath, $this->tempDir, force: true);

    $entry = array_values(array_filter($result['files'], fn ($f) => $f['file'] === 'AGENTS.md'))[0];
    expect($entry['action'])->toBe('symlink');
    expect($entry['backed_up'])->toBeFalse();
    expect(file_get_contents($outside))->toBe('precious data outside the target directory');
    expect(is_link($this->tempDir.'/AGENTS.md'))->toBeTrue();

    unlink($outside);
});
