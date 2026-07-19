<?php

use larablocks\MapAi\Doctor;
use larablocks\MapAi\Installer;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/map-ai-doctor-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->stubsPath = Installer::stubsPath();
    $this->doctor = new Doctor;
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

function findingsOf(array $findings, string $id): array
{
    return array_values(array_filter($findings, fn (array $f) => $f['id'] === $id));
}

it('reports every stub file as a fixable missing-file finding on an empty project', function () {
    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    $missing = findingsOf($findings, 'missing-file');
    expect($missing)->not->toBeEmpty();
    expect($missing[0]['fixable'])->toBeTrue();

    $missingFiles = array_column($missing, 'file');
    expect($missingFiles)->toContain('AGENTS.md');
    expect($missingFiles)->toContain('docs/BUGS.md');
});

it('reports no missing-file findings right after a full install', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    expect(findingsOf($findings, 'missing-file'))->toBeEmpty();
});

it('reports an out-of-date scaffold file as not fixable', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    file_put_contents($this->tempDir.'/docs/BUGS.md', "# stale project bugs\ncustom content\n");

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    $outdated = findingsOf($findings, 'outdated-scaffold-file');
    $bugs = array_values(array_filter($outdated, fn (array $f) => $f['file'] === 'docs/BUGS.md'));
    expect($bugs)->toHaveCount(1);
    expect($bugs[0]['fixable'])->toBeFalse();
});

it('does not flag AGENTS.md within the line cap', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    expect(findingsOf($findings, 'agents-md-too-long'))->toBeEmpty();
});

it('flags AGENTS.md over the line cap as not fixable', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    $bloated = str_repeat("- extra line\n", Doctor::AGENTS_MD_MAX_LINES + 10);
    file_put_contents($this->tempDir.'/AGENTS.md', $bloated);

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    $tooLong = findingsOf($findings, 'agents-md-too-long');
    expect($tooLong)->toHaveCount(1);
    expect($tooLong[0]['fixable'])->toBeFalse();
});

it('flags copilot-instructions.md as fixable when it is only missing added content', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    // A real fresh install's copilot-instructions.md carries the stub's own hand-tuned
    // wording, which the mechanical regenerator can't reproduce byte-for-byte — so it's
    // never a valid "clean" baseline for this scenario. Establish one explicitly instead.
    file_put_contents(
        $this->tempDir.'/.github/copilot-instructions.md',
        $this->doctor->regenerateCopilotInstructions($this->tempDir)
    );

    file_put_contents(
        $this->tempDir.'/AGENTS.md',
        file_get_contents($this->tempDir.'/AGENTS.md')."\n## Project hard rules\n- Never call the production billing API from a dev session.\n"
    );

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    $copilot = findingsOf($findings, 'copilot-out-of-sync');
    expect($copilot)->toHaveCount(1);
    expect($copilot[0]['fixable'])->toBeTrue();
});

it('flags copilot-instructions.md as not fixable when regenerating would drop a line', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    file_put_contents(
        $this->tempDir.'/.github/copilot-instructions.md',
        $this->doctor->regenerateCopilotInstructions($this->tempDir)
            ."\n- Hand-added Copilot-only note that lives nowhere else\n"
    );

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    $copilot = findingsOf($findings, 'copilot-out-of-sync');
    expect($copilot)->toHaveCount(1);
    expect($copilot[0]['fixable'])->toBeFalse();
});

it('fix copies missing files without touching existing ones', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    file_put_contents($this->tempDir.'/AGENTS.md', 'developer-customized content');
    unlink($this->tempDir.'/docs/GLOSSARY.md');

    $applied = $this->doctor->fix($this->stubsPath, $this->tempDir);

    expect($this->tempDir.'/docs/GLOSSARY.md')->toBeFile();
    expect(file_get_contents($this->tempDir.'/AGENTS.md'))->toBe('developer-customized content');

    $copied = array_values(array_filter($applied, fn (array $a) => $a['file'] === 'docs/GLOSSARY.md'));
    expect($copied)->toHaveCount(1);
});

it('fix never touches an out-of-date scaffold file', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    file_put_contents($this->tempDir.'/docs/BUGS.md', "# stale project bugs\ncustom content\n");

    $this->doctor->fix($this->stubsPath, $this->tempDir);

    expect(file_get_contents($this->tempDir.'/docs/BUGS.md'))->toBe("# stale project bugs\ncustom content\n");
});

