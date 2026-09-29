<?php

use larablocks\MapAi\Doctor;
use larablocks\MapAi\Installer;

/*
 * End-to-end merge scenarios for the MAP docs: each one installs MAP into a real
 * git repo, seeds realistic content, lets two branches edit the docs the way AI
 * sessions do, and merges (or rebases) with the real driver — Claude pass off.
 * Most scenarios must merge without stopping; the few genuine disagreements
 * must stop with markers only around the disputed part.
 */

/** Edits MAP docs in a scenario repo by anchoring on text already in the file. */
final class ScenarioDocs
{
    public function __construct(public string $root) {}

    public function read(string $path): string
    {
        return (string) file_get_contents("$this->root/$path");
    }

    public function put(string $path, string $content): self
    {
        is_dir(dirname("$this->root/$path")) || mkdir(dirname("$this->root/$path"), 0755, true);
        file_put_contents("$this->root/$path", $content);

        return $this;
    }

    public function replace(string $path, string $from, string $to): self
    {
        $content = $this->read($path);
        expect($content)->toContain($from); // a typo in a scenario must not pass silently

        return $this->put($path, preg_replace('/'.preg_quote($from, '/').'/', addcslashes($to, '\\$'), $content, 1));
    }

    public function after(string $path, string $anchor, string $text): self
    {
        return $this->replace($path, $anchor, $anchor.$text);
    }

    public function before(string $path, string $anchor, string $text): self
    {
        return $this->replace($path, $anchor, $text.$anchor);
    }

    public function remove(string $path, string $text): self
    {
        return $this->replace($path, $text, '');
    }

    public function append(string $path, string $text): self
    {
        return $this->put($path, $this->read($path).$text);
    }
}

/**
 * A scenario repo with MAP installed and $seed committed on main. Returns a git
 * runner (Claude pass off) and the docs editor.
 *
 * @return array{0: Closure(string): string, 1: ScenarioDocs}
 */
function scenarioRepo(callable $seed): array
{
    $repo = sys_get_temp_dir().'/map-ai-scenario-'.uniqid();
    mkdir($repo, 0755, true);
    $git = fn (string $args) => (string) shell_exec('cd '.escapeshellarg($repo).' && MAP_MERGE_LLM=0 git -c user.name=t -c user.email=t@t '.$args.' 2>&1');
    $docs = new ScenarioDocs($repo);

    $git('init -q -b main');
    (new Installer)->install(Installer::stubsPath(), $repo);
    $seed($docs);
    scenarioCommit($git, 'seed');

    if (getenv('KEEP_SCENARIO')) { // debugging aid: KEEP_SCENARIO=1 vendor/bin/pest --filter=...
        fwrite(STDERR, "scenario repo kept at $repo\n");
    } else {
        register_shutdown_function(fn () => shell_exec('rm -rf '.escapeshellarg($repo)));
    }

    return [$git, $docs];
}

function scenarioCommit(Closure $git, string $message): void
{
    $git('add -A');
    $git('commit -qm '.escapeshellarg($message));
}

/**
 * Applies $commits (one callable, or a list for several commits) on the checked-out branch.
 *
 * @param  callable|list<callable>  $commits
 */
function scenarioWork(Closure $git, ScenarioDocs $docs, callable|array $commits, string $name): void
{
    foreach (is_array($commits) ? $commits : [$commits] as $i => $commit) {
        $commit($docs);
        scenarioCommit($git, "$name-$i");
    }
}

/** @return array{docs: ScenarioDocs, output: string, unmerged: list<string>, committed: bool} */
function scenarioState(Closure $git, ScenarioDocs $docs, string $output): array
{
    $unmerged = array_values(array_unique(array_map(
        fn ($line) => preg_split('/\s+/', trim($line), 4)[3],
        array_filter(explode("\n", $git('ls-files -u'))),
    )));
    sort($unmerged);
    $committed = $unmerged === [] && trim($git('status --porcelain')) === '' && ! is_dir($docs->root.'/.git/rebase-merge');

    return ['docs' => $docs, 'output' => $output, 'unmerged' => $unmerged, 'committed' => $committed];
}

/**
 * Runs one scenario. The first entry of $branches is committed on main
 * ("ours"); each later one on its own branch (b0, b1, ...) off the seed, merged
 * into main in order. Each entry is one callable or a list of them (one commit
 * each). With $mode 'rebase', b0 is rebased onto main instead.
 *
 * @param  list<callable|list<callable>>  $branches
 * @return array{docs: ScenarioDocs, output: string, unmerged: list<string>, committed: bool}
 */
function mapMergeScenario(callable $seed, array $branches, string $mode = 'merge'): array
{
    [$git, $docs] = scenarioRepo($seed);

    foreach (array_slice($branches, 1) as $i => $branch) {
        $git("checkout -q -b b$i main");
        scenarioWork($git, $docs, $branch, "b$i");
    }
    $git('checkout -q main');
    scenarioWork($git, $docs, $branches[0], 'ours');

    $output = '';
    if ($mode === 'rebase') {
        $git('checkout -q b0');
        $output = $git('rebase main');
    } else {
        foreach (array_keys(array_slice($branches, 1)) as $i) {
            $output .= $git("merge --no-edit b$i");
            if (trim($git('ls-files -u')) !== '') {
                break;
            }
        }
    }

    return scenarioState($git, $docs, $output);
}

