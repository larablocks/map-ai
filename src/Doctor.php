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

        foreach ((new Installer)->scaffoldOutOfDate($stubsPath, $targetPath) as $file) {
            $findings[] = [
                'id' => 'outdated-scaffold-file',
                'fixable' => false,
                'file' => $file,
                'message' => "Differs from the current template stub — merge in what's new by hand, never auto-applied.",
            ];
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
}
