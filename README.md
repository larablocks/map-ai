# map-ai

Framework-agnostic core for the **MAP** documentation scaffold. Contains the stubs and installer logic used by framework-specific packages.

## What is MAP?

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

Both files carry `BUG-N` IDs and ship with `merge=union` set in `.gitattributes`, so two branches appending entries at the same time combine cleanly instead of producing conflict markers. If two branches independently assign the same `BUG-N`, the AI is instructed to notice after a merge and renumber the duplicate — `docs/BUGS.md` documents the exact procedure.

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
| Time wasted on a mistake | `docs/memory/gotchas.md` — capped at 10, least-actionable removed when full |
| A schema change | `docs/SCHEMA.md` — updated immediately |
| An architecture change | `docs/ARCHITECTURE.md` — updated to reflect current state |
| Tests added or coverage run | `docs/TESTING_COVERAGE.md` — from actual output, never estimated |
| Session end | `docs/METRICS_HISTORY.md` — dated entry appended, current metrics vs. the previous one |

Writes happen immediately — not deferred to session end, not optional. When the AI finds a bug mid-task, it appends to `BUGS.md` before continuing. When it makes an architectural call, it records the decision and reasoning before moving on. The priority order is fixed: `BUGS.md` first, `ARCHITECTURE_HISTORY.md` second, everything else after.

At session end the AI updates `docs/STATUS.md` with current project health and routes everything learned during the session to the appropriate `docs/memory/` file.

The result is documentation that reflects what is actually true about the project right now — maintained continuously as a side effect of development work, not as a separate task someone needs to remember to do.

---

## Designed for lean context

Every file in MAP has a size ceiling enforced by the AI's own write rules. `AGENTS.md` stays under 100 lines. `docs/memory/gotchas.md` caps at 10 entries. Memory topic files cap at 50. When a file fills up, the AI summarises or removes before adding — so files stay dense and high-signal rather than growing without bound.

Beyond size caps, the structure itself controls what gets loaded:

**Selective loading, not full context at startup.** `AGENTS.md`'s "Load when relevant" section tells the AI which files to read for which tasks. A session fixing a bug doesn't load `ARCHITECTURE.md` or `SCHEMA.md`. A session touching the database doesn't load `DOCKER.md`. Files are pulled on demand.

**Index before content.** `MEMORY.md` is a one-page index — a table of topic files and entry counts. The AI reads it first to know what knowledge exists, then loads only the topic file relevant to the current task. `docs/memory/database.md` is never loaded during a UI fix.

**History separated from current state.** `ARCHITECTURE_HISTORY.md` grows large over time; `ARCHITECTURE.md` stays a concise snapshot of current structure. You pay for historical decision tokens only when a decision is actively being revisited.

**Write-on-discovery keeps future context accurate.** The AI writes to docs immediately when it finds something rather than waiting until session end. Accurate docs mean future sessions don't waste tokens working from stale context or asking clarifying questions they shouldn't need to ask.

The result: sessions start faster because the AI isn't loading irrelevant context, and the token cost per session stays proportional to the actual scope of the work.

---

## Upgrading an existing MAP install

For a brand-new project, letting `install.sh` / `Installer::install()` copy the full template is correct — there's nothing to lose. For a project that's had MAP running for a while, its `AGENTS.md` and other `SCAFFOLD_FILES` likely carry project-specific additions the template doesn't know about — extra Hard rules, custom Load rules for project-specific docs, and so on.

Never regenerate an existing project's `AGENTS.md` (or any other `SCAFFOLD_FILES` entry) wholesale from a newer template — that clobbers real customization. Diff the new template's changed sections against the project's current file and merge in only what's new or changed, preserving anything project-specific. `MANAGED_FILES` (`.cursor/rules/agents.mdc` and the `*.example.md` templates) are the exception — those are meant to always match the template exactly, and `install.sh` / `Installer::install()` already overwrite them automatically on every run (though only when content actually differs — an identical file is left untouched). `.claude/rules/security.md`, `.claude/rules/testing.md`, `.github/copilot-instructions.md`, and `.claude/skills/example-skill/SKILL.md` are all `SCAFFOLD_FILES`, not `MANAGED_FILES` — each is a starting point developers customize in place (coverage thresholds, security rules, project-specific commands), so all are protected the same way `AGENTS.md` is.

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

A few places in MAP converge on the same shape independently: a hub file holds an index or quick-reference and owns the maintenance contract, while companion files hold the depth. `docs/COMMANDS.md`'s quick-index table points at per-command detail below it; `docs/ARCHITECTURE.md`'s Component docs table points into `docs/architecture/[name].md`; `docs/integrations/[service].md` is guided to split into `[service]-[topic].md` companions once a single file passes ~150 lines or covers multiple distinct concerns. It isn't a distinct file type MAP defines — it's a convention worth recognizing when a single doc starts doing too much: split it, keep one file as the index, and note in that index that it and its companions must be kept current together.

---

## Framework packages

Install MAP via your framework's package — this core package is a dependency, not meant to be required directly:

| Framework | Package |
|-----------|---------|
| Laravel | [`larablocks/map-ai-laravel`](https://github.com/larablocks/map-ai-laravel) |

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

## Development

```bash
composer install
composer test
composer analyse
composer format
```

## License

MIT