function expectCleanMerge(array $result): void
{
    expect($result['unmerged'])->toBe([], $result['output']);
    expect($result['committed'])->toBeTrue($result['output']);
    foreach (glob($result['docs']->root.'/{,*/,.*/,docs/*/,docs/}*.md', GLOB_BRACE) as $file) {
        expect((string) file_get_contents($file))->not->toContain('<<<<<<<', $file);
    }
}

/** Markers in $path must enclose $inside and nothing listed in $outside. */
function expectConflictOnlyAround(array $result, string $path, string $inside, array $outside = []): void
{
    expect($result['unmerged'])->toBe([$path], $result['output']);
    $content = $result['docs']->read($path);
    preg_match_all('/^<{7} .*?^>{7} [^\n]*$/ms', $content, $hunks);
    expect(implode("\n", $hunks[0]))->toContain($inside);
    foreach ($outside as $text) {
        expect($content)->toContain($text);
        expect(implode("\n", $hunks[0]))->not->toContain($text);
    }
}

$bug = fn (int $n, string $title, string $severity = 'medium') => "\n### BUG-$n — $title (Agent-reported.)\n- **Discovered:** 2026-09-01 via test failure\n- **Affects:** app/Billing\n- **Severity:** $severity\n- **Description:** $title\n- **Blocking:** NONE\n- **Status:** open\n- **Test:** NONE\n";
$archived = fn (int $n, string $title, string $date) => "\n### BUG-$n — $date — $title (Verified.)\n**Was:** $title\n**Fix:** fixed\n**Commit/PR:** —\n";
$decision = fn (string $date, string $title, string $reasoning = 'because') => "\n### $date — $title\n**Decision:** $title\n**Alternatives considered:** none\n**Reasoning:** $reasoning\n**Consequences:** some\n";
$metrics = fn (string $date, int $tests, int $coverage) => "\n### $date\n| Metric | Value | Change since last entry |\n|---|---|---|\n| Test count | $tests | +0 |\n| Coverage | $coverage% | +0 pts |\n";

// A project mid-milestone: two open bugs, one decision, filled STATUS/coverage/glossary/etc.
$seedProject = function (ScenarioDocs $d) use ($bug, $decision, $metrics) {
    $d->replace('docs/STATUS.md', '_Last updated: YYYY-MM-DD by Claude_', '_Last updated: 2026-09-01 by Claude_')
        ->replace('docs/STATUS.md', "**[Current milestone or phase]** (active)\n[Remove or replace with your project's milestone/phase structure]", '**Milestone 2 — Billing** (active)')
        ->replace('docs/STATUS.md', '| Tests | ○ unknown | Not yet configured |', '| Tests | ✓ passing | 120 tests |')
        ->replace('docs/STATUS.md', '| Coverage | ○ unknown | See docs/TESTING_COVERAGE.md |', '| Coverage | ✓ 81% | See docs/TESTING_COVERAGE.md |')
        ->replace('docs/STATUS.md', '| Open bugs | ○ unknown | See docs/BUGS.md |', '| Open bugs | 2 | See docs/BUGS.md |')
        ->replace('docs/STATUS.md', "- [Project created]\n- [Initial structure committed]", "- Invoices table added\n- Invoice PDF export")
        ->replace('docs/STATUS.md', "1. [First meaningful task]\n2. [Following task]\n3. [Following task]", "1. Stripe webhooks\n2. Refunds\n3. Dunning emails")
        ->replace('docs/STATUS.md', '| Test count | 0 | — |', '| Test count | 120 | ↑ |')
        ->replace('docs/STATUS.md', '| Coverage | 0% | — |', '| Coverage | 81% | — |')
        ->replace('docs/STATUS.md', '| Open bugs | 0 | — |', '| Open bugs | 2 | — |');

    $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(1, 'Invoice total rounding').$bug(2, 'Timezone on due dates', 'low'));
    $d->append('docs/BUGS_ARCHIVE.md', "\n### BUG-0 — 2026-08-01 — Seed data missing (Verified.)\n**Was:** x\n**Fix:** y\n**Commit/PR:** —\n");
    $d->append('docs/ARCHITECTURE_HISTORY.md', $decision('2026-08-20', 'Invoices are immutable once issued'));
    $d->append('docs/METRICS_HISTORY.md', $metrics('2026-09-01', 120, 81));

    $d->replace('docs/TESTING_COVERAGE.md', '| Backend | [PHPUnit / Pest] | 0% | [N]% | ○ no data | — |', '| Backend | Pest | 81% | 80% | ✓ | 2026-09-01 |')
        ->replace('docs/TESTING_COVERAGE.md', '**Suite:** [N] tests passing, 0 failing ([duration with coverage]).', '**Suite:** 120 tests passing, 0 failing (8s).')
        ->replace('docs/TESTING_COVERAGE.md', '**Overall coverage:** 0% ([PCOV / Xdebug], measured [YYYY-MM-DD]).', '**Overall coverage:** 81% (PCOV, measured 2026-09-01).')
        ->replace('docs/TESTING_COVERAGE.md', "| `[path/to/file.php]` | 0% | `[none]` | [brief note — what's missing and why] |", "| `app/Billing/Invoice.php` | 80% | `[partial]` | rounding |\n| `app/Billing/Webhook.php` | 0% | `[none]` | not built |\n| `app/Billing/Refund.php` | 0% | `[none]` | not built |")
        ->replace('docs/TESTING_COVERAGE.md', "1. [Highest-priority gap — file, why it matters, what's blocking]\n2. [Next gap]\n3. [Integration-level files deferred pending a binary harness or external service]", "1. Invoice rounding paths\n2. Webhook handler\n3. Refund handler")
        ->replace('docs/TESTING_COVERAGE.md', '| — | — | — | — | — |', '| 2026-09-01 | Backend | 81% | 120 | 8s |');

    $d->replace('docs/GLOSSARY.md', '| [Term] | [Plain English definition — include how it is used specifically in this project] |', "| Dunning | Reminder emails for overdue invoices |\n| Invoice | A bill issued to a customer; immutable once issued |");
    $d->replace('docs/CODE_PATTERNS.md', '[Where business logic lives, how features are structured, what layer does what.]', '- Business logic lives in app/Actions, one class per use case');
    $d->replace('docs/SCHEMA.md', "## Tables\n", "## Tables\n\n### invoices\n- uuid id, customer_id, total_cents, issued_at\n");
    $d->replace('docs/SCHEMA.md', "[Which models relate to which, type of relationship, foreign key.\nFocus on non-obvious relationships or those deviating from conventions.]", '- Invoice belongsTo Customer (customer_id)');
    $d->after('docs/FEATURE_FLAGS.md', "| Flag | Purpose | Added | Remove when |\n|---|---|---|---|\n", "| pdf_v2 | New PDF renderer | 2026-08-01 | rollout done |\n| dunning | Dunning emails | 2026-08-15 | launched |\n");
    $d->replace('docs/COMMANDS.md', '| `[command]` | [category] | [yes/no] |', '| `./dev.sh seed` | Database | yes |');
    $d->replace('docs/ARCHITECTURE.md', '| [Name] | [What it does] | [What it controls] | [Explicit boundaries] |', '| Billing | Issues invoices | invoices table | payments |');
    $d->put('docs/memory/shared.md', $d->read('docs/memory/shared.example.md')."\n2026-08-02 — Pest parallel needs a DB per worker — use ParaTest tokens\n2026-08-10 — PDF fonts must be vendored — CI has none\n");
};

