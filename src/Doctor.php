<?php

namespace larablocks\MapAi;

/**
 * Reports on drift between an installed MAP project and the current template, and
 * repairs only the additive, zero-judgment cases. Two guarantees drive the split:
 *
 * 1. fix() never removes or rewrites a line a developer could have written — it only
 *    adds files/lines that don't exist anywhere yet (missing files, missing gitignore
 *    entries, missing .github/copilot-instructions.md lines).
 * 2. Anything that would require dropping or reinterpreting existing content
 *    (an out-of-date SCAFFOLD_FILES entry, an AGENTS.md over the line cap, a
 *    copilot-instructions.md regeneration that would lose a line) is check()-only —
 *    fix() reports it but never touches the file.
 */
class Doctor
{
    public const AGENTS_MD_MAX_LINES = 100;

    private const COPILOT_HEADER = <<<'MD'
        # copilot-instructions.md
        _GitHub Copilot entry point — MAP v1.0 convention_
        _Copilot does not support @file imports — AGENTS.md content is inlined below._
        _When AGENTS.md, .claude/rules/security.md, or .claude/rules/testing.md changes, update this file to match — the Security/Testing rules sections below are inlined copies of those two files._
        MD;

    /** @return list<array{id: string, fixable: bool, file: string, message: string}> */
    public function check(string $stubsPath, string $targetPath): array
    {
        $findings = [];

        foreach ($this->missingFiles($stubsPath, $targetPath) as $file) {
            $findings[] = [
                'id' => 'missing-file',
                'fixable' => true,
                'file' => $file,
                'message' => 'Missing — will be copied from the current template.',
            ];
        }

        foreach (Installer::SCAFFOLD_FILES as $file) {
            if ($file === '.github/copilot-instructions.md') {
                continue; // handled separately below — regenerated from the project's own sources, not the stub
            }

            $diff = $this->diffAgainstStub($targetPath.'/'.$file, $stubsPath.'/'.$file);

            if ($diff === null) {
                continue;
            }

            if ($diff['appliableHunks'] !== []) {
                $findings[] = [
                    'id' => 'missing-template-updates',
                    'fixable' => true,
                    'file' => $file,
                    'message' => 'Has new template content or an updated instructional note — safe to patch in, real content is never touched.',
                ];
            }

            if ($diff['hasModifications']) {
                $findings[] = [
                    'id' => 'outdated-scaffold-file',
                    'fixable' => false,
                    'file' => $file,
                    'message' => "Differs from the current stub in a way that isn't a pure addition — needs a human diff and merge.",
                ];
            }
        }

        if ($finding = $this->checkAgentsLineLimit($targetPath)) {
            $findings[] = $finding;
        }

        if ($finding = $this->checkCopilotSync($targetPath)) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * @param  ?callable(array{id: string, file: string, action: string}): void  $progress
     * @return list<array{id: string, file: string, action: string}>
     */
    public function fix(string $stubsPath, string $targetPath, ?callable $progress = null): array
    {
        /** @var list<array{id: string, file: string, action: string}> $applied */
        $applied = [];

        // force: false — MANAGED_FILES still sync (they're pure template, no room for
        // customization), SCAFFOLD_FILES only fill in what's missing, existing ones are
        // left untouched. This is the same guarantee install.sh gives without --force.
        $installResult = (new Installer)->install($stubsPath, $targetPath, force: false);

        foreach ($installResult['files'] as $file) {
            if (in_array($file['action'], ['copy', 'update'], true)) {
                $applied[] = $this->reportFix(['id' => 'missing-file', 'file' => $file['file'], 'action' => (string) $file['action']], $progress);
            }
        }

        if ($installResult['gitignore'] === 'updated') {
            $applied[] = $this->reportFix(['id' => 'gitignore', 'file' => '.gitignore', 'action' => 'updated'], $progress);
        }

        if ($installResult['gitattributes'] === 'updated') {
            $applied[] = $this->reportFix(['id' => 'gitattributes', 'file' => '.gitattributes', 'action' => 'updated'], $progress);
        }

        // Patch in new template content before regenerating copilot-instructions.md, so
        // the regeneration reflects whatever AGENTS.md just picked up.
        foreach (Installer::SCAFFOLD_FILES as $file) {
            if ($file === '.github/copilot-instructions.md') {
                continue;
            }

            if ($this->patchScaffoldFile($targetPath.'/'.$file, $stubsPath.'/'.$file)) {
                $applied[] = $this->reportFix(['id' => 'missing-template-updates', 'file' => $file, 'action' => 'patched'], $progress);
            }
        }

        if ($this->fixCopilotSync($targetPath)) {
            $applied[] = $this->reportFix(['id' => 'copilot-sync', 'file' => '.github/copilot-instructions.md', 'action' => 'regenerated'], $progress);
        }

        return $applied;
    }

    /**
     * @param  array{id: string, file: string, action: string}  $result
     * @param  ?callable(array{id: string, file: string, action: string}): void  $progress
     * @return array{id: string, file: string, action: string}
     */
    private function reportFix(array $result, ?callable $progress): array
    {
        if ($progress !== null) {
            $progress($result);
        }

        return $result;
    }

    /** @return list<string> */
    private function missingFiles(string $stubsPath, string $targetPath): array
    {
        $missing = [];

        foreach ([...Installer::MANAGED_FILES, ...Installer::SCAFFOLD_FILES] as $file) {
            if (file_exists($stubsPath.'/'.$file) && ! file_exists($targetPath.'/'.$file)) {
                $missing[] = $file;
            }
        }

        return $missing;
    }

    /** @return ?array{id: string, fixable: bool, file: string, message: string} */
    private function checkAgentsLineLimit(string $targetPath): ?array
    {
        $path = $targetPath.'/AGENTS.md';

        if (! file_exists($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $lineCount = $lines === false ? 0 : count($lines);

        if ($lineCount <= self::AGENTS_MD_MAX_LINES) {
            return null;
        }

        return [
            'id' => 'agents-md-too-long',
            'fixable' => false,
            'file' => 'AGENTS.md',
            'message' => "{$lineCount} lines, over the ".self::AGENTS_MD_MAX_LINES.' line cap — trim by hand (which sections to cut is a judgment call).',
        ];
    }

    /** @return ?array{id: string, fixable: bool, file: string, message: string} */
    private function checkCopilotSync(string $targetPath): ?array
    {
        $regenerated = $this->regenerateCopilotInstructions($targetPath);

        if ($regenerated === null) {
            return null;
        }

        $current = $this->readCopilotInstructions($targetPath);

        if ($this->normalize($regenerated) === $this->normalize($current)) {
            return null;
        }

        $safe = $this->isSupersetOfCurrentLines($current, $regenerated);

        return [
            'id' => 'copilot-out-of-sync',
            'fixable' => $safe,
            'file' => '.github/copilot-instructions.md',
            'message' => $safe
                ? 'Out of sync with AGENTS.md/security.md/testing.md — safe to regenerate (only adds or reorders content already present in the source files).'
                : 'Out of sync, and regenerating would drop a line currently in the file (a hand edit, or content since removed upstream) — review the diff before touching it.',
        ];
    }

    private function fixCopilotSync(string $targetPath): bool
    {
        $regenerated = $this->regenerateCopilotInstructions($targetPath);

        if ($regenerated === null) {
            return false;
        }

        $current = $this->readCopilotInstructions($targetPath);

        if ($this->normalize($regenerated) === $this->normalize($current)) {
            return false;
        }

        if (! $this->isSupersetOfCurrentLines($current, $regenerated)) {
            return false;
        }

        $dir = dirname($targetPath.'/.github/copilot-instructions.md');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($targetPath.'/.github/copilot-instructions.md', $regenerated);

        return true;
    }

    private function readCopilotInstructions(string $targetPath): string
    {
        $path = $targetPath.'/.github/copilot-instructions.md';

        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * The canonical, mechanical inlining: AGENTS.md content verbatim (minus @-prefixes,
     * which Copilot doesn't resolve) followed by security.md's and testing.md's bullet
     * lines with their headers stripped. Returns null when any source file is missing —
     * there's nothing to regenerate from. Public because it's the reference output for
     * anything that needs to know what "in sync" looks like, not just this class.
     */
    public function regenerateCopilotInstructions(string $targetPath): ?string
    {
        $agentsPath = $targetPath.'/AGENTS.md';
        $securityPath = $targetPath.'/.claude/rules/security.md';
        $testingPath = $targetPath.'/.claude/rules/testing.md';

        if (! file_exists($agentsPath) || ! file_exists($securityPath) || ! file_exists($testingPath)) {
            return null;
        }

        $agentsBody = $this->stripAtRefs((string) file_get_contents($agentsPath));
        $securityBullets = $this->extractBullets((string) file_get_contents($securityPath));
        $testingBullets = $this->extractBullets((string) file_get_contents($testingPath));

        return self::COPILOT_HEADER."\n\n---\n\n"
            .rtrim($agentsBody)."\n\n"
            ."## Security rules\n"
            ."_Copilot does not auto-load .claude/rules/security.md — rules are inlined here_\n"
            .implode("\n", $securityBullets)."\n\n"
            ."## Testing rules\n"
            ."_Copilot does not auto-load .claude/rules/testing.md — rules are inlined here_\n"
            .implode("\n", $testingBullets)."\n";
    }

    private function stripAtRefs(string $content): string
    {
        return preg_replace('/@(?=(docs\/|CLAUDE\.local\.md))/', '', $content) ?? $content;
    }

    /** @return list<string> */
    private function extractBullets(string $content): array
    {
        $bullets = [];

        foreach (explode("\n", $content) as $line) {
            $isBullet = (bool) preg_match('/^-\s/', $line);
            $isContinuation = $bullets !== [] && (bool) preg_match('/^\s+\S/', $line);

            if ($isBullet || $isContinuation) {
                $bullets[] = rtrim($line);
            }
        }

        return $bullets;
    }

    /** Every non-blank line in $current must appear somewhere in $regenerated. */
    private function isSupersetOfCurrentLines(string $current, string $regenerated): bool
    {
        if (trim($current) === '') {
            return true;
        }

        $currentLines = array_filter(array_map('rtrim', explode("\n", $current)), fn (string $l) => trim($l) !== '');
        $regeneratedLines = array_filter(array_map('rtrim', explode("\n", $regenerated)), fn (string $l) => trim($l) !== '');

        foreach ($currentLines as $line) {
            if (! in_array($line, $regeneratedLines, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $content): string
    {
        return trim(preg_replace('/\R/', "\n", $content) ?? $content);
    }

    /**
     * Diffs $targetFile against $stubFile and classifies the result. A "pure addition"
     * hunk is stub content with no corresponding removal from the target — safe to
     * splice in. A hunk that replaces or removes target content is a "modification" —
     * never touched, surfaced for a human instead. Content the target has that the stub
     * doesn't (real customization) produces neither and is never flagged.
     *
     * @return array{appliableHunks: list<array{start: int, count: int, lines: list<string>}>, hasModifications: bool}|null
     *                                                                                                                      null if either file doesn't exist.
     */
    private function diffAgainstStub(string $targetFile, string $stubFile): ?array
    {
        if (! file_exists($targetFile) || ! file_exists($stubFile)) {
            return null;
        }

        $process = proc_open(
            ['diff', '--unified=0', $targetFile, $stubFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if ($process === false) {
            return null;
        }

        $output = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $this->classifyDiffHunks($output, $this->fencedCodeLines($targetFile));
    }

    /** @return array<int, true> set of 1-indexed line numbers inside a ``` fence */
    private function fencedCodeLines(string $file): array
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $inFence = false;
        $result = [];

        foreach ($lines as $i => $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                $result[$i + 1] = true;
            }
        }

        return $result;
    }

    /**
     * A hunk is appliable in three cases: a pure addition (oldCount 0 — stub has new
     * lines with nothing removed from target), spliced in as-is; a modification where
     * every line on both sides is a full-line italic note or HTML-comment block — the
     * conventions every stub file uses for its own instructional text, never used for
     * real content; or a single-line modification inside a fenced code block where only
     * the trailing `  # comment` differs and the real command before it is unchanged.
     * Any other modification is left for a human.
     *
     * @param  array<int, true>  $targetFenceLines
     * @return array{appliableHunks: list<array{start: int, count: int, lines: list<string>}>, hasModifications: bool}
     */
    private function classifyDiffHunks(string $diffOutput, array $targetFenceLines): array
    {
        $appliableHunks = [];
        $hasModifications = false;

        $lines = explode("\n", $diffOutput);
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            if (! preg_match('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $lines[$i], $m)) {
                $i++;

                continue;
            }

            $oldStart = (int) $m[1];
            // PCRE backfills an unmatched optional group with '' (not unset) whenever a
            // later group participates — isset() alone can't tell "omitted" from "empty".
            $oldCount = ($m[2] ?? '') !== '' ? (int) $m[2] : 1;
            $newCount = ($m[3] ?? '') !== '' ? (int) $m[3] : 1;
            $i++;

            $removed = [];
            $added = [];
            while ($i < $count && ! str_starts_with($lines[$i], '@@ ')) {
                if (str_starts_with($lines[$i], '+')) {
                    $added[] = substr($lines[$i], 1);
                } elseif (str_starts_with($lines[$i], '-')) {
                    $removed[] = substr($lines[$i], 1);
                }
                $i++;
            }

            if ($oldCount === 0 && $newCount > 0) {
                $appliableHunks[] = ['start' => $oldStart, 'count' => 0, 'lines' => $added];
            } elseif ($oldCount > 0 && $newCount > 0) {
                $isInlineCommentHunk = $oldCount === 1 && $newCount === 1
                    && isset($targetFenceLines[$oldStart])
                    && $this->isSafeInlineCommentModification($removed[0], $added[0]);

                if ($this->isSafeNoteModification($removed, $added) || $isInlineCommentHunk) {
                    $appliableHunks[] = ['start' => $oldStart - 1, 'count' => $oldCount, 'lines' => $added];
                } else {
                    $hasModifications = true;
                }
            }
            // oldCount > 0 && newCount === 0: target has content the stub doesn't —
            // that's the target's own customization, never flagged either way.
        }

        return ['appliableHunks' => $appliableHunks, 'hasModifications' => $hasModifications];
    }

    /**
     * A modification is safe to auto-apply when both sides are entirely one of the
     * stub's two conventions for meta/instructional text — full-line italic notes, or
     * a complete HTML comment block. Real content (bug entries, schema tables, decision
     * records) is never written as either in these files, so both are safe to always
     * take from the stub. Anything else (prose bullets, headings, real data) is left
     * for a human — those are exactly the shapes real project content also takes.
     *
     * @param  list<string>  $removed
     * @param  list<string>  $added
     */
    private function isSafeNoteModification(array $removed, array $added): bool
    {
        if ($removed === [] || $added === []) {
            return false;
        }

        if ($this->isAllItalicNotes($removed) && $this->isAllItalicNotes($added)) {
            return true;
        }

        return $this->isHtmlCommentBlock($removed) && $this->isHtmlCommentBlock($added);
    }

    /** @param  list<string>  $lines */
    private function isAllItalicNotes(array $lines): bool
    {
        foreach ($lines as $line) {
            if (! preg_match('/^_.*_$/', rtrim($line))) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $lines */
    private function isHtmlCommentBlock(array $lines): bool
    {
        $first = ltrim($lines[0]);
        $last = rtrim((string) end($lines));

        return str_starts_with($first, '<!--') && str_ends_with($last, '-->');
    }

    /**
     * Safe only when both lines have a trailing `  # comment` and the real content
     * before it is byte-identical — i.e. only the comment changed. Scoped to fenced
     * code lines by the caller, since `#` has no reserved meaning in prose and this
     * check alone can't tell a shell comment from, say, a literal issue reference.
     */
    private function isSafeInlineCommentModification(string $removedLine, string $addedLine): bool
    {
        $removedPrefix = $this->inlineCommentPrefix($removedLine);
        $addedPrefix = $this->inlineCommentPrefix($addedLine);

        return $removedPrefix !== null && $removedPrefix === $addedPrefix;
    }

    /** Everything before a whitespace-preceded `#`, or null if the line has none. */
    private function inlineCommentPrefix(string $line): ?string
    {
        if (preg_match('/^(.*\S)\s+#.*$/', rtrim($line), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Splices only the appliable hunks from $targetFile vs $stubFile into $targetFile,
     * in place. Returns true if anything was written.
     */
    private function patchScaffoldFile(string $targetFile, string $stubFile): bool
    {
        $diff = $this->diffAgainstStub($targetFile, $stubFile);

        if ($diff === null || $diff['appliableHunks'] === []) {
            return false;
        }

        $lines = explode("\n", (string) file_get_contents($targetFile));

        $hunks = $diff['appliableHunks'];
        usort($hunks, fn (array $a, array $b) => $b['start'] <=> $a['start']);

        foreach ($hunks as $hunk) {
            array_splice($lines, $hunk['start'], $hunk['count'], $hunk['lines']);
        }

        file_put_contents($targetFile, implode("\n", $lines));

        return true;
    }
}