it('fix regenerates copilot-instructions.md when doing so only adds content', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    file_put_contents(
        $this->tempDir.'/.github/copilot-instructions.md',
        $this->doctor->regenerateCopilotInstructions($this->tempDir)
    );

    file_put_contents(
        $this->tempDir.'/AGENTS.md',
        file_get_contents($this->tempDir.'/AGENTS.md')."\n## Project hard rules\n- Never call the production billing API from a dev session.\n"
    );

    $applied = $this->doctor->fix($this->stubsPath, $this->tempDir);

    $copilotContent = file_get_contents($this->tempDir.'/.github/copilot-instructions.md');
    expect($copilotContent)->toContain('Never call the production billing API from a dev session.');

    $copilotFixes = array_values(array_filter($applied, fn (array $a) => $a['id'] === 'copilot-sync'));
    expect($copilotFixes)->toHaveCount(1);
});

it('fix leaves copilot-instructions.md untouched when regenerating would drop a hand-added line', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    $copilotPath = $this->tempDir.'/.github/copilot-instructions.md';
    $original = $this->doctor->regenerateCopilotInstructions($this->tempDir).
        "\n- Hand-added Copilot-only note that lives nowhere else\n";
    file_put_contents($copilotPath, $original);

    $applied = $this->doctor->fix($this->stubsPath, $this->tempDir);

    expect(file_get_contents($copilotPath))->toBe($original);
    expect(array_column($applied, 'id'))->not->toContain('copilot-sync');
});

/**
 * Copies the real stubs to a temp directory and appends one line to a rules file
 * within it, simulating "the template gained new content" without touching the
 * real stubs/ directory. Returns the synthetic stubs path.
 */
function stubsWithExtraLine(string $tempBase, string $relativeFile, string $extraLine): string
{
    $stubsCopy = $tempBase.'-stubs';
    mkdir($stubsCopy, 0755, true);

    $source = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Installer::stubsPath(), RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($source as $item) {
        $dest = $stubsCopy.DIRECTORY_SEPARATOR.$source->getSubPathName();
        $item->isDir() ? mkdir($dest, 0755, true) : copy($item->getPathname(), $dest);
    }

    file_put_contents($stubsCopy.'/'.$relativeFile, file_get_contents($stubsCopy.'/'.$relativeFile).$extraLine);

    return $stubsCopy;
}

function removeDirectory(string $dir): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

it('flags a new stub line as missing-template-updates and patches it in without touching custom content', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    // The custom line goes in the middle of "Load when relevant" (matches how a real
    // project adds a project-specific Load rule); the new stub line lands at the very
    // end, so the two insertions don't collide at the same position in the diff.
    file_put_contents(
        $this->tempDir.'/AGENTS.md',
        str_replace(
            '## Write rules',
            "Read @docs/JOB_SOURCES.md before proposing a new scraper\n\n## Write rules",
            file_get_contents($this->tempDir.'/AGENTS.md')
        )
    );

    $newStubsPath = stubsWithExtraLine($this->tempDir, 'AGENTS.md', "Read @docs/FEATURE_FLAGS.md when starting a new feature\n");

    $findings = $this->doctor->check($newStubsPath, $this->tempDir);
    $missingUpdates = findingsOf($findings, 'missing-template-updates');
    $agentsFinding = array_values(array_filter($missingUpdates, fn (array $f) => $f['file'] === 'AGENTS.md'));
    expect($agentsFinding)->toHaveCount(1);
    expect($agentsFinding[0]['fixable'])->toBeTrue();

    $applied = $this->doctor->fix($newStubsPath, $this->tempDir);
    $agentsContent = file_get_contents($this->tempDir.'/AGENTS.md');

    expect($agentsContent)->toContain('Read @docs/FEATURE_FLAGS.md when starting a new feature');
    expect($agentsContent)->toContain('Read @docs/JOB_SOURCES.md before proposing a new scraper');

    $patched = array_values(array_filter($applied, fn (array $a) => $a['id'] === 'missing-template-updates' && $a['file'] === 'AGENTS.md'));
    expect($patched)->toHaveCount(1);

    removeDirectory($newStubsPath);
});

it('never flags a scaffold file that only has extra content the stub does not', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);
    file_put_contents(
        $this->tempDir.'/docs/GLOSSARY.md',
        file_get_contents($this->tempDir.'/docs/GLOSSARY.md')."\n| BUG-N | This project's bug ID convention |\n"
    );

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);

    $glossaryFindings = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'docs/GLOSSARY.md'));
    expect($glossaryFindings)->toBeEmpty();
});