it('merges two full session ends without stopping — bugs, status, metrics, coverage', function () use ($seedProject, $bug, $metrics) {
    $session = fn (string $date, int $tests, int $coverage, int $openBugs, string $newBug, string $shipped, string $next, string $file) => function (ScenarioDocs $d) use ($date, $tests, $coverage, $openBugs, $newBug, $shipped, $next, $file, $bug, $metrics) {
        $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, $newBug, 'high'));
        $d->replace('docs/STATUS.md', '2026-09-01 by Claude', "$date by Claude")
            ->replace('docs/STATUS.md', '120 tests', "$tests tests")
            ->replace('docs/STATUS.md', '✓ 81%', "✓ $coverage%")
            ->replace('docs/STATUS.md', '| Open bugs | 2 | See', "| Open bugs | $openBugs | See")
            ->replace('docs/STATUS.md', '- Invoice PDF export', "- Invoice PDF export\n- $shipped")
            ->replace('docs/STATUS.md', "1. Stripe webhooks\n2. Refunds\n3. Dunning emails", $next)
            ->replace('docs/STATUS.md', '| Test count | 120 | ↑ |', "| Test count | $tests | ↑ |")
            ->replace('docs/STATUS.md', '| Coverage | 81% | — |', "| Coverage | $coverage% | ↑ |");
        $d->append('docs/METRICS_HISTORY.md', $metrics($date, $tests, $coverage));
        $d->replace('docs/TESTING_COVERAGE.md', '| Backend | Pest | 81% | 80% | ✓ | 2026-09-01 |', "| Backend | Pest | $coverage% | 80% | ✓ | $date |")
            ->replace('docs/TESTING_COVERAGE.md', '120 tests passing', "$tests tests passing")
            ->replace('docs/TESTING_COVERAGE.md', '81% (PCOV, measured 2026-09-01)', "$coverage% (PCOV, measured $date)")
            ->replace('docs/TESTING_COVERAGE.md', "| `app/Billing/$file.php` | 0% | `[none]` | not built |", "| `app/Billing/$file.php` | 90% | `[covered]` | — |")
            ->replace('docs/TESTING_COVERAGE.md', '| 2026-09-01 | Backend | 81% | 120 | 8s |', "| 2026-09-01 | Backend | 81% | 120 | 8s |\n| $date | Backend | $coverage% | $tests | 9s |");
    };

    $result = mapMergeScenario($seedProject, [
        $session('2026-09-10', 131, 83, 3, 'Webhook signature skew', 'Stripe webhooks', "1. Refunds\n2. Dunning emails\n3. Webhook retries", 'Webhook'),
        $session('2026-09-12', 128, 82, 3, 'Refund exceeds charge', 'Refunds API', "1. Stripe webhooks\n2. Dunning emails\n3. Partial refunds", 'Refund'),
    ]);

    expectCleanMerge($result);
    $d = $result['docs'];
    expect($result['output'])->toContain('renumbered one to BUG-4')->toContain('snapshot value(s)');
    expect($d->read('docs/BUGS.md'))->toContain('### BUG-3 — Webhook signature skew')->toContain('### BUG-4 — Refund exceeds charge');

    $status = $d->read('docs/STATUS.md');
    expect($status)->toContain('_Last updated: 2026-09-12 by Claude_')
        ->toContain('| Tests | ✓ passing | 128 tests |') // newer side's snapshot
        ->toContain("- Invoice PDF export\n- Stripe webhooks\n- Refunds API")
        ->toContain("1. Dunning emails\n2. Webhook retries\n3. Partial refunds");

    $coverage = $d->read('docs/TESTING_COVERAGE.md');
    expect($coverage)->toContain('| `app/Billing/Webhook.php` | 90% | `[covered]` | — |')
        ->toContain('| `app/Billing/Refund.php` | 90% | `[covered]` | — |')
        ->toContain('128 tests passing')
        ->toContain("| 2026-09-10 | Backend | 83% | 131 | 9s |\n| 2026-09-12 | Backend | 82% | 128 | 9s |");

    $history = $d->read('docs/METRICS_HISTORY.md');
    expect(strpos($history, '### 2026-09-10'))->toBeLessThan(strpos($history, '### 2026-09-12'));
});

