# rules/meta.md
_This repo is the MAP template source — always loaded_

## IMPORTANT: This is a template repository
This project exists to maintain and improve the MAP template files.
It is NOT a project that uses MAP for its own development.

- Do NOT fill in template placeholders (e.g. `[PROJECT NAME]`, `[TEST COMMAND]`, `YYYY-MM-DD`)
- Do NOT treat docs/*.md files as live project documentation — they are templates for other projects to copy
- Do NOT run session rituals (STATUS.md updates) as if this were an active application project
- DO treat all files as template artifacts to be reviewed, improved, and kept consistent
- DO flag inconsistencies between files (e.g. AGENTS.md vs copilot-instructions.md sync)

## Sync rule — when editing template files
`stubs/` is the canonical distributable source. Root-level template files (AGENTS.md, CLAUDE.md, etc.) mirror stubs/ for this repo's own AI sessions.

When any template file is edited:
1. Update `stubs/<file>` first — this is what the installer distributes
2. Sync the change to the root-level copy
3. If `AGENTS.md`, `.claude/rules/security.md`, or `.claude/rules/testing.md` changed → update `.github/copilot-instructions.md` (both `stubs/` and root) to match