it('patches new content into a file that also has unrelated reworded content, leaving the reworded part flagged', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    $original = file_get_contents($this->tempDir.'/docs/GLOSSARY.md');
    $reworded = str_replace('Domain concepts', 'Business concepts', $original);
    file_put_contents($this->tempDir.'/docs/GLOSSARY.md', $reworded);

    $newStubsPath = stubsWithExtraLine($this->tempDir, 'docs/GLOSSARY.md', "\n## New section from a newer template\n");

    $findings = $this->doctor->check($newStubsPath, $this->tempDir);
    $forGlossary = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'docs/GLOSSARY.md'));
    $ids = array_column($forGlossary, 'id');
    expect($ids)->toContain('missing-template-updates');
    expect($ids)->toContain('outdated-scaffold-file');

    $this->doctor->fix($newStubsPath, $this->tempDir);
    $patchedContent = file_get_contents($this->tempDir.'/docs/GLOSSARY.md');

    expect($patchedContent)->toContain('New section from a newer template');
    expect($patchedContent)->toContain('Business concepts');
    expect($patchedContent)->not->toContain('Domain concepts');

    removeDirectory($newStubsPath);
});

it('auto-replaces a reworded italic note line, leaving real content and non-note reword requests alone', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    // Real project data the stub has no corresponding row for — a pure target-side
    // addition, invisible to the stub diff, isolating the note-line change being tested.
    file_put_contents(
        $this->tempDir.'/docs/GLOSSARY.md',
        file_get_contents($this->tempDir.'/docs/GLOSSARY.md')."| BUG-N | This project's bug ID convention, referenced from docs/BUGS.md |\n"
    );

    $newStubsPath = $this->tempDir.'-stubs';
    mkdir($newStubsPath, 0755, true);
    $source = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->stubsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($source as $item) {
        $dest = $newStubsPath.DIRECTORY_SEPARATOR.$source->getSubPathName();
        $item->isDir() ? mkdir($dest, 0755, true) : copy($item->getPathname(), $dest);
    }
    file_put_contents(
        $newStubsPath.'/docs/GLOSSARY.md',
        str_replace(
            '_Claude-maintained — append immediately when a project-specific term is encountered; human reviews for accuracy_',
            '_Human-maintained — add entries when domain-specific language causes confusion_',
            file_get_contents($newStubsPath.'/docs/GLOSSARY.md')
        )
    );

    $findings = $this->doctor->check($newStubsPath, $this->tempDir);
    $forGlossary = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'docs/GLOSSARY.md'));
    expect(array_column($forGlossary, 'id'))->toBe(['missing-template-updates']);
    expect($forGlossary[0]['fixable'])->toBeTrue();

    $this->doctor->fix($newStubsPath, $this->tempDir);
    $patched = file_get_contents($this->tempDir.'/docs/GLOSSARY.md');

    expect($patched)->toContain('_Human-maintained — add entries when domain-specific language causes confusion_');
    expect($patched)->not->toContain('_Claude-maintained — append immediately');
    expect($patched)->toContain("This project's bug ID convention, referenced from docs/BUGS.md");

    removeDirectory($newStubsPath);
});

it('auto-replaces a reworded HTML comment block, leaving real bug entries alone', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    $bugsPath = $this->tempDir.'/docs/BUGS.md';
    $reworded = str_replace(
        '<!-- Move here when resolved, then to docs/BUGS_ARCHIVE.md as soon as the fix is verified — do not let this section accumulate -->',
        "<!-- Move here when resolved. Include fix summary and covering test. -->\n<!-- When this section exceeds 20 entries, archive oldest to docs/BUGS_ARCHIVE.md -->",
        file_get_contents($bugsPath)
    );
    // A real fixed-bug entry the stub has no corresponding row for — a pure target-side
    // addition, isolating the HTML-comment change being tested.
    $reworded .= "\n### BUG-1 — Something real ✓\n- **Fixed:** 2026-01-01\n- **Fix:** did the thing\n- **Covered by:** SomeTest\n";
    file_put_contents($bugsPath, $reworded);

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);
    $forBugs = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'docs/BUGS.md'));
    expect(array_column($forBugs, 'id'))->toBe(['missing-template-updates']);
    expect($forBugs[0]['fixable'])->toBeTrue();

    $this->doctor->fix($this->stubsPath, $this->tempDir);
    $patched = file_get_contents($bugsPath);

    expect($patched)->toContain('<!-- Move here when resolved, then to docs/BUGS_ARCHIVE.md as soon as the fix is verified — do not let this section accumulate -->');
    expect($patched)->not->toContain('When this section exceeds 20 entries');
    expect($patched)->toContain('BUG-1 — Something real ✓');
});