it('keeps both archive moves when each branch fixes a different bug', function () use ($seedProject, $bug, $archived) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->remove('docs/BUGS.md', $bug(1, 'Invoice total rounding'))->append('docs/BUGS_ARCHIVE.md', $archived(1, 'Invoice total rounding', '2026-09-10')),
        fn (ScenarioDocs $d) => $d->remove('docs/BUGS.md', $bug(2, 'Timezone on due dates', 'low'))->append('docs/BUGS_ARCHIVE.md', $archived(2, 'Timezone on due dates', '2026-09-11')),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/BUGS.md'))->not->toContain('### BUG-1')->not->toContain('### BUG-2');
    expect($result['docs']->read('docs/BUGS_ARCHIVE.md'))->toContain('### BUG-1 — 2026-09-10')->toContain('### BUG-2 — 2026-09-11');
});

it('keeps a bug archived when the other branch added a new bug next to it', function () use ($seedProject, $bug, $archived) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->remove('docs/BUGS.md', $bug(2, 'Timezone on due dates', 'low'))->append('docs/BUGS_ARCHIVE.md', $archived(2, 'Timezone on due dates', '2026-09-10')),
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Refund exceeds charge')),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/BUGS.md'))->not->toContain('### BUG-2')->toContain('### BUG-3 — Refund exceeds charge');
});

it('stops only on the bug one branch archived while the other updated it', function () use ($seedProject, $bug, $archived) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->remove('docs/BUGS.md', $bug(1, 'Invoice total rounding'))->append('docs/BUGS_ARCHIVE.md', $archived(1, 'Invoice total rounding', '2026-09-10'))
            ->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Refund exceeds charge')),
        fn (ScenarioDocs $d) => $d->replace('docs/BUGS.md', "- **Description:** Invoice total rounding\n- **Blocking:** NONE\n- **Status:** open", "- **Description:** Invoice total rounding\n- **Blocking:** NONE\n- **Status:** investigating"),
    ]);

    expectConflictOnlyAround($result, 'docs/BUGS.md', '**Status:** investigating', ['### BUG-2 — Timezone', '### BUG-3 — Refund exceeds charge']);
});

it('renumbers across three branches that all took the next BUG-N', function () use ($seedProject, $bug) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Ours')),
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'First branch')),
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Second branch')),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/BUGS.md'))->toContain('### BUG-3 — Ours')->toContain('### BUG-4 — First branch')->toContain('### BUG-5 — Second branch');
});

it('never reuses a number a branch already archived when renumbering', function () use ($seedProject, $bug, $archived) {
    $result = mapMergeScenario($seedProject, [
        // ours found BUG-3 and already fixed + archived it
        fn (ScenarioDocs $d) => $d->append('docs/BUGS_ARCHIVE.md', $archived(3, 'Ours, already fixed', '2026-09-10'))->before('docs/BUGS.md', "\n## Fixed bugs", $bug(4, 'Ours, open')),
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Theirs')),
    ]);

    expectCleanMerge($result);
    $bugs = $result['docs']->read('docs/BUGS.md');
    expect($bugs)->toContain('### BUG-4 — Ours, open')->toContain('### BUG-5 — Theirs')->not->toContain('### BUG-3 — Theirs');
});

it('replays a rebased session onto main without stopping', function () use ($seedProject, $bug, $metrics) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'On main'))
            ->append('docs/METRICS_HISTORY.md', $metrics('2026-09-10', 131, 83))
            ->replace('docs/STATUS.md', '120 tests', '131 tests')->replace('docs/STATUS.md', '2026-09-01 by', '2026-09-10 by'),
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'On the feature branch'))
            ->append('docs/METRICS_HISTORY.md', $metrics('2026-09-12', 128, 82))
            ->replace('docs/STATUS.md', '120 tests', '128 tests')->replace('docs/STATUS.md', '2026-09-01 by', '2026-09-12 by'),
    ], 'rebase');

    expectCleanMerge($result);
    $d = $result['docs'];
    // Rebase replays the feature branch onto main, so main's entry is "ours" and keeps BUG-3.
    expect($d->read('docs/BUGS.md'))->toContain('### BUG-3 — On main')->toContain('### BUG-4 — On the feature branch');
    expect($d->read('docs/STATUS.md'))->toContain('128 tests')->toContain('2026-09-12 by');
    expect($d->read('docs/METRICS_HISTORY.md'))->toContain('### 2026-09-10')->toContain('### 2026-09-12');
});

