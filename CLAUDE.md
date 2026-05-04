# CLAUDE.md
_Claude Code entry point — imports AGENTS.md (MAP v1.0) and adds Claude-specific config_

@AGENTS.md

## Claude Code additions
- `.claude/rules/*.md` files load automatically every session — no @import needed
- Context approaching 70%: rewrite HANDOFF.md then run `/compact` to compress history
- Always rewrite HANDOFF.md before running `/clear` or `/compact`
- Keep AGENTS.md at 90 lines maximum
