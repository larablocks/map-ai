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