it('keeps both decisions when two branches log one the same day — even with the same title', function () use ($seedProject, $decision) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->append('docs/ARCHITECTURE_HISTORY.md', $decision('2026-09-10', 'Queue webhooks on Redis', 'retries are free')),
        fn (ScenarioDocs $d) => $d->append('docs/ARCHITECTURE_HISTORY.md', $decision('2026-09-10', 'Refunds go through a ledger').$decision('2026-09-10', 'Queue webhooks on Redis', 'Horizon dashboards')),
    ]);

    expectCleanMerge($result);
    $history = $result['docs']->read('docs/ARCHITECTURE_HISTORY.md');
    expect(substr_count($history, '### 2026-09-10 — Queue webhooks on Redis'))->toBe(2);
    expect($history)->toContain('retries are free')->toContain('Horizon dashboards');
    expect($history)->toContain('### 2026-09-10 — Refunds go through a ledger')->toContain('### 2026-08-20 — Invoices are immutable');
});

it('merges glossary terms, code patterns, schema tables and relationships added on both branches', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        function (ScenarioDocs $d) {
            $d->after('docs/GLOSSARY.md', "| Dunning | Reminder emails for overdue invoices |\n", "| Grace period | Days before dunning starts |\n");
            $d->after('docs/CODE_PATTERNS.md', '- Business logic lives in app/Actions, one class per use case', "\n- Webhooks are verified in middleware, never in the controller");
            $d->after('docs/SCHEMA.md', "- uuid id, customer_id, total_cents, issued_at\n", "\n### webhook_events\n- uuid id, stripe_id unique, payload json\n");
            $d->after('docs/SCHEMA.md', '- Invoice belongsTo Customer (customer_id)', "\n- WebhookEvent morphTo subject");
        },
        function (ScenarioDocs $d) {
            $d->after('docs/GLOSSARY.md', "| Invoice | A bill issued to a customer; immutable once issued |\n", "| Refund | Money returned against a charge |\n");
            $d->after('docs/CODE_PATTERNS.md', '- Business logic lives in app/Actions, one class per use case', "\n- Money is integer cents, never floats");
            $d->after('docs/SCHEMA.md', "- uuid id, customer_id, total_cents, issued_at\n", "\n### refunds\n- uuid id, invoice_id, amount_cents\n");
            $d->after('docs/SCHEMA.md', '- Invoice belongsTo Customer (customer_id)', "\n- Refund belongsTo Invoice (invoice_id)");
        },
    ]);

    expectCleanMerge($result);
    $d = $result['docs'];
    expect($d->read('docs/GLOSSARY.md'))->toContain('| Grace period |')->toContain('| Refund |')->toContain('| Dunning |')->toContain('| Invoice |');
    expect($d->read('docs/CODE_PATTERNS.md'))->toContain('- Webhooks are verified in middleware')->toContain('- Money is integer cents');
    expect($d->read('docs/SCHEMA.md'))->toContain('### webhook_events')->toContain('### refunds')->toContain('- WebhookEvent morphTo subject')->toContain('- Refund belongsTo Invoice');
});

it('stops only on a glossary term both branches defined differently', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->replace('docs/GLOSSARY.md', '| Dunning | Reminder emails for overdue invoices |', '| Dunning | Automated reminder sequence for overdue invoices |')
            ->after('docs/GLOSSARY.md', "| Invoice | A bill issued to a customer; immutable once issued |\n", "| Ledger | Append-only money log |\n"),
        fn (ScenarioDocs $d) => $d->replace('docs/GLOSSARY.md', '| Dunning | Reminder emails for overdue invoices |', '| Dunning | Collections process for unpaid invoices |'),
    ]);

    expectConflictOnlyAround($result, 'docs/GLOSSARY.md', 'Collections process', ['| Ledger | Append-only money log |']);
});

it('merges feature flags added on one branch and retired on the other', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->after('docs/FEATURE_FLAGS.md', "| dunning | Dunning emails | 2026-08-15 | launched |\n", "| refunds_v1 | Refunds API | 2026-09-10 | GA |\n"),
        fn (ScenarioDocs $d) => $d->remove('docs/FEATURE_FLAGS.md', "| pdf_v2 | New PDF renderer | 2026-08-01 | rollout done |\n")
            ->after('docs/FEATURE_FLAGS.md', "| Flag | Removed | Reason |\n|---|---|---|\n", "| pdf_v2 | 2026-09-11 | rolled out |\n"),
    ]);

    expectCleanMerge($result);
    $flags = $result['docs']->read('docs/FEATURE_FLAGS.md');
    [$active, $removed] = explode('## Removed flags', $flags);
    expect($active)->toContain('| refunds_v1 |')->toContain('| dunning |')->not->toContain('| pdf_v2 |');
    expect($removed)->toContain('| pdf_v2 | 2026-09-11 | rolled out |');
});

