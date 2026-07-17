<?php

namespace larablocks\MapAi;

class Installer
{
    public const PERSONAL_FILES = [
        'docs/MEMORY.example.md'                => 'docs/MEMORY.md',
        'docs/memory/gotchas.example.md'        => 'docs/memory/gotchas.md',
        'docs/memory/framework.example.md'      => 'docs/memory/framework.md',
        'docs/memory/database.example.md'       => 'docs/memory/database.md',
        'docs/memory/testing.example.md'        => 'docs/memory/testing.md',
        'docs/memory/environment.example.md'    => 'docs/memory/environment.md',
        'docs/memory/performance.example.md'    => 'docs/memory/performance.md',
        'docs/memory/agents.example.md'         => 'docs/memory/agents.md',
    ];

    /** Framework-owned files — always kept in sync with the package stubs, never backed up. */
    public const MANAGED_FILES = [
        '.claude/rules/security.md',
        '.claude/rules/testing.md',
        '.github/copilot-instructions.md',
        '.cursor/rules/agents.mdc',
        'docs/MEMORY.example.md',
        'docs/agents/agent.example.md',
        'docs/api/api.example.md',
        'docs/architecture/architecture.example.md',
        'docs/integrations/integration.example.md',
        'docs/qa/qa.example.md',
        'docs/memory/agents.example.md',
        'docs/memory/database.example.md',
        'docs/memory/environment.example.md',
        'docs/memory/framework.example.md',
        'docs/memory/gotchas.example.md',
        'docs/memory/performance.example.md',
        'docs/memory/shared.example.md',
        'docs/memory/testing.example.md',
    ];

    /** User-owned scaffold files — copied once on install, never overwritten without --force. */
    public const SCAFFOLD_FILES = [
        'AGENTS.md',
        'CLAUDE.md',
        'GEMINI.md',
        '.claude/skills/example-skill/SKILL.md',
        'docs/ARCHITECTURE.md',
        'docs/ARCHITECTURE_HISTORY.md',
        'docs/BUGS.md',
        'docs/BUGS_ARCHIVE.md',
        'docs/CODE_PATTERNS.md',
        'docs/COMMANDS.md',
        'docs/COMPLIANCE.md',
        'docs/DESIGN.md',
        'docs/DOCKER.md',
        'docs/FEATURE_FLAGS.md',
        'docs/GLOSSARY.md',
        'docs/METRICS_HISTORY.md',
        'docs/SCHEMA.md',
        'docs/SETUP.md',
        'docs/STATUS.md',
        'docs/TESTING_COVERAGE.md',
    ];

    private const GITIGNORE_BLOCK = <<<'BLOCK'

# MAP — developer-specific files (do not commit)
.claude/settings.local.json

# Claude personal local rules — developer specific, not shared
CLAUDE.local.md

# Claude auto-memory — session/machine specific
# Copy *.example.md files to their non-example versions on first clone
docs/MEMORY.md
docs/memory/*.md
!docs/memory/*.example.md
!docs/memory/shared.md
BLOCK;

    private const GITIGNORE_SENTINELS = [
        '.claude/settings.local.json',
        'CLAUDE.local.md',
        'docs/MEMORY.md',
        'docs/memory/*.md',
    ];

    /**
     * merge=union lets concurrent appends to these append-only logs combine automatically
     * instead of producing conflict markers. It does not catch two branches assigning the
     * same BUG-N — docs/BUGS.md documents the post-merge renumbering procedure for that.
     */
    private const GITATTRIBUTES_HEADER = '# MAP — merge-friendly append-only logs';

    private const GITATTRIBUTES_ENTRIES = [
        'docs/BUGS.md merge=union',
        'docs/BUGS_ARCHIVE.md merge=union',
        'docs/ARCHITECTURE_HISTORY.md merge=union',
        'docs/METRICS_HISTORY.md merge=union',
    ];

    public static function stubsPath(): string
    {
        return dirname(__DIR__).'/stubs';
    }

    /** @return list<string> Scaffold files that exist in the project but differ from the current stub. */
    public function scaffoldOutOfDate(string $stubsPath, string $targetPath): array
    {
        $outOfDate = [];

        foreach (self::SCAFFOLD_FILES as $file) {
            $stub = $stubsPath.'/'.$file;
            $project = $targetPath.'/'.$file;

            if (file_exists($stub) && file_exists($project) && file_get_contents($stub) !== file_get_contents($project)) {
                $outOfDate[] = $file;
            }
        }

        return $outOfDate;
    }

