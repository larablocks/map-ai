# map-ai

Framework-agnostic core for the **MAP** documentation scaffold. Contains the stubs and installer logic used by framework-specific packages.

## What is MAP?

MAP stands for **Markdown for AI Processing** — a structured Markdown convention designed to make project content predictable, chunkable, and easily consumable by AI systems (LLMs, embeddings, agents).

MAP is a structured set of markdown files that AI coding agents read at session start. It gives every session — with any tool — a reliable starting point: what the project is, how to run it, what's been decided and why, where to pick up from last time, and accumulated knowledge about what doesn't work.

Without it, each AI session starts from zero. With it, sessions start informed.

## What you gain

### The AI remembers what it learned

`docs/memory/` files accumulate project-specific knowledge across sessions — gotchas, database quirks, testing patterns, environment surprises. The AI reads these at startup and doesn't repeat mistakes it's already made on your project.

`docs/memory/shared.md` is committed to the repo, so every developer's AI sessions share the same team-level learnings.

### The AI knows your project from the first message

`AGENTS.md` tells every tool your stack, your test and build commands, and what files to read for what purpose. The AI doesn't ask what framework you're on or how to run tests — it already knows.

### Decisions don't get lost

`docs/ARCHITECTURE_HISTORY.md` is append-only. Every significant architectural decision is recorded with its alternatives and reasoning. When a decision gets revisited months later, the AI can explain why it was made the first time.

### Bugs stay visible

`docs/BUGS.md` is a live list the AI maintains automatically — appended on discovery, moved to `docs/BUGS_ARCHIVE.md` on fix. Known issues don't disappear from context at session end.

Both files carry `BUG-N` IDs and are merged by MAP's own git merge driver (see [Merging MAP docs](#merging-map-docs)), so two branches adding entries at the same time combine cleanly instead of producing conflict markers, a bug one branch moved to the archive stays moved, and if two branches independently assign the same `BUG-N` the incoming one is renumbered to the next free number.

### Security and testing standards apply every session

`.claude/rules/security.md` and `.claude/rules/testing.md` load automatically each Claude Code session. The AI follows your coverage requirements and security practices without being reminded.

### Compliance obligations are project-specific, not generic