it('merges commands, components and shared memory added on both branches', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        function (ScenarioDocs $d) {
            $d->after('docs/COMMANDS.md', "| `./dev.sh seed` | Database | yes |\n", "| `./dev.sh stripe:listen` | Payments | no |\n");
            $d->after('docs/ARCHITECTURE.md', "| Billing | Issues invoices | invoices table | payments |\n", "| Webhooks | Ingests Stripe events | webhook_events | billing state |\n");
            $d->append('docs/memory/shared.md', "2026-09-10 — Stripe CLI drops events on sleep — restart listener\n");
        },
        function (ScenarioDocs $d) {
            $d->after('docs/COMMANDS.md', "| `./dev.sh seed` | Database | yes |\n", "| `./dev.sh refunds:replay` | Payments | yes |\n");
            $d->after('docs/ARCHITECTURE.md', "| Billing | Issues invoices | invoices table | payments |\n", "| Refunds | Returns money | refunds | charges |\n");
            // at the token cap: drop the least actionable entry, add the new one
            $d->remove('docs/memory/shared.md', "2026-08-10 — PDF fonts must be vendored — CI has none\n");
            $d->append('docs/memory/shared.md', "2026-09-12 — Refund webhooks arrive before the API response — make handlers idempotent\n");
        },
    ]);

    expectCleanMerge($result);
    $d = $result['docs'];
    expect($d->read('docs/COMMANDS.md'))->toContain('stripe:listen')->toContain('refunds:replay')->toContain('./dev.sh seed');
    expect($d->read('docs/ARCHITECTURE.md'))->toContain('| Webhooks |')->toContain('| Refunds |')->toContain('| Billing |');
    expect($d->read('docs/memory/shared.md'))->toContain('Stripe CLI drops')->toContain('Refund webhooks arrive')->toContain('Pest parallel')->not->toContain('PDF fonts');
});

it('merges a rolling "last N" progress list both branches trimmed and appended to', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->replace('docs/STATUS.md', "- Invoices table added\n- Invoice PDF export", "- Invoice PDF export\n- Stripe webhooks"),
        fn (ScenarioDocs $d) => $d->replace('docs/STATUS.md', "- Invoices table added\n- Invoice PDF export", "- Invoice PDF export\n- Refunds API"),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/STATUS.md'))->toContain("## Last meaningful progress\n- Invoice PDF export\n- Stripe webhooks\n- Refunds API\n");
});

it('merges the coverage to-do list both branches worked through', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->replace('docs/TESTING_COVERAGE.md', "1. Invoice rounding paths\n2. Webhook handler\n3. Refund handler", "1. Invoice rounding paths\n2. Refund handler\n3. Webhook retries"),
        fn (ScenarioDocs $d) => $d->replace('docs/TESTING_COVERAGE.md', "1. Invoice rounding paths\n2. Webhook handler\n3. Refund handler", "1. Webhook handler\n2. Refund handler\n3. Partial refunds"),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/TESTING_COVERAGE.md'))->toContain("1. Refund handler\n2. Webhook retries\n3. Partial refunds\n");
});

it('keeps both instructions when two branches add project hard rules', function () use ($seedProject) {
    $placeholder = "[Project-specific hard rules that don't fit the generic list above — e.g. \"never call the production billing API from a dev session.\" Delete this line once real rules are added; leave the section empty if there are none yet.]";
    $result = mapMergeScenario(function (ScenarioDocs $d) use ($seedProject, $placeholder) {
        $seedProject($d);
        $d->replace('AGENTS.md', $placeholder, '- Never call the live Stripe API from tests');
    }, [
        fn (ScenarioDocs $d) => $d->after('AGENTS.md', '- Never call the live Stripe API from tests', "\n- Money is always integer cents"),
        fn (ScenarioDocs $d) => $d->after('AGENTS.md', '- Never call the live Stripe API from tests', "\n- Never edit issued invoices"),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('AGENTS.md'))->toContain("- Never call the live Stripe API from tests\n- Money is always integer cents\n- Never edit issued invoices");
});

it('keeps both QA files and both sections when branches write QA notes', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->put('docs/qa/ABC-1.md', "# QA — ABC-1 webhooks\n\n## Steps\n1. Send test event\n"),
        fn (ScenarioDocs $d) => $d->put('docs/qa/ABC-2.md', "# QA — ABC-2 refunds\n\n## Steps\n1. Refund an invoice\n"),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/qa/ABC-1.md'))->toContain('Send test event');
    expect($result['docs']->read('docs/qa/ABC-2.md'))->toContain('Refund an invoice');
});

it('stops only on the current phase when both branches rewrote it differently', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->replace('docs/STATUS.md', '**Milestone 2 — Billing** (active)', '**Milestone 3 — Payments** (active)')
            ->replace('docs/STATUS.md', '120 tests', '131 tests')->replace('docs/STATUS.md', '2026-09-01 by', '2026-09-10 by'),
        fn (ScenarioDocs $d) => $d->replace('docs/STATUS.md', '**Milestone 2 — Billing** (active)', '**Milestone 2 — Billing** (wrapping up)')
            ->replace('docs/STATUS.md', '120 tests', '128 tests')->replace('docs/STATUS.md', '2026-09-01 by', '2026-09-12 by'),
    ]);

    expectConflictOnlyAround($result, 'docs/STATUS.md', 'Milestone 3 — Payments', ['128 tests', '_Last updated: 2026-09-12 by Claude_']);
});