it('auto-replaces a reworded inline trailing comment inside a fenced code block', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    $setupPath = $this->tempDir.'/docs/SETUP.md';
    $reworded = str_replace(
        'cp docs/memory/framework.example.md docs/memory/[stack].md    # e.g. laravel.md; then update [stack].md ref in docs/MEMORY.md',
        'cp docs/memory/framework.example.md docs/memory/[stack].md    # e.g. laravel.md; then update [stack].md ref in AGENTS.md and docs/MEMORY.md',
        file_get_contents($setupPath)
    );
    file_put_contents($setupPath, $reworded);

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);
    $forSetup = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'docs/SETUP.md'));
    expect(array_column($forSetup, 'id'))->toBe(['missing-template-updates']);
    expect($forSetup[0]['fixable'])->toBeTrue();

    $this->doctor->fix($this->stubsPath, $this->tempDir);
    $patched = file_get_contents($setupPath);

    expect($patched)->toContain('cp docs/memory/framework.example.md docs/memory/[stack].md    # e.g. laravel.md; then update [stack].md ref in docs/MEMORY.md');
    expect($patched)->not->toContain('ref in AGENTS.md and docs/MEMORY.md');
    // The real command itself must be byte-identical either way — only the comment moved.
    expect($patched)->toContain('cp docs/memory/framework.example.md docs/memory/[stack].md');
});

it('does not auto-replace a trailing # reference outside a fenced code block, even when the prefix matches', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    // Simulates a real project note that happens to look like an inline comment
    // (e.g. referencing an issue number) but lives in prose, not a code fence.
    $glossaryPath = $this->tempDir.'/docs/GLOSSARY.md';
    file_put_contents(
        $glossaryPath,
        file_get_contents($glossaryPath)."\nSee tracking discussion #123 for context\n"
    );

    $newStubsPath = stubsWithExtraLine($this->tempDir, 'docs/GLOSSARY.md', '');
    file_put_contents(
        $newStubsPath.'/docs/GLOSSARY.md',
        str_replace(
            'See tracking discussion #123 for context',
            'See tracking discussion #124 for context',
            file_get_contents($glossaryPath)
        )
    );

    $findings = $this->doctor->check($newStubsPath, $this->tempDir);
    $forGlossary = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'docs/GLOSSARY.md'));
    expect(array_column($forGlossary, 'id'))->toBe(['outdated-scaffold-file']);
    expect($forGlossary[0]['fixable'])->toBeFalse();

    $this->doctor->fix($newStubsPath, $this->tempDir);
    expect(file_get_contents($glossaryPath))->toContain('#123');

    removeDirectory($newStubsPath);
});

it('never replaces a filled-in placeholder with the stub still showing [PLACEHOLDER] — AGENTS.md project/stack/date', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

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

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);
    $forAgents = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'AGENTS.md'));
    expect(array_column($forAgents, 'id'))->toBe(['outdated-scaffold-file']);
    expect($forAgents[0]['fixable'])->toBeFalse();

    $this->doctor->fix($this->stubsPath, $this->tempDir);
    $content = file_get_contents($agentsPath);

    expect($content)->toContain('_Project: archer | Stack: Laravel 13, PHP 8.5, MySQL, Redis_');
    expect($content)->toContain('_MAP v1.0 | Last updated: 2026-06-22_');
    expect($content)->not->toContain('[PROJECT NAME]');
    expect($content)->not->toContain('[DATE]');
});

it('never replaces a filled-in placeholder with the stub still showing YYYY-MM-DD — docs/STATUS.md', function () {
    (new Installer)->install($this->stubsPath, $this->tempDir);

    $statusPath = $this->tempDir.'/docs/STATUS.md';
    file_put_contents(
        $statusPath,
        str_replace(
            '_Last updated: YYYY-MM-DD by Claude_',
            '_Last updated: 2026-07-10 by Claude_',
            file_get_contents($statusPath)
        )
    );

    $findings = $this->doctor->check($this->stubsPath, $this->tempDir);
    $forStatus = array_values(array_filter($findings, fn (array $f) => $f['file'] === 'docs/STATUS.md'));
    expect(array_column($forStatus, 'id'))->toBe(['outdated-scaffold-file']);
    expect($forStatus[0]['fixable'])->toBeFalse();

    $this->doctor->fix($this->stubsPath, $this->tempDir);

    expect(file_get_contents($statusPath))->toContain('_Last updated: 2026-07-10 by Claude_');
});