`docs/COMPLIANCE.md` documents this project's actual regulatory obligations — data classification, retention, audit requirements, whatever frameworks apply (GDPR, HIPAA, SOC2…). It's loaded whenever the AI touches sensitive data, exports, deletions, or third-party integrations, and such actions require explicit confirmation before proceeding. Editing the file itself is a separate, similarly gated action — Claude may propose changes but never writes them without developer approval, and some changes need more than a verbal yes (see the file's own "Constraints on AI-assisted changes" section). This is distinct from `.claude/rules/security.md`, which only covers generic secret hygiene.

### Leadership gets a numbers trend, not just a snapshot

`docs/STATUS.md` shows current health; `docs/METRICS_HISTORY.md` is the append-only log behind it — one dated entry per session end (test count, coverage, bug counts, milestone progress vs. the previous entry). Non-engineers can skim it for a trend line without reading commit history or code.

### One source of truth, four tools

MAP works with Claude Code, Gemini CLI, GitHub Copilot, and Cursor. Each tool gets its own entry point file (`CLAUDE.md`, `GEMINI.md`, `.cursor/rules/agents.mdc`, `.github/copilot-instructions.md`), but they all point at — or inline — the same `AGENTS.md`. Update one file, all tools stay in sync.

### Personal overrides without team noise

`CLAUDE.local.md` is gitignored. Each developer can add personal preferences for their machine without affecting anyone else's sessions.

---

## Documentation that writes itself

MAP defines a set of **write rules** — declarative triggers built into `AGENTS.md` that tell the AI exactly what to write, where, and when. The AI follows them immediately without being asked, as a side effect of normal work:

| When the AI discovers… | It writes to… |
|---|---|
| A bug (from any source) | `docs/BUGS.md` — appended immediately |
| A bug is fixed and verified | Entry moved to `docs/BUGS_ARCHIVE.md` |
| A hard-to-reverse architectural decision | `docs/ARCHITECTURE_HISTORY.md` — decision, alternatives considered, and reasoning |
| A new project-specific pattern | `docs/CODE_PATTERNS.md` — checked first to avoid duplication |
| A new domain term or abbreviation | `docs/GLOSSARY.md` |
| Surprising behaviour (framework, DB, tests, environment) | `docs/memory/[topic].md` — routed by subject |
| Time wasted on a mistake | `docs/memory/gotchas.md` — capped at ~750 tokens, least-actionable removed when full |
| A schema change | `docs/SCHEMA.md` — updated immediately |
| An architecture change | `docs/ARCHITECTURE.md` — updated to reflect current state |
| Tests added or coverage run | `docs/TESTING_COVERAGE.md` — from actual output, never estimated |
| Session end | `docs/METRICS_HISTORY.md` — dated entry appended, current metrics vs. the previous one |

Writes happen immediately — not deferred to session end, not optional. When the AI finds a bug mid-task, it appends to `BUGS.md` before continuing. When it makes an architectural call, it records the decision and reasoning before moving on. The priority order is fixed: `BUGS.md` first, `ARCHITECTURE_HISTORY.md` second, everything else after.

At session end the AI updates `docs/STATUS.md` with current project health and routes everything learned during the session to the appropriate `docs/memory/` file.

The result is documentation that reflects what is actually true about the project right now — maintained continuously as a side effect of development work, not as a separate task someone needs to remember to do.

---

## Designed for lean context

Every working file in MAP has a size ceiling enforced by the AI's own write rules (the deliberate exceptions are the append-only logs — `ARCHITECTURE_HISTORY.md`, `BUGS_ARCHIVE.md`, `STATUS_ARCHIVE.md`, and `METRICS_HISTORY.md` — which have no size limit and are never summarised; see below). `AGENTS.md` stays under 3,000 tokens (estimated as bytes ÷ 4) — measured in tokens, not lines, because it loads every session and one long line costs as much as many short ones. `docs/memory/gotchas.md` caps at ~750 tokens and `docs/memory/shared.md` at ~1,500 (both load every session). Other memory topic files cap at ~2,500 tokens. In Claude Code, `.claude/hooks/map-token-check.sh` enforces all of these: at session start, and immediately after any edit that pushes a capped file over its cap. When a file fills up, the AI summarises or removes before adding — so files stay dense and high-signal rather than growing without bound.

Beyond size caps, the structure itself controls what gets loaded:

**Selective loading, not full context at startup.** `AGENTS.md`'s "Load when relevant" section tells the AI which files to read for which tasks. A session fixing a bug doesn't load `ARCHITECTURE.md` or `SCHEMA.md`. A session touching the database doesn't load `DOCKER.md`. Files are pulled on demand. That's why those lines use plain paths: an `@docs/...` import in Claude Code or Gemini CLI loads the file at session start no matter what the rule says. Only the session start ritual's files (`STATUS.md`, `MEMORY.md`, `BUGS.md`) and `CLAUDE.local.md` are `@`-imported.

**Index before content.** `MEMORY.md` is a one-page index — a table of topic files and entry counts. The AI reads it first to know what knowledge exists, then loads only the topic file relevant to the current task. `docs/memory/database.md` is never loaded during a UI fix.

**History separated from current state.** `ARCHITECTURE_HISTORY.md` has no size limit and is never summarised — it grows for the life of the project so no decision's reasoning is ever lost; `ARCHITECTURE.md` stays a concise snapshot of current structure. You pay for historical decision tokens only when a decision is actively being revisited.

**Write-on-discovery keeps future context accurate.** The AI writes to docs immediately when it finds something rather than waiting until session end. Accurate docs mean future sessions don't waste tokens working from stale context or asking clarifying questions they shouldn't need to ask.

The result: sessions start faster because the AI isn't loading irrelevant context, and the token cost per session stays proportional to the actual scope of the work.

---

## Installation

Pick the package for your stack — each one wraps this core package (the stub files plus the shared install/patch logic) in a one-command install with stack-aware auto-detection baked in: project name, framework, and test/build/start commands get read straight from your project's own manifest and written into `AGENTS.md`, rather than left as `[...]` placeholders. **Prefer these over installing this package directly** — the platform wrapper is strictly the better experience, and none of them reimplement MAP's diff/patch logic, they all call this package's `install.sh`/`doctor.sh` (or the PHP `Installer`/`Doctor` classes) underneath.

| Stack | Package | Install |
|---|---|---|
| Laravel | [`map-ai-laravel`](https://github.com/larablocks/map-ai-laravel) | `composer require larablocks/map-ai-laravel && php artisan map:install` |
| React, Vue, Next.js, Nuxt, Angular, plain Node/TypeScript | [`map-ai-js`](https://github.com/larablocks/map-ai-js) | `npx map-ai-js install` |
| Django, Flask, FastAPI, plain Python | [`map-ai-py`](https://github.com/larablocks/map-ai-py) | `pipx run map-ai-py install` (or `uvx map-ai-py install`) |

### Laravel

```bash
composer require larablocks/map-ai-laravel
php artisan map:install
```

`map:install` copies the scaffold into your project root (see [What gets installed](#what-gets-installed) below), merges the required entries into `.gitignore`/`.gitattributes`, and auto-detects project name/stack/commands from `composer.json`/`package.json`/`.env.example` to fill in `AGENTS.md`. Files that already exist are left alone unless you pass `--force`, which overwrites `SCAFFOLD_FILES` after backing each one up to `<file>.bak`.

```bash
php artisan map:install --force
```

### React, Vue, Next.js, Nuxt, Angular, or plain Node/TypeScript

```bash
npx map-ai-js install
```

Same install behavior as Laravel's, with auto-detection reading `package.json` instead — recognizes Next.js, Nuxt, SvelteKit, Remix, Astro, Angular, NestJS, React, Vue, Svelte, Fastify, and Express, plus TypeScript/JavaScript and common data-layer dependencies (Drizzle, Prisma, MongoDB, Postgres, MySQL, SQLite, Redis). See [`map-ai-js`'s README](https://github.com/larablocks/map-ai-js) for the full detection table and `doctor` usage.

### Django, Flask, FastAPI, or plain Python

```bash
pipx run map-ai-py install
# or
uvx map-ai-py install
```

Same behavior again, reading `pyproject.toml` (or `requirements.txt` as a fallback). Start/build command detection is intentionally weaker than the JS/PHP versions — Python has no `package.json`-scripts equivalent, so those two fall back to a `[MANUAL]` placeholder more often; everything else detects the same way. See [`map-ai-py`'s README](https://github.com/larablocks/map-ai-py) for specifics.

### Any other PHP project

No dedicated wrapper exists yet for Symfony, WordPress, or other non-Laravel PHP frameworks. Require this package directly and run the installer script that ships with it:

```bash
composer require larablocks/map-ai
bash vendor/larablocks/map-ai/install.sh /path/to/your/project
```

Add `--force` to the end of that command to overwrite existing `SCAFFOLD_FILES` (backed up to `<file>.bak` first). `bash` is used explicitly rather than `./install.sh` because Composer-installed vendor files aren't guaranteed to keep their executable bit.

Prefer to drive it from PHP instead of the shell? Call the `Installer` class directly — see [For package authors](#for-package-authors) below for the exact API.

### Any other stack entirely

`install.sh`/`doctor.sh` need nothing but `bash` — no PHP, Node, or Python required to run them. Clone this repo and point them at your project directly:

```bash
git clone https://github.com/larablocks/map-ai.git /tmp/map-ai
bash /tmp/map-ai/install.sh /path/to/your/project
```

If you're doing this for more than one project in your ecosystem, it's worth building a proper wrapper instead — see [Want a wrapper for another framework?](#want-a-wrapper-for-another-framework) below.

### After installing

1. Review `AGENTS.md` — fill in any remaining `[...]` placeholders (project name, stack, commands) that couldn't be auto-detected.
2. Installing already bootstrapped each developer's gitignored `docs/memory/*.md` files from their `.example.md` counterpart (see `docs/SETUP.md` step 3 for the manual `cp` commands, only needed to review/edit one before first use, or to run the stack-specific `framework.example.md` rename — that one file is never auto-bootstrapped, since the target filename is project-specific).

### Want a wrapper for another framework?

Two patterns, depending on your ecosystem:

- **PHP frameworks** (Symfony, WordPress, etc.): require this core package and call `Installer::install()` directly, the same way `map-ai-laravel` does. See [For package authors](#for-package-authors) below for the exact API.
- **Everything else**: vendor a copy of `install.sh`/`doctor.sh`/`lib.sh`/`stubs/` from this repo into your package and shell out to them, rather than porting the diff/patch logic to another language — the same approach [`map-ai-js`](https://github.com/larablocks/map-ai-js) and [`map-ai-py`](https://github.com/larablocks/map-ai-py) both use. Add only what's genuinely native to your ecosystem: reading its manifest format (`package.json`, `pyproject.toml`, `Cargo.toml`, `go.mod`, whatever's standard) to auto-fill `AGENTS.md`'s placeholders. Both of those packages' `src/detect.js`/`detect.py` are a reasonable reference for the shape that takes.

---

## Merging MAP docs

MAP docs are edited on every branch, so they conflict on merge more than most files. MAP ships a git merge driver, `.map/merge.sh`, and `.gitattributes` routes every file an AI agent writes to through it: all of `docs/` (including the `agents|api|architecture|integrations|qa/` folders and `docs/memory/shared.md`), plus `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md`, and `.claude/rules/*.md`.

Each conflicting file goes through up to three passes. Each pass only touches what the one before couldn't settle:

1. **Git's own merge.** If git merges the file cleanly, that result is used unchanged. The only addition is renumbering duplicate `BUG-N`s, below. One check comes first. Git matches identical lines, and MAP entries repeat the same field lines (`- **Status:** open`), so a "clean" merge can land an edit in the wrong entry. For example, one branch archives BUG-1 while the other updates BUG-1's status, and git applies that status change to BUG-2. So the result is only used if every entry neither branch touched comes out unchanged, and every entry one branch changed comes out as that branch's version. Otherwise the file goes to pass 2.
2. **Deterministic rules.** Each side is split into blocks at markdown headings (headings inside code fences and HTML comments don't count) and merged block by block:
   - two new entries under different headings are both kept, ours first;
   - an entry deleted on one side and untouched on the other stays deleted, so a bug moved to `BUGS_ARCHIVE.md` doesn't come back.

   Inside a block both sides changed, it resolves:
   - table rows, 3-way by their first cell;
   - list items, 3-way by their text:
     - An item either side removed stays removed.
     - An item either side added goes right after the item it followed on that side, ours first when both added at the same spot.
     - An item that starts with the text of an item it replaced (`Ship auth` → `Ship auth v2`) counts as an edit and keeps its place.
     - Numbered lists are renumbered. One-line dated entries (`2026-09-10 — …`, as in `docs/memory/shared.md`) count as items, and their dates are never touched.
     - It's left as a conflict if both sides reordered the items they both kept, edited the same item differently, or one side edited an item the other removed;
   - pure insertions on both sides (ours, then theirs). A one-line row or item that ours already added isn't repeated, but multi-line entries are always kept whole;
   - `Last updated` lines (newest date wins).

   **Snapshot sections.** Some values are re-measured every session: `STATUS.md`'s health table and metrics snapshot, and everything in `TESTING_COVERAGE.md`. After a merge neither branch's numbers are right for the merged code anyway. These sections carry a `<!-- map-merge: snapshot -->` comment; placed above a file's first `##` heading, it covers the whole file. Where both branches changed the same value there, the side with the newer date wins, checking the section's own dates first, then the whole file's. The merge reports what it took so the next session re-verifies it, and doesn't stop. Changes only one side made are still kept as normal.

   Everything the rules settle is kept, and conflict markers remain only around what they couldn't. If nothing is left, the merge completes as normal.
3. **Claude, for the rest.** Each remaining conflict goes to Claude (`claude -p`, no tools, run outside the project so its `CLAUDE.md` and hooks don't load). Claude sees base, ours and theirs, the surrounding lines, the file's own header rules, and the commit subjects from both branches.

   A resolution is accepted only if all of these hold:
   - it has no conflict markers;
   - every heading, table-row key and dated entry from both sides is still there, unless one side deliberately deleted it relative to base;
   - it adds no heading neither side had.

   Anything rejected, or marked unresolved by Claude, keeps its markers.

**Nothing Claude wrote is committed unseen.** When the merge needed Claude, it always stops before committing, even if every conflict was resolved: the files have no markers but are still marked unmerged. Review with `git diff`, then `git add` and commit. `git checkout --conflict=diff3 -- <file>` restores the raw markers if you'd rather start over. Merges the rules fully resolve complete on their own.

**Duplicate `BUG-N`.** In `BUGS.md` / `BUGS_ARCHIVE.md`, if both branches used the same number, the entry already on your side keeps it. The other is renumbered to the next free number across both files and both branches. The merge prints what it renumbered so references in `docs/qa/` can be fixed.
- **Across the two files.** This also applies when your branch already used the number in the other bug file, for example when you found BUG-3 and archived it straight away while they opened a different BUG-3.
- **Same bug on both branches.** If the number existed before the branches split, both entries are the same bug, for example when both branches fixed and archived it. Renumbering would invent a bug, so the merge stops for review with both entries in place.
- **When git never runs the driver.** Git only runs a merge driver on a file both branches changed. If one branch touched only `BUGS.md` and the other only `BUGS_ARCHIVE.md`, a clash between them is never seen at merge time. `bash .map/merge.sh --check-bugs` finds these afterwards. The SessionStart hook runs it every Claude Code session and tells Claude, and `doctor` reports `duplicate-bug-number`. `bash .map/merge.sh --fix-bugs` renumbers the copy in `BUGS.md`. The archive is never edited, so a duplicate inside it is reported for you to fix by hand.

**The `map-resolve` skill** (`.claude/skills/map-resolve/`) is the in-session counterpart. When a merge stops, ask Claude Code to resolve or review the conflicts. It reviews what the driver already resolved, resolves what's left itself, walks you through the diff, and only runs `git add` once you confirm. `AGENTS.md` points other tools at the same steps. `bash .map/merge.sh --resolve <file>` re-runs the driver on a file git already left conflicted, for example when the merge ran before the driver was registered. The path can be relative or absolute. It refuses a file deleted on one side and changed on the other; resolve that with `git add` or `git rm`.

Settings, as environment variables or `git config`:

| Setting | Effect |
|---|---|
| `MAP_MERGE_LLM=0` / `git config map-ai.llm false` | Rules only, never call Claude |
| `MAP_MERGE_MODEL` / `git config map-ai.model` | Model for the Claude pass (defaults to your CLI's default) |
| `MAP_MERGE_LLM_TIMEOUT` | Seconds to wait for Claude per file (default 180) |
| `MAP_MERGE_LLM_COMMAND` / `git config map-ai.llmCommand` | Replace the Claude call entirely: prompt on stdin, reply on stdout |

The Claude pass runs only where the `claude` CLI is installed and signed in. Without it, the rules still run, and the rest is left as markers for you or the `map-resolve` skill.

The driver, `install.sh`, `doctor.sh` and the hooks are plain bash and POSIX awk. The test suite runs under stock-macOS-equivalent bash 3.2 with the BWK awk macOS ships, as well as gawk and mawk. CRLF files are handled. A MAP project in a subdirectory of its git repo (a monorepo package) works too: the driver is registered with that subdirectory in its path. `.gitattributes` is committed, but the driver's registration lives in `.git/config`, which a clone never copies. So it's registered per clone by `install.sh`, `doctor --fix`/`Doctor::fix()` and `map:install`, and silently by the SessionStart hook on a clone's first Claude Code session. `doctor` reports `merge-driver-not-registered` when it's missing. Unregistered, git just falls back to its normal text merge. Merges made on GitHub (the merge button, merge queues) never run local merge drivers.

## Upgrading an existing MAP install

For a brand-new project, letting `install.sh` / `Installer::install()` copy the full template is correct — there's nothing to lose. For a project that's had MAP running for a while, its `AGENTS.md` and other `SCAFFOLD_FILES` likely carry project-specific additions the template doesn't know about — extra Hard rules, custom Load rules for project-specific docs, and so on.

Never regenerate an existing project's `AGENTS.md` (or any other `SCAFFOLD_FILES` entry) wholesale from a newer template — that clobbers real customization. Diff the new template's changed sections against the project's current file and merge in only what's new or changed, preserving anything project-specific. `MANAGED_FILES` (`.cursor/rules/agents.mdc` and the `*.example.md` templates) are the exception — those are meant to always match the template exactly, and `install.sh` / `Installer::install()` already overwrite them automatically on every run (though only when content actually differs — an identical file is left untouched). `.claude/rules/security.md`, `.claude/rules/testing.md`, `.github/copilot-instructions.md`, and `.claude/skills/example-skill/SKILL.md` are all `SCAFFOLD_FILES`, not `MANAGED_FILES` — each is a starting point developers customize in place (coverage thresholds, security rules, project-specific commands), so all are protected the same way `AGENTS.md` is.

### Doctor — checking and repairing drift automatically

The `Doctor` class automates the "what's missing or stale" half of the guidance above. Its governing principle: **the stub's instructional wording is always authoritative** — `fix()` will freely add missing content and replace a project's stale instructional text with the stub's current version. What it will never do is touch, drop, or reinterpret a line that's real project content, not template instructions — those are always left for a human, even when they clearly differ from the stub.

```php
use larablocks\MapAi\Doctor;
use larablocks\MapAi\Installer;

$doctor = new Doctor;

// Report-only — nothing is written. Every finding is tagged fixable: true|false.
$findings = $doctor->check(Installer::stubsPath(), '/path/to/project');

// Applies only the fixable: true findings from check(). Safe to run unattended
// or wire into CI — it never touches real project content.
$applied = $doctor->fix(Installer::stubsPath(), '/path/to/project');

// For a caller that wants to preview and confirm before writing (e.g. an
// interactive per-file "apply these N changes?" prompt) instead of applying
// everything unattended: fixableHunks() computes the same safe hunks fix()
// would use for one file without writing them, applyHunks() splices a
// (sub)set of those hunks in once the developer has confirmed.
$hunks = $doctor->fixableHunks('/path/to/project/docs/GLOSSARY.md', Installer::stubsPath().'/docs/GLOSSARY.md');
$doctor->applyHunks('/path/to/project/docs/GLOSSARY.md', $hunks);
```

`check()` reports these findings:

| `fixable` | Finding | What it means |
|---|---|---|
| `true` | `missing-file` | A `MANAGED_FILES`/`SCAFFOLD_FILES` entry doesn't exist in the project yet |
| `true` | `missing-template-updates` | A `SCAFFOLD_FILES` entry has new stub content the project doesn't have yet, and/or a stale italic note, HTML-comment block, or fenced-code trailing comment the stub has since reworded — all safe to take from the stub as-is |
| `true` | `copilot-out-of-sync` (safe case) | `.github/copilot-instructions.md` is stale, but regenerating it from the project's own `AGENTS.md`/`security.md`/`testing.md` would only add or reorder lines already present in those source files |
| `false` | `outdated-scaffold-file` | A `SCAFFOLD_FILES` entry differs from the stub in a way that isn't a pure addition or a safe note/comment swap — likely real project content, needs a human diff and merge |
| `false` | `agents-md-too-long` | `AGENTS.md` is over the 3,000-token cap (bytes ÷ 4) — which sections to cut is a judgment call |
| `true` | `merge-driver-not-registered` | The clone's `.git/config` doesn't register `merge.map-ai` — `.gitattributes` routes MAP docs to it, but git falls back to its normal text merge until it's registered |
| `false` | `duplicate-bug-number` | A `BUG-N` heading is used twice across `docs/BUGS.md` / `docs/BUGS_ARCHIVE.md` — usually a merge where each branch only touched one of the two files, so the merge driver never ran. Whether the entries are the same bug is a judgement call: different bugs → `bash .map/merge.sh --fix-bugs`, same bug → remove the stale entry |
| `false` | `token-hook-not-registered` | `.claude/settings.json` exists but doesn't register `map-token-check.sh` — the file is copy-if-absent and may hold unrelated hooks/permissions, so the entries are copied in by hand (a missing `settings.json` is reported as a fixable `missing-file` instead) |
| `false` | `copilot-out-of-sync` (unsafe case) | Regenerating `.github/copilot-instructions.md` would drop a line currently in the file — likely a hand edit, or content since removed upstream |

`fix()` applies the `true` rows only: it copies in missing files (via `Installer::install(force: false)`, so `MANAGED_FILES` re-sync and missing `SCAFFOLD_FILES` are added), fills in missing `.gitignore`/`.gitattributes` entries, patches in new stub content and reworded instructional notes into existing `SCAFFOLD_FILES`, and regenerates `.github/copilot-instructions.md` when that's a strict superset of what's already there.

The "is this safe to replace" line is drawn narrowly and mechanically, not by guessing what's "important": a hunk is only ever auto-applied when it's either a pure addition (nothing removed from the project's version), or a modification that's entirely one of three instructional conventions:

- **A full-line italic note** (`_like this_`) on both sides.
- **A complete HTML comment block** (`<!-- ... -->`, possibly spanning several lines) on both sides.
- **A single-line change inside a fenced code block** where only the trailing `  # comment` differs — the real command before it must be byte-identical on both sides. This one is scoped to fenced code specifically: `#` has no reserved meaning in prose (e.g. `See issue #123`), so outside a code fence a matching prefix isn't a reliable signal and the change is left for a human.

Real content (bug entries, schema tables, decision records, filled-in commands) is never written in any of these three forms, so all three are safe to always take from the stub. Anything else — prose bullets, headings, arbitrary reworded lines, or a comment change outside a fence — is exactly the shape real project content also takes, so it's left for a human every time.

### `doctor.sh` — the same checks, no PHP required

`doctor.sh` is a standalone bash port of `Doctor` with identical behavior — same findings, same fix-only-what's-safe guarantee, verified byte-for-byte against the PHP implementation for both the `.github/copilot-instructions.md` regeneration case and the general instructional-note-patching case. It exists because the MAP convention itself is language-agnostic (it installs into any project via `install.sh`, PHP or not — see [Any other PHP project](#any-other-php-project) above, which works the same way for non-PHP projects too), but the PHP `Doctor` class only helps if PHP happens to be present. `doctor.sh` doesn't have that requirement — it needs nothing but bash, matching `install.sh`'s own zero-runtime-dependency design.

```bash
./doctor.sh /path/to/project                 # report only — exits 1 if anything needs attention
./doctor.sh /path/to/project --fix           # applies fixable findings, then reports what's left
./doctor.sh /path/to/project --interactive   # same fixable set as --fix, confirmed one file at a time
```

Report-only mode exits `1` on any finding at all (fixable or not) — CI-friendly, since any drift is worth surfacing. `--fix` mode exits `0` once everything it can apply is applied, `1` only if a review-only finding remains. `--interactive` still applies missing files/`.gitignore`/`.gitattributes` entries unattended (there's no existing content they could touch, apart from removing the `merge=union` lines and their header that MAP itself wrote up to 0.1.9), but shows each scaffold file's fixable hunks — and the `.github/copilot-instructions.md` regeneration — and asks `[Y/n]` before writing, one prompt per file with all of that file's changes together, not one prompt per hunk.

`doctor.sh` sources `lib.sh` for the `MANAGED_FILES`/`SCAFFOLD_FILES` lists (the same ones `install.sh` and `Installer.php` use — kept in sync by an automated test), and shells out to `install.sh` internally for the missing-file/gitignore/gitattributes repairs rather than reimplementing that logic a third time.

## What gets installed

```
AGENTS.md                          — master instructions every tool reads
CLAUDE.md                          — Claude Code entry point (@AGENTS.md)
GEMINI.md                          — Gemini CLI entry point (@AGENTS.md)
.claude/rules/security.md          — security rules, auto-loaded every session
.claude/rules/testing.md           — coverage and test quality rules, auto-loaded
.claude/skills/example-skill/SKILL.md — template for a Claude Code skill (auto-discovered, no wiring needed)
.github/copilot-instructions.md    — Copilot entry point (AGENTS.md inlined)
.cursor/rules/agents.mdc           — Cursor entry point (@AGENTS.md)
.claude/settings.json              — registers the MAP hooks below (copied only if absent)
.claude/hooks/map-first-run-check.sh — SessionStart: first-run check + registers the merge driver
.claude/hooks/map-token-check.sh   — SessionStart/PostToolUse: enforces the token caps
.claude/skills/map-resolve/SKILL.md — resolve/review MAP doc merge conflicts with Claude Code
.map/merge.sh                      — git merge driver for MAP docs (see Merging MAP docs)

docs/STATUS.md                     — project health: build, tests, blockers, milestones
docs/BUGS.md                       — open bugs (AI-maintained)
docs/BUGS_ARCHIVE.md               — fixed bugs (append-only)
docs/ARCHITECTURE.md               — current system structure (AI-maintained)
docs/ARCHITECTURE_HISTORY.md       — decision log (append-only)
docs/CODE_PATTERNS.md              — project-specific patterns (AI-maintained)
docs/COMMANDS.md                   — custom project commands, categorized (AI-maintained)
docs/FEATURE_FLAGS.md              — feature flag registry (AI-maintained)
docs/SCHEMA.md                     — database schema and contracts (AI-maintained)
docs/COMPLIANCE.md                 — regulatory/compliance obligations (Claude proposes, developer approves)
docs/DESIGN.md                     — UI/frontend conventions (Claude proposes, developer approves; optional — delete if no UI layer)
docs/GLOSSARY.md                   — domain terms and abbreviations (AI-maintained)
docs/DOCKER.md                     — container reference (Claude proposes, developer approves)
docs/SETUP.md                      — local dev setup for new developers (Claude proposes, developer approves)
docs/TESTING_COVERAGE.md           — coverage tracking (AI-maintained from output)
docs/METRICS_HISTORY.md            — dated metrics log for leadership (AI-maintained, append-only)
docs/STATUS_ARCHIVE.md             — older STATUS.md progress entries (append-only, not loaded each session)

docs/MEMORY.example.md             — memory index template (copy to MEMORY.md)
docs/memory/gotchas.example.md     — critical mistakes to avoid
docs/memory/framework.example.md   — framework/language surprises
docs/memory/database.example.md    — database behaviour surprises
docs/memory/testing.example.md     — test framework quirks
docs/memory/environment.example.md — environment and Docker surprises
docs/memory/performance.example.md — performance bottlenecks and surprises
docs/memory/agents.example.md      — agent pipeline learnings (optional)
docs/memory/shared.example.md      — team-shared learnings (committed)

docs/agents/agent.example.md       — template for documenting a specific agent (name + description frontmatter)
docs/api/api.example.md            — template for documenting an API (name + description frontmatter)
docs/architecture/architecture.example.md — template for documenting a subsystem or component (name + description frontmatter)
docs/integrations/integration.example.md — template for documenting an integration (name + description frontmatter)
docs/qa/qa.example.md              — template for a completed feature's QA notes
```

### Cheap discovery for per-item docs

`docs/agents/`, `docs/api/`, `docs/integrations/`, and `docs/architecture/` can hold many files — one per agent, endpoint, integration, or component. Each one starts with plain YAML frontmatter (`name` + `description`), the same progressive-disclosure idea behind Claude Code's `SKILL.md` files: scan the cheap part across every file in the folder to find the one that matches, then load only that file's full body. It's plain YAML in a markdown file, so every MAP-supported tool can use or ignore it — this is a MAP-internal convention for keeping "load when relevant" cheap at scale, not a claim of compatibility with Claude Code's native Skill discovery, which is a separate mechanism scoped to `.claude/skills/`.

For that native mechanism, MAP ships `.claude/skills/example-skill/SKILL.md` — a starting point for Claude-Code-specific, invocable skills (as opposed to the passive context docs above). Copy the folder, rename it, and Claude Code auto-discovers it; nothing in `AGENTS.md` needs to change. It's Claude-Code-only, so it lives under `.claude/` alongside `.claude/rules/` rather than in the tool-agnostic `docs/` tree.

### Hub-and-spoke: one recurring shape

A few places in MAP converge on the same shape independently: a hub file holds an index or quick-reference and owns the maintenance contract, while companion files hold the depth. `docs/COMMANDS.md`'s quick-index table points at per-command detail below it; `docs/ARCHITECTURE.md`'s Component docs table points into `docs/architecture/[name].md`; `docs/integrations/[service].md` is guided to split into `[service]-[topic].md` companions once a single file passes ~2,000 tokens or covers multiple distinct concerns. It isn't a distinct file type MAP defines — it's a convention worth recognizing when a single doc starts doing too much: split it, keep one file as the index, and note in that index that it and its companions must be kept current together.

---

## For package authors

If you want to build a MAP installer for another framework, require this package and use:

```php
use larablocks\MapAi\Installer;

$result = (new Installer)->install(
    stubsPath: Installer::stubsPath(),
    targetPath: '/path/to/project',
    force: false,
);
```

`$result` contains a `files` array with per-file `action` (`copy`, `update`, `skip`, `identical`, `missing`, or `symlink` — the last when the destination is a symlink, which is never written through) and `backed_up` (true when an existing file was backed up to `<file>.bak` before overwriting — note this backup is single-generation: a second forced overwrite replaces `<file>.bak` with the file's already-once-overwritten content, so it isn't a full history), plus `gitignore` and `gitattributes` keys (each `updated` or `skipped`).

Two more `Installer` methods round out the API:

```php
// Copies each PERSONAL_FILES example (e.g. docs/MEMORY.example.md, already placed by
// install()) to its personal, gitignored counterpart (docs/MEMORY.md) if that file doesn't
// already exist. map-ai-laravel's map:install command calls this right after install() so
// each developer's personal memory files get bootstrapped alongside the shared scaffold.
(new Installer)->bootstrapPersonalFiles(targetPath: '/path/to/project');
// Returns a list of {action: 'copy'|'skip'|'missing', file: string}.

// Reports which SCAFFOLD_FILES exist in the project but differ from the current stub —
// useful for a standalone "your docs are behind the template" check without writing
// anything.
(new Installer)->scaffoldOutOfDate(stubsPath: Installer::stubsPath(), targetPath: '/path/to/project');
// Returns a list<string> of relative file paths.
```

For anything more than a yes/no "is it out of date" check — classifying *what kind* of drift each file has, or safely patching in just the safe parts — use `Doctor` instead of `scaffoldOutOfDate()` directly; see [Doctor — checking and repairing drift automatically](#doctor--checking-and-repairing-drift-automatically) above. `map-ai-laravel`'s `map:install`/`map:diff` commands are built on `Doctor::check()`/`fixableHunks()`/`applyHunks()`, not this method.

## Development

```bash
composer install
composer test
composer analyse
composer format
```

## License

MIT