it('renumbers across files during a rebase too', function () use ($seedProject, $bug, $archived) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->append('docs/BUGS_ARCHIVE.md', $archived(3, 'On main, already fixed', '2026-09-10'))
            ->before('docs/BUGS.md', "\n## Fixed bugs", $bug(4, 'On main, open')),
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'On the feature branch')),
    ], 'rebase');

    expectCleanMerge($result);
    expect($result['docs']->read('docs/BUGS.md'))->toContain('### BUG-4 — On main, open')->toContain('### BUG-5 — On the feature branch');
    expect($result['output'])->toContain('already uses BUG-3 in docs/BUGS_ARCHIVE.md');
});

it('flags a BUG-N clash the driver never sees, and --fix-bugs renumbers the open copy', function (string $mode) use ($seedProject, $bug, $archived) {
    // Each branch touched only one bug file, so git never runs the driver on either.
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->append('docs/BUGS_ARCHIVE.md', $archived(3, 'On main, already fixed', '2026-09-10')),
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'On the feature branch'))
            ->put('docs/qa/ABC-9.md', "# QA — ABC-9\n\nCovers BUG-3.\n"),
    ], $mode);
    expectCleanMerge($result);
    $root = $result['docs']->root;

    expect(Doctor::duplicateBugNumbers($root))->toBe([3 => ['docs/BUGS_ARCHIVE.md', 'docs/BUGS.md']]);
    $findings = array_values(array_filter((new Doctor)->check(Installer::stubsPath(), $root), fn ($f) => $f['id'] === 'duplicate-bug-number'));
    expect($findings)->toHaveCount(1);
    expect($findings[0]['fixable'])->toBeFalse();

    $hook = (string) shell_exec('cd '.escapeshellarg($root).' && CLAUDE_PROJECT_DIR=. bash .claude/hooks/map-first-run-check.sh');
    $context = json_decode($hook, true)['hookSpecificOutput']['additionalContext'] ?? '';
    expect($context)->toContain('MAP duplicate bug numbers: BUG-3')->toContain('--fix-bugs');

    exec('cd '.escapeshellarg($root).' && bash .map/merge.sh --check-bugs 2>&1', $out, $exit);
    expect($exit)->toBe(1);

    $fixed = (string) shell_exec('cd '.escapeshellarg($root).' && bash .map/merge.sh --fix-bugs 2>&1');
    expect($fixed)->toContain('renumbered it to BUG-4 in docs/BUGS.md');
    expect($result['docs']->read('docs/BUGS.md'))->toContain('### BUG-4 — On the feature branch');
    expect($result['docs']->read('docs/BUGS_ARCHIVE.md'))->toContain('### BUG-3 — 2026-09-10 — On main, already fixed');
    expect(Doctor::duplicateBugNumbers($root))->toBe([]);
})->with(['merge', 'rebase']);

it('does not renumber a bug the other branch moved to the archive', function () use ($seedProject, $bug, $archived) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->append('docs/BUGS_ARCHIVE.md', "\n<!-- archive reviewed 2026-09-10 -->\n")->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Ours')),
        fn (ScenarioDocs $d) => $d->remove('docs/BUGS.md', $bug(2, 'Timezone on due dates', 'low'))->append('docs/BUGS_ARCHIVE.md', $archived(2, 'Timezone on due dates', '2026-09-11')),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/BUGS_ARCHIVE.md'))->toContain('### BUG-2 — 2026-09-11 — Timezone on due dates');
    expect($result['docs']->read('docs/BUGS.md'))->not->toContain('### BUG-2')->toContain('### BUG-3 — Ours');
    expect($result['output'])->not->toContain('renumbered');
});

it('stops rather than renumber when both branches archived the same bug', function () use ($seedProject, $bug, $archived) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->remove('docs/BUGS.md', $bug(1, 'Invoice total rounding'))->append('docs/BUGS_ARCHIVE.md', $archived(1, 'Invoice total rounding', '2026-09-10')),
        fn (ScenarioDocs $d) => $d->remove('docs/BUGS.md', $bug(1, 'Invoice total rounding'))->append('docs/BUGS_ARCHIVE.md', $archived(1, 'Invoice total rounding', '2026-09-12')),
    ]);

    // One bug, fixed twice: renumbering either copy would invent a bug that never existed.
    expect($result['output'])->not->toContain('renumbered');
    expect($result['unmerged'])->toBe(['docs/BUGS_ARCHIVE.md']);
    expect($result['docs']->read('docs/BUGS.md'))->not->toContain('### BUG-1');
});

