# map-ai

Framework-agnostic core for the **MAP** documentation scaffold. Contains the stubs and installer logic used by framework-specific packages.

## What is MAP?

MAP is a structured set of markdown files that AI coding agents read at session start. It gives every session — with any tool — a reliable starting point: what the project is, how to run it, what's been decided and why, where to pick up from last time, and accumulated knowledge about what doesn't work.

Without it, each AI session starts from zero. With it, sessions start informed.

## What you gain

### Sessions pick up where they left off

`HANDOFF.md` captures the exact state at session end: what was in progress, what's broken, what to do next. Start a new session with "continue" and the AI reads it and resumes without re-explanation. No more spending the first ten minutes re-establishing context.

### The AI remembers what it learned

`docs/memory/` files accumulate project-specific knowledge across sessions — gotchas, database quirks, testing patterns, environment surprises. The AI reads these at startup and doesn't repeat mistakes it's already made on your project.

`docs/memory/shared.md` is committed to the repo, so every developer's AI sessions share the same team-level learnings.

### The AI knows your project from the first message

`AGENTS.md` tells every tool your stack, your test and build commands, and what files to read for what purpose. The AI doesn't ask what framework you're on or how to run tests — it already knows.

### Decisions don't get lost

`docs/ARCHITECTURE_HISTORY.md` is append-only. Every significant architectural decision is recorded with its alternatives and reasoning. When a decision gets revisited months later, the AI can explain why it was made the first time.

### Bugs stay visible

`docs/BUGS.md` is a live list the AI maintains automatically — appended on discovery, moved to `docs/BUGS_ARCHIVE.md` on fix. Known issues don't disappear from context at session end.

### Security and testing standards apply every session

`.claude/rules/security.md` and `.claude/rules/testing.md` load automatically each Claude Code session. The AI follows your coverage requirements and security practices without being reminded.

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

Writes happen immediately — not deferred to session end, not optional. When the AI finds a bug mid-task, it appends to `BUGS.md` before continuing. When it makes an architectural call, it records the decision and reasoning before moving on. The priority order is fixed: `BUGS.md` first, `ARCHITECTURE_HISTORY.md` second, everything else after.

`HANDOFF.md` is rewritten at two specific moments: when a task completes or a blocker is discovered, and when the context window approaches 70% capacity. At the context limit the AI rewrites `HANDOFF.md` and compacts — so even a session cut short by a context reset leaves a clean handoff for the next session to continue from.

At session end the AI runs a closing ritual: rewrite `HANDOFF.md`, update `docs/STATUS.md` with current project health, and route everything learned during the session to the appropriate `docs/memory/` file.

The result is documentation that reflects what is actually true about the project right now — maintained continuously as a side effect of development work, not as a separate task someone needs to remember to do.

---

## Designed for lean context

Every file in MAP has a size ceiling enforced by the AI's own write rules. `AGENTS.md` stays under 90 lines. `HANDOFF.md` stays under 40. `docs/memory/gotchas.md` caps at 10 entries. Memory topic files cap at 50. When a file fills up, the AI summarises or removes before adding — so files stay dense and high-signal rather than growing without bound.

Beyond size caps, the structure itself controls what gets loaded:

**Selective loading, not full context at startup.** `AGENTS.md`'s "Load when relevant" section tells the AI which files to read for which tasks. A session fixing a bug doesn't load `ARCHITECTURE.md` or `SCHEMA.md`. A session touching the database doesn't load `DOCKER.md`. Files are pulled on demand.

**Index before content.** `MEMORY.md` is a one-page index — a table of topic files and entry counts. The AI reads it first to know what knowledge exists, then loads only the topic file relevant to the current task. `docs/memory/database.md` is never loaded during a UI fix.

**Session branching eliminates waste.** The startup ritual has two explicit paths: fresh start and continuation. In a fresh start, `HANDOFF.md` is skipped entirely. In a continuation, only `HANDOFF.md` and a small set of status files are read before work begins. The AI doesn't load everything — it loads what the session type requires.

**History separated from current state.** `ARCHITECTURE_HISTORY.md` grows large over time; `ARCHITECTURE.md` stays a concise snapshot of current structure. You pay for historical decision tokens only when a decision is actively being revisited.

**Write-on-discovery keeps future context accurate.** The AI writes to docs immediately when it finds something rather than waiting until session end. Accurate docs mean future sessions don't waste tokens working from stale context or asking clarifying questions they shouldn't need to ask.

The result: sessions start faster because the AI isn't loading irrelevant context, and the token cost per session stays proportional to the actual scope of the work.

---

## What gets installed

```
AGENTS.md                          — master instructions every tool reads
CLAUDE.md                          — Claude Code entry point (@AGENTS.md)
GEMINI.md                          — Gemini CLI entry point (@AGENTS.md)
HANDOFF.example.md                 — session state template (copy to HANDOFF.md)

.claude/rules/security.md          — security rules, auto-loaded every session
.claude/rules/testing.md           — coverage and test quality rules, auto-loaded
.github/copilot-instructions.md    — Copilot entry point (AGENTS.md inlined)
.cursor/rules/agents.mdc           — Cursor entry point (@AGENTS.md)

docs/STATUS.md                     — project health: build, tests, blockers, milestones
docs/BUGS.md                       — open bugs (AI-maintained)
docs/BUGS_ARCHIVE.md               — fixed bugs (append-only)
docs/ARCHITECTURE.md               — current system structure (AI-maintained)
docs/ARCHITECTURE_HISTORY.md       — decision log (append-only)
docs/CODE_PATTERNS.md              — project-specific patterns (AI-maintained)
docs/SCHEMA.md                     — database schema and contracts (AI-maintained)
docs/GLOSSARY.md                   — domain terms and abbreviations
docs/DOCKER.md                     — container reference
docs/SETUP.md                      — local dev setup for new developers
docs/TESTING_COVERAGE.md           — coverage tracking (AI-maintained from output)

docs/MEMORY.example.md             — memory index template (copy to MEMORY.md)
docs/memory/gotchas.example.md     — critical mistakes to avoid
docs/memory/framework.example.md   — framework/language surprises
docs/memory/database.example.md    — database behaviour surprises
docs/memory/testing.example.md     — test framework quirks
docs/memory/environment.example.md — environment and Docker surprises
docs/memory/agents.example.md      — agent pipeline learnings (optional)
docs/memory/shared.example.md      — team-shared learnings (committed)

docs/agents/agent.example.md       — template for documenting a specific agent
docs/api/api.example.md            — template for documenting an API
docs/integrations/integration.example.md — template for documenting an integration
```

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

`$result` contains a `files` array with per-file `action` (`copy`, `update`, `skip`, `missing`) and `backed_up` (true when an existing file was backed up to `<file>.bak` before overwriting), plus a `gitignore` key (`updated` or `skipped`).

## Development

```bash
composer install
composer test
composer analyse
composer format
```

## License

MIT