    /**
     * @param callable(array{action: 'copy'|'update'|'skip'|'identical'|'missing', file: string, backed_up: bool}): void|null $progress
     * @return array{
     *     files: list<array{action: 'copy'|'update'|'skip'|'identical'|'missing', file: string, backed_up: bool}>,
     *     gitignore: 'updated'|'skipped',
     *     gitattributes: 'updated'|'skipped'
     * }
     */
    public function install(string $stubsPath, string $targetPath, bool $force = false, ?callable $progress = null): array
    {
        $files = [];

        foreach (self::MANAGED_FILES as $file) {
            $result = $this->copyFile($stubsPath, $targetPath, $file, force: true, backup: false);
            $files[] = $result;
            if ($progress !== null) {
                $progress($result);
            }
        }

        foreach (self::SCAFFOLD_FILES as $file) {
            $result = $this->copyFile($stubsPath, $targetPath, $file, force: $force, backup: true);
            $files[] = $result;
            if ($progress !== null) {
                $progress($result);
            }
        }

        return [
            'files' => $files,
            'gitignore' => $this->mergeGitignore($targetPath),
            'gitattributes' => $this->mergeGitattributes($targetPath),
        ];
    }

    /**
     * @param callable(array{action: 'copy'|'skip'|'missing', file: string}): void|null $progress
     * @return list<array{action: 'copy'|'skip'|'missing', file: string}>
     */
    public function bootstrapPersonalFiles(string $targetPath, ?callable $progress = null): array
    {
        $results = [];

        foreach (self::PERSONAL_FILES as $example => $personal) {
            $src = $targetPath.'/'.$example;
            $dst = $targetPath.'/'.$personal;

            if (! file_exists($src)) {
                $result = ['action' => 'missing', 'file' => $personal];
            } elseif (file_exists($dst)) {
                $result = ['action' => 'skip', 'file' => $personal];
            } else {
                copy($src, $dst);
                $result = ['action' => 'copy', 'file' => $personal];
            }

            $results[] = $result;
            if ($progress !== null) {
                $progress($result);
            }
        }

        return $results;
    }

    /** @return array{action: 'copy'|'update'|'skip'|'identical'|'missing', file: string, backed_up: bool} */
    private function copyFile(string $stubsPath, string $targetPath, string $file, bool $force, bool $backup = true): array
    {
        $src = $stubsPath.'/'.$file;
        $dst = $targetPath.'/'.$file;

        if (! file_exists($src)) {
            return ['action' => 'missing', 'file' => $file, 'backed_up' => false];
        }

        if (file_exists($dst) && ! $force) {
            return ['action' => 'skip', 'file' => $file, 'backed_up' => false];
        }

        $action = file_exists($dst) ? 'update' : 'copy';

        if ($action === 'update') {
            if (file_get_contents($src) === file_get_contents($dst)) {
                return ['action' => 'identical', 'file' => $file, 'backed_up' => false];
            }

            if ($backup) {
                copy($dst, $dst.'.bak');
            }
        }

        if (! is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0755, true);
        }

        copy($src, $dst);

        return ['action' => $action, 'file' => $file, 'backed_up' => $action === 'update' && $backup];
    }

    /** @return 'skipped'|'updated' */
    private function mergeGitignore(string $targetPath): string
    {
        $gitignorePath = $targetPath.'/.gitignore';
        $existing = file_exists($gitignorePath) ? (file_get_contents($gitignorePath) ?: '') : '';
        $existingLines = $existing !== '' ? array_map('trim', explode("\n", $existing)) : [];

        foreach (self::GITIGNORE_SENTINELS as $sentinel) {
            if (! in_array($sentinel, $existingLines, true)) {
                file_put_contents($gitignorePath, $existing.self::GITIGNORE_BLOCK."\n");

                return 'updated';
            }
        }

        return 'skipped';
    }

    /** @return 'skipped'|'updated' */
    private function mergeGitattributes(string $targetPath): string
    {
        $gitattributesPath = $targetPath.'/.gitattributes';
        $existing = file_exists($gitattributesPath) ? (file_get_contents($gitattributesPath) ?: '') : '';
        $existingLines = $existing !== '' ? array_map('trim', explode("\n", $existing)) : [];

        $missing = array_values(array_diff(self::GITATTRIBUTES_ENTRIES, $existingLines));

        if ($missing === []) {
            return 'skipped';
        }

        // Only re-emit the header comment on a first write — a partial match means the
        // header is already there, so appending just the missing entries avoids duplicating
        // lines that already exist.
        $addition = $missing === self::GITATTRIBUTES_ENTRIES
            ? "\n".self::GITATTRIBUTES_HEADER."\n".implode("\n", self::GITATTRIBUTES_ENTRIES)."\n"
            : "\n".implode("\n", $missing)."\n";

        file_put_contents($gitattributesPath, $existing.$addition);

        return 'updated';
    }
}
