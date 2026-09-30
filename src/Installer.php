<?php

namespace larablocks\MapAi;

class Installer
{
    /**
     * framework.example.md is deliberately excluded — it needs a project-specific rename
     * (e.g. laravel.md) this generic example→personal mapping can't determine, and a
     * literal framework.md would sit unread since AGENTS.md's Load rule and docs/MEMORY.md's
     * summary table both expect the renamed file. docs/SETUP.md documents the manual rename
     * command, and AGENTS.md's session-start ritual self-creates it correctly on first need.
     */
    public const PERSONAL_FILES = [
        'docs/MEMORY.example.md' => 'docs/MEMORY.md',
        'docs/memory/gotchas.example.md' => 'docs/memory/gotchas.md',
        'docs/memory/database.example.md' => 'docs/memory/database.md',
        'docs/memory/testing.example.md' => 'docs/memory/testing.md',
        'docs/memory/environment.example.md' => 'docs/memory/environment.md',
        'docs/memory/performance.example.md' => 'docs/memory/performance.md',
        'docs/memory/agents.example.md' => 'docs/memory/agents.md',
    ];

    /** Framework-owned files — always kept in sync with the package stubs, never backed up. */
    public const MANAGED_FILES = [
        '.claude/hooks/map-token-check.sh',
        '.claude/hooks/map-first-run-check.sh',
        '.claude/skills/map-resolve/SKILL.md',
        '.cursor/rules/agents.mdc',
        '.map/merge.sh',
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

    /**
     * User-owned scaffold files — copied once on install, never overwritten without --force.
     * This governs install-time file safety only. AGENTS.md, CLAUDE.md, GEMINI.md, and
     * .claude/rules/*.md carry an additional, separate restriction: AGENTS.md's own Hard
     * rule requires explicit developer instruction before Claude edits them directly,
     * regardless of this installer's SCAFFOLD/MANAGED categorization.
     *
     * .claude/settings.json is deliberately excluded from this list — it's copied
     * ad hoc by install() below (same copy-if-absent semantics) instead of being
     * enumerated here, because this repo's own root .claude/settings.json is Eric's
     * real Claude Code config for developing this package, not a template mirror,
     * and the "root template files match stubs" test asserts byte-parity for
     * every file in SCAFFOLD_FILES/MANAGED_FILES.
     */
    public const SCAFFOLD_FILES = [
        'AGENTS.md',
        'CLAUDE.md',
        'GEMINI.md',
        '.claude/rules/security.md',
        '.claude/rules/testing.md',
        '.claude/skills/example-skill/SKILL.md',
        '.github/copilot-instructions.md',
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
        'docs/STATUS_ARCHIVE.md',
        'docs/TESTING_COVERAGE.md',
    ];

    /**
     * Grouped so a partially-present group only gets its missing lines appended (no
     * duplicate header, no duplicate already-present lines) while a fully-missing group
     * gets its header re-emitted — mirrors install.sh's equivalent grouping exactly.
     *
     * @var list<array{header: string, entries: list<string>}>
     */
    private const GITIGNORE_GROUPS = [
        [
            'header' => '# MAP — developer-specific files (do not commit)',
            'entries' => ['.claude/settings.local.json'],
        ],
        [
            'header' => '# Claude personal local rules — developer specific, not shared',
            'entries' => ['CLAUDE.local.md'],
        ],
        [
            'header' => "# Claude auto-memory — session/machine specific\n# Copy *.example.md files to their non-example versions on first clone",
            'entries' => ['docs/MEMORY.md', 'docs/memory/*.md', '!docs/memory/*.example.md', '!docs/memory/shared.md'],
        ],
    ];

    /**
     * merge=map-ai routes the Claude-maintained docs through .map/merge.sh, which
     * resolves the conflicts markdown docs typically hit (both branches appending
     * entries or table rows, bumping "Last updated", picking the same next BUG-N), then
     * offers Claude what's left — always stopping for review. Covers every file an AI
     * agent writes to, including the ones it only edits with approval (DESIGN/DOCKER/
     * SETUP/COMPLIANCE, AGENTS.md and the other entry points). The entry points are
     * anchored with a leading "/" — a bare "AGENTS.md" would also match nested or
     * vendored copies anywhere in the tree. Mirrors lib.sh.
     */
    private const GITATTRIBUTES_HEADER = '# MAP — structured markdown merge driver (.map/merge.sh, registered per clone)';

    public const GITATTRIBUTES_ENTRIES = [
        'docs/BUGS.md merge=map-ai',
        'docs/BUGS_ARCHIVE.md merge=map-ai',
        'docs/ARCHITECTURE_HISTORY.md merge=map-ai',
        'docs/METRICS_HISTORY.md merge=map-ai',
        'docs/STATUS.md merge=map-ai',
        'docs/STATUS_ARCHIVE.md merge=map-ai',
        'docs/ARCHITECTURE.md merge=map-ai',
        'docs/CODE_PATTERNS.md merge=map-ai',
        'docs/COMMANDS.md merge=map-ai',
        'docs/FEATURE_FLAGS.md merge=map-ai',
        'docs/GLOSSARY.md merge=map-ai',
        'docs/SCHEMA.md merge=map-ai',
        'docs/TESTING_COVERAGE.md merge=map-ai',
        'docs/memory/shared.md merge=map-ai',
        'docs/agents/*.md merge=map-ai',
        'docs/api/*.md merge=map-ai',
        'docs/architecture/*.md merge=map-ai',
        'docs/integrations/*.md merge=map-ai',
        'docs/qa/*.md merge=map-ai',
        'docs/DESIGN.md merge=map-ai',
        'docs/DOCKER.md merge=map-ai',
        'docs/SETUP.md merge=map-ai',
        'docs/COMPLIANCE.md merge=map-ai',
        '/AGENTS.md merge=map-ai',
        '/CLAUDE.md merge=map-ai',
        '/GEMINI.md merge=map-ai',
        '.github/copilot-instructions.md merge=map-ai',
        '.claude/rules/*.md merge=map-ai',
    ];

    /**
     * Lines earlier MAP versions (up to 0.1.9) wrote, header comment included.
     * merge=union kept every line from both sides, which resurrected bugs one branch
     * had moved to BUGS_ARCHIVE.md — removed on install now that merge=map-ai replaces
     * them. The only lines install/fix ever remove, and only as exact matches.
     */
    public const LEGACY_GITATTRIBUTES_ENTRIES = [
        '# MAP — merge-friendly append-only logs',
        'docs/BUGS.md merge=union',
        'docs/BUGS_ARCHIVE.md merge=union',
        'docs/ARCHITECTURE_HISTORY.md merge=union',
        'docs/METRICS_HISTORY.md merge=union',
    ];

    /**
     * The per-clone git config that makes merge=map-ai mean something. git config is
     * never committed, so every clone registers it once — install, doctor fix and the
     * SessionStart hook all do. Unregistered, git silently falls back to its normal
     * text merge for these files.
     */
    public const MERGE_DRIVER_NAME = 'MAP structured markdown merge';

    /** The driver command for a project at the root of its git repo — see mergeDriverCommand(). */
    public const MERGE_DRIVER_COMMAND = 'bash "$(git rev-parse --show-toplevel)/.map/merge.sh" %O %A %B %P';

    /**
     * The driver command for $targetPath. git runs drivers from the repo root, so a
     * project installed in a subdirectory (a monorepo package) needs that prefix in
     * the path — without it the command fails, and git leaves only our side of the
     * file. Mirrors lib.sh's merge_driver_command.
     */
    public static function mergeDriverCommand(string $targetPath): string
    {
        $prefix = trim((string) self::git($targetPath, ['rev-parse', '--show-prefix']));

        return str_replace('/.map/merge.sh', '/'.$prefix.'.map/merge.sh', self::MERGE_DRIVER_COMMAND);
    }

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
     * @param  callable(array{action: 'copy'|'update'|'skip'|'identical'|'missing'|'symlink', file: string, backed_up: bool}): void|null  $progress
     * @return array{
     *     files: list<array{action: 'copy'|'update'|'skip'|'identical'|'missing'|'symlink', file: string, backed_up: bool}>,
     *     gitignore: 'updated'|'skipped',
     *     gitattributes: 'updated'|'skipped',
     *     claudeSettings: array{action: 'copy'|'update'|'skip'|'identical'|'missing'|'symlink', file: string, backed_up: bool},
     *     mergeDriver: 'updated'|'skipped'|'not-a-repo'|'failed'
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

        // Not part of SCAFFOLD_FILES — see that constant's docblock for why. Same
        // copy-if-absent-else-skip semantics, just not enumerated or diffed like
        // the rest of the scaffold, since a target may already have this file
        // populated with unrelated hooks/permissions this installer must not clobber.
        $claudeSettings = $this->copyFile($stubsPath, $targetPath, '.claude/settings.json', force: $force, backup: true);

        return [
            'files' => $files,
            'gitignore' => $this->mergeGitignore($targetPath),
            'gitattributes' => $this->mergeGitattributes($targetPath),
            'claudeSettings' => $claudeSettings,
            'mergeDriver' => $this->registerMergeDriver($targetPath),
        ];
    }

    /**
     * @param  callable(array{action: 'copy'|'skip'|'missing', file: string}): void|null  $progress
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

    /** @return array{action: 'copy'|'update'|'skip'|'identical'|'missing'|'symlink', file: string, backed_up: bool} */
    private function copyFile(string $stubsPath, string $targetPath, string $file, bool $force, bool $backup = true): array
    {
        $src = $stubsPath.'/'.$file;
        $dst = $targetPath.'/'.$file;

        if (! file_exists($src)) {
            return ['action' => 'missing', 'file' => $file, 'backed_up' => false];
        }

        // Never write through a symlink at the destination — copy()/file_put_contents()
        // follow it, which could silently write outside the target directory entirely.
        if (is_link($dst)) {
            return ['action' => 'symlink', 'file' => $file, 'backed_up' => false];
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

        // copy() doesn't preserve the source's permission bits (e.g. the executable
        // bit on .claude/hooks/*.sh) — the destination gets PHP's default mode instead.
        $sourcePerms = fileperms($src);
        if ($sourcePerms !== false) {
            chmod($dst, $sourcePerms & 0777);
        }

        return ['action' => $action, 'file' => $file, 'backed_up' => $action === 'update' && $backup];
    }

    /** @return 'skipped'|'updated' */
    private function mergeGitignore(string $targetPath): string
    {
        $gitignorePath = $targetPath.'/.gitignore';
        $existing = file_exists($gitignorePath) ? (file_get_contents($gitignorePath) ?: '') : '';
        $existingLines = $existing !== '' ? array_map('trim', explode("\n", $existing)) : [];

        $additions = [];
        foreach (self::GITIGNORE_GROUPS as $group) {
            $missing = array_values(array_diff($group['entries'], $existingLines));

            if ($missing === []) {
                continue;
            }

            $additions[] = $missing === $group['entries']
                ? $group['header']."\n".implode("\n", $group['entries'])
                : implode("\n", $missing);
        }

        if ($additions === []) {
            return 'skipped';
        }

        file_put_contents($gitignorePath, $existing."\n".implode("\n\n", $additions)."\n");

        return 'updated';
    }

    /** @return 'skipped'|'updated' */
    private function mergeGitattributes(string $targetPath): string
    {
        $gitattributesPath = $targetPath.'/.gitattributes';
        $existing = file_exists($gitattributesPath) ? (file_get_contents($gitattributesPath) ?: '') : '';

        // Drop the merge=union lines earlier MAP versions wrote — exact matches only,
        // so a project's own attributes are never touched.
        $lines = $existing !== '' ? explode("\n", $existing) : [];
        $kept = array_values(array_filter(
            $lines,
            fn (string $line) => ! in_array(trim($line), self::LEGACY_GITATTRIBUTES_ENTRIES, true),
        ));
        $removedLegacy = count($kept) !== count($lines);
        $existing = implode("\n", $kept);

        $existingLines = $existing !== '' ? array_map('trim', $kept) : [];
        $missing = array_values(array_diff(self::GITATTRIBUTES_ENTRIES, $existingLines));

        if ($missing === [] && ! $removedLegacy) {
            return 'skipped';
        }

        // Only re-emit the header comment on a first write — a partial match means the
        // header is already there, so appending just the missing entries avoids duplicating
        // lines that already exist.
        $addition = '';
        if ($missing !== []) {
            $header = in_array(self::GITATTRIBUTES_HEADER, $existingLines, true) ? '' : self::GITATTRIBUTES_HEADER."\n";
            $addition = "\n".$header.implode("\n", $missing)."\n";
        }

        file_put_contents($gitattributesPath, $existing.$addition);

        return 'updated';
    }

    /**
     * Registers the merge=map-ai driver in the target clone's git config.
     *
     * @return 'updated'|'skipped'|'not-a-repo'|'failed'
     */
    public function registerMergeDriver(string $targetPath): string
    {
        if (! self::isGitRepo($targetPath)) {
            return 'not-a-repo';
        }

        if (self::mergeDriverRegistered($targetPath)) {
            return 'skipped';
        }

        $ok = self::git($targetPath, ['config', 'merge.map-ai.name', self::MERGE_DRIVER_NAME]) !== null
            && self::git($targetPath, ['config', 'merge.map-ai.driver', self::mergeDriverCommand($targetPath)]) !== null;

        return $ok ? 'updated' : 'failed';
    }

    public static function isGitRepo(string $targetPath): bool
    {
        return trim((string) self::git($targetPath, ['rev-parse', '--is-inside-work-tree'])) === 'true';
    }

    public static function mergeDriverRegistered(string $targetPath): bool
    {
        return trim((string) self::git($targetPath, ['config', '--get', 'merge.map-ai.driver'])) === self::mergeDriverCommand($targetPath);
    }

    /**
     * Runs git in $targetPath without a shell. Returns stdout, or null on a non-zero exit.
     *
     * @param  list<string>  $args
     */
    private static function git(string $targetPath, array $args): ?string
    {
        $process = proc_open(
            ['git', '-C', $targetPath, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? (string) $stdout : null;
    }
}