it('follows a renumbered bug when the branch keeps working on it and merges again', function () use ($seedProject, $bug) {
    [$git, $docs] = scenarioRepo($seedProject);
    $git('checkout -q -b feature');
    scenarioWork($git, $docs, fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Refund exceeds charge')), 'feature');
    $git('checkout -q main');
    scenarioWork($git, $docs, fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'Webhook signature skew')), 'main');
    $first = $git('merge --no-edit feature');
    expect($first)->toContain('renumbered one to BUG-4');

    // The feature branch still calls it BUG-3 and updates its status.
    $git('checkout -q feature');
    scenarioWork($git, $docs, fn (ScenarioDocs $d) => $d->replace('docs/BUGS.md', "- **Description:** Refund exceeds charge\n- **Blocking:** NONE\n- **Status:** open", "- **Description:** Refund exceeds charge\n- **Blocking:** NONE\n- **Status:** investigating"), 'feature-2');
    $git('checkout -q main');
    $result = scenarioState($git, $docs, $git('merge --no-edit feature'));

    expectCleanMerge($result);
    $bugs = $docs->read('docs/BUGS.md');
    expect($bugs)->toContain('### BUG-3 — Webhook signature skew')->toContain('### BUG-4 — Refund exceeds charge')->not->toContain('### BUG-3 — Refund');
    expect(substr($bugs, strpos($bugs, '### BUG-4 — Refund exceeds charge'), 300))->toContain('**Status:** investigating');
    expect(substr($bugs, strpos($bugs, '### BUG-3 — Webhook'), 300))->toContain('**Status:** open');
});

it('follows a renumbered bug through the later commits of a rebase', function () use ($seedProject, $bug) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'On main')),
        [
            fn (ScenarioDocs $d) => $d->before('docs/BUGS.md', "\n## Fixed bugs", $bug(3, 'On the feature branch')),
            fn (ScenarioDocs $d) => $d->replace('docs/BUGS.md', "- **Description:** On the feature branch\n- **Blocking:** NONE\n- **Status:** open", "- **Description:** On the feature branch\n- **Blocking:** NONE\n- **Status:** investigating"),
        ],
    ], 'rebase');

    expectCleanMerge($result);
    $bugs = $result['docs']->read('docs/BUGS.md');
    expect($bugs)->toContain('### BUG-3 — On main')->toContain('### BUG-4 — On the feature branch');
    expect(substr($bugs, strpos($bugs, '### BUG-4 — On the feature branch'), 300))->toContain('**Status:** investigating');
});

it('keeps both QA notes when both branches wrote the same QA file', function () use ($seedProject) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->put('docs/qa/ABC-1.md', "# QA — ABC-1\n\n## Webhooks\n1. Send test event\n"),
        fn (ScenarioDocs $d) => $d->put('docs/qa/ABC-1.md', "# QA — ABC-1\n\n## Refunds\n1. Refund an invoice\n"),
    ]);

    expectCleanMerge($result);
    expect($result['docs']->read('docs/qa/ABC-1.md'))->toBe("# QA — ABC-1\n\n## Webhooks\n1. Send test event\n\n## Refunds\n1. Refund an invoice\n");
});

it('copes with docs saved without a trailing newline', function () use ($seedProject, $decision) {
    $result = mapMergeScenario($seedProject, [
        fn (ScenarioDocs $d) => $d->put('docs/ARCHITECTURE_HISTORY.md', rtrim($d->read('docs/ARCHITECTURE_HISTORY.md').$decision('2026-09-10', 'Ours'))),
        fn (ScenarioDocs $d) => $d->put('docs/ARCHITECTURE_HISTORY.md', rtrim($d->read('docs/ARCHITECTURE_HISTORY.md').$decision('2026-09-12', 'Theirs'))),
    ]);

    expectCleanMerge($result);
    $history = $result['docs']->read('docs/ARCHITECTURE_HISTORY.md');
    expect($history)->toContain("**Consequences:** some\n\n### 2026-09-12 — Theirs");
    expect(strpos($history, '### 2026-09-10 — Ours'))->toBeLessThan(strpos($history, '### 2026-09-12 — Theirs'));
});

it('merges a large bug archive quickly', function () use ($seedProject, $archived) {
    $seed = function (ScenarioDocs $d) use ($seedProject, $archived) {
        $seedProject($d);
        $entries = '';
        for ($n = 10; $n < 410; $n++) {
            $entries .= $archived($n, "Old bug $n", '2026-01-01');
        }
        $d->append('docs/BUGS_ARCHIVE.md', $entries);
    };

    $start = microtime(true);
    $result = mapMergeScenario($seed, [
        fn (ScenarioDocs $d) => $d->append('docs/BUGS_ARCHIVE.md', $archived(1, 'Invoice total rounding', '2026-09-10'))->replace('docs/BUGS_ARCHIVE.md', '**Was:** Old bug 200', '**Was:** Old bug 200, see also BUG-1'),
        fn (ScenarioDocs $d) => $d->append('docs/BUGS_ARCHIVE.md', $archived(2, 'Timezone on due dates', '2026-09-11'))->replace('docs/BUGS_ARCHIVE.md', '**Was:** Old bug 300', '**Was:** Old bug 300, see also BUG-2'),
    ]);
    $seconds = microtime(true) - $start;

    expectCleanMerge($result);
    expect($result['docs']->read('docs/BUGS_ARCHIVE.md'))->toContain('see also BUG-1')->toContain('see also BUG-2')->toContain('### BUG-2 — 2026-09-11');
    expect($seconds)->toBeLessThan(5.0);
    fwrite(STDERR, sprintf("large archive merge: %.1fs\n", $seconds));
});
