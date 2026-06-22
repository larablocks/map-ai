# copilot-instructions.md
_GitHub Copilot entry point — MAP v1.0 convention_
_Copilot does not support @file imports — AGENTS.md content is inlined below._
_When AGENTS.md changes, update this file to match._

---

# AGENTS.md
_Project: [PROJECT NAME] | Stack: [e.g. Laravel 13, PHP 8.5, PostgreSQL 16, Redis]_
_MAP v1.0 | Last updated: [DATE]_

## Personal rules
Load CLAUDE.local.md if it exists or equivalent local rules file for your tool — overrides AGENTS.md
If docs/MEMORY.md does not exist — developer has not run docs/SETUP.md step 3 yet.

## Session start ritual
1. Read docs/STATUS.md — if it contains only placeholder text, tell developer to fill it in
2. Read docs/MEMORY.md — if it exists, load docs/memory/gotchas.md and docs/memory/shared.md (if exists) and note topic files
3. Read docs/BUGS.md — note any blocking or high severity bugs before starting work
4. Ask the developer what they want to work on before acting

## Commands — fill these in for this project
- Run tests: `[TEST COMMAND]`
- Static analysis: `[STATIC ANALYSIS COMMAND]`
- Start services: `[START COMMAND]`
- Build: `[BUILD COMMAND]`

## Load when relevant
Read docs/ARCHITECTURE.md when working on structure or new features
Read docs/ARCHITECTURE_HISTORY.md when revisiting an architectural choice
Read docs/CODE_PATTERNS.md when writing application code, migrations, config or scripts
Read docs/SCHEMA.md when touching the database or internal service contracts
Read docs/BUGS.md when writing tests or modifying areas with known issues
Read docs/TESTING_COVERAGE.md when writing or reviewing tests
Read docs/DOCKER.md when running commands or diagnosing environment issues (skip if project has no Docker)
Read docs/SETUP.md when helping with local dev or onboarding questions
Read docs/GLOSSARY.md when domain-specific terms or abbreviations are unfamiliar
Read docs/memory/[stack].md when writing application code, migrations, config or scripts — past surprises
Read docs/memory/agents.md when working on agent pipeline (skip if no agents)
Read docs/memory/database.md when touching the database or schema
Read docs/memory/testing.md when writing or debugging tests
Read docs/memory/environment.md when diagnosing environment issues
Read docs/memory/performance.md when investigating slow behaviour or optimising code
Read docs/FEATURE_FLAGS.md when starting a new feature or working on flagged code
Read docs/agents/[name].md when working on a specific agent
Read docs/api/[name].md when working on API endpoints
Read docs/integrations/[name].md when working with an external service

## Write rules — do these immediately, without being asked
_Priority order: BUGS.md first, then ARCHITECTURE_HISTORY.md, then others_
- Bug found (any source) → append to docs/BUGS.md; Bug fixed and verified → move to docs/BUGS_ARCHIVE.md
- Architectural decision made → append to docs/ARCHITECTURE_HISTORY.md (hard to reverse or multi-component only)
- New pattern established → check docs/CODE_PATTERNS.md first, only append if not already covered
- New domain term or abbreviation encountered → add to docs/GLOSSARY.md
- Surprising behaviour → route by topic (all in docs/memory/): [stack].md | database.md | testing.md | environment.md | performance.md | agents.md
- Memory file updated → update entry count in docs/MEMORY.md summary table
- Time wasted on a mistake → append to docs/memory/gotchas.md (max 10 entries — remove least-actionable when full)
- Schema changed → update docs/SCHEMA.md immediately
- Architecture changed → update docs/ARCHITECTURE.md to reflect current state
- Tests added or coverage run → update docs/TESTING_COVERAGE.md from command output
- Performance issue discovered → append to docs/memory/performance.md
- New feature started → suggest adding a feature flag before implementing; flag created → append row to docs/FEATURE_FLAGS.md active section; flag removed → move row to removed section

## Session end — do this before closing
1. Update docs/STATUS.md — milestone/feature progress, health indicators, project-level next priorities
2. Ask "what did I learn?" — route each learning to docs/memory/ and update MEMORY.md entry counts

## File routing — when in doubt
Project health → docs/STATUS.md | Decisions → docs/ARCHITECTURE_HISTORY.md
Bugs → docs/BUGS.md | Old fixed bugs → docs/BUGS_ARCHIVE.md | Learnings → docs/memory/[topic].md
New agent/API/integration docs → copy the relevant .example.md in that folder

## Ask before acting
- Destructive or cross-system action → confirm scope before executing
- Ambiguous requirement → state interpretation and confirm
- Uncertain about framework-specific approach → read relevant docs/ file first, then act
- Otherwise → act then explain

## Hard rules
- IMPORTANT: Never delete files, database records, or data without explicit developer confirmation
- IMPORTANT: Never modify AGENTS.md, CLAUDE.md, GEMINI.md, or .claude/rules/*.md without explicit developer instruction — these are MAP configuration files, not AI-maintained docs
- Use YYYY-MM-DD for all dates in all files
- IMPORTANT: Only update docs/TESTING_COVERAGE.md after running coverage — never estimate without fresh output
- IMPORTANT: Never skip the session start ritual

## Security rules
_Copilot does not auto-load .claude/rules/security.md — rules are inlined here_
- IMPORTANT: Never commit secrets, API keys, or credentials
- Never log sensitive values — mask in all output
- Environment variables for all external service credentials
- .env is gitignored — .env.example contains only placeholder values
- Validate and sanitise all external input before use
- Never trust data from queues, webhooks, or external APIs without validation
- Flag any new dependency additions for review
- Do not add packages with known critical vulnerabilities

## Testing rules
_Copilot does not auto-load .claude/rules/testing.md — rules are inlined here_
- IMPORTANT: New code requires tests before marking a task complete
  (exception: first task on a new project may establish the test framework itself)
- Minimum coverage threshold: 80% (adjust in .claude/rules/testing.md if different) — run coverage and update docs/TESTING_COVERAGE.md after adding tests
- Critical paths require explicit test coverage regardless of overall percentage
- Tests must assert behaviour, not implementation details
- Each test has one clear reason to fail
- Test names describe the scenario, not the method being tested
- Static analysis must pass before any task is considered complete
- Do not suppress errors without a comment explaining why
