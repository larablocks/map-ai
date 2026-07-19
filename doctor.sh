#!/usr/bin/env bash
# doctor.sh — report on and repair drift between an installed MAP project and
# the current template stubs, without needing a PHP/Composer runtime at all.
# Mirrors src/Doctor.php's check()/fix() split and the same hard rule: --fix
# only ever adds content (missing files, missing gitignore/gitattributes
# entries, a safe copilot-instructions.md regeneration) — it never removes or
# rewrites a line a developer could have written. Anything else is reported
# only, for a human to merge by hand.
# Usage: ./doctor.sh <target-project-path> [--fix]
# Run from the map-ai repo root.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

TARGET=""
FIX=0

for arg in "$@"; do
  case "$arg" in
    --fix) FIX=1 ;;
    -*) echo "Unknown option: $arg"; exit 1 ;;
    *) TARGET="$arg" ;;
  esac
done

if [[ -z "$TARGET" ]]; then
  echo "Usage: $0 <target-project-path> [--fix]"
  echo ""
  echo "  --fix   Apply only the additive, zero-judgment repairs: missing files,"
  echo "          missing .gitignore/.gitattributes entries, and a"
  echo "          .github/copilot-instructions.md regeneration — but only when"
  echo "          regenerating it would strictly add content, never drop a line"
  echo "          that's already there. Everything else is reported only."
  exit 1
fi

if [[ ! -d "$TARGET" ]]; then
  echo "Error: '$TARGET' is not a directory"
  exit 1
fi

AGENTS_MD_MAX_LINES=100
FIXABLE_FOUND=0
REVIEW_FOUND=0

COPILOT_HEADER='# copilot-instructions.md
_GitHub Copilot entry point — MAP v1.0 convention_
_Copilot does not support @file imports — AGENTS.md content is inlined below._
_When AGENTS.md, .claude/rules/security.md, or .claude/rules/testing.md changes, update this file to match — the Security/Testing rules sections below are inlined copies of those two files._'

# ---------------------------------------------------------------------------
# .github/copilot-instructions.md regeneration — mirrors Doctor.php exactly:
# AGENTS.md verbatim (minus @-prefixes, which Copilot doesn't resolve) plus
# security.md's and testing.md's bullet lines with their headers stripped.
# ---------------------------------------------------------------------------

# Strips the @ from @docs/... and @CLAUDE.local.md references — Copilot
# doesn't support @file imports, so those refs would just be dead text to it.
strip_at_refs() {
  sed -E 's/@(docs\/|CLAUDE\.local\.md)/\1/g' "$1"
}

# Extracts top-level "- " bullets and their indented continuation lines from
# a rules file, dropping its H1 title and ## subheadings. Once a bullet has
# been seen, any later indented non-blank line is treated as a continuation
# of it — this deliberately mirrors Doctor.php's extractBullets() line for
# line, including its looseness (an indented line anywhere after the first
# bullet counts), so both implementations produce identical output on the
# same input.
extract_bullets() {
  awk '
    /^-[ \t]/ { print; bullets=1; next }
    bullets && /^[ \t]+[^ \t]/ { print; next }
  ' "$1"
}

# Prints the canonical copilot-instructions.md content for $1 (a project
# root) to stdout, or returns 1 if any of the three source files is missing —
# there is nothing to regenerate from.
regenerate_copilot() {
  local project="$1"
  local agents="$project/AGENTS.md"
  local security="$project/.claude/rules/security.md"
  local testing="$project/.claude/rules/testing.md"

  if [[ ! -f "$agents" || ! -f "$security" || ! -f "$testing" ]]; then
    return 1
  fi

  local agents_body security_bullets testing_bullets
  agents_body="$(strip_at_refs "$agents")"
  security_bullets="$(extract_bullets "$security")"
  testing_bullets="$(extract_bullets "$testing")"

  printf '%s\n\n---\n\n%s\n\n## Security rules\n_Copilot does not auto-load .claude/rules/security.md — rules are inlined here_\n%s\n\n## Testing rules\n_Copilot does not auto-load .claude/rules/testing.md — rules are inlined here_\n%s\n' \
    "$COPILOT_HEADER" "$agents_body" "$security_bullets" "$testing_bullets"
}

# True only if every non-blank line of $1 (rtrimmed) appears verbatim
# somewhere in $2 (also rtrimmed) — the safety net that decides whether
# regenerating copilot-instructions.md can only add/reorder content, or
# would drop something (a hand edit, or content since removed upstream).
copilot_is_superset() {
  local current_trimmed regenerated_trimmed line
  current_trimmed="$(printf '%s\n' "$1" | sed -E 's/[[:space:]]+$//')"
  regenerated_trimmed="$(printf '%s\n' "$2" | sed -E 's/[[:space:]]+$//')"

  while IFS= read -r line; do
    [[ -z "${line//[[:space:]]/}" ]] && continue
    grep -qxF -- "$line" <<< "$regenerated_trimmed" || return 1
  done <<< "$current_trimmed"

  return 0
}

# Regenerates .github/copilot-instructions.md in $TARGET only when doing so
# is superset-safe. Prints nothing; returns 1 if there was nothing to do
# (in sync, unsafe, or source files missing) so callers can branch on it.
fix_copilot_sync() {
  local copilot_path="$TARGET/.github/copilot-instructions.md"
  local regenerated current

  regenerated="$(regenerate_copilot "$TARGET")" || return 1

  current=""
  [[ -f "$copilot_path" ]] && current="$(cat "$copilot_path")"

  if [[ "$(printf '%s' "$current" | tr -d '\r')" == "$(printf '%s' "$regenerated" | tr -d '\r')" ]]; then
    return 1
  fi

  copilot_is_superset "$current" "$regenerated" || return 1

  mkdir -p "$(dirname "$copilot_path")"
  printf '%s\n' "$regenerated" > "$copilot_path"
  return 0
}

# ---------------------------------------------------------------------------
# Apply fixes first (if --fix), then always run the check pass below to
# report what's left — including confirmation that the fixable items are
# gone, and anything that still needs a human.
# ---------------------------------------------------------------------------
if [[ "$FIX" -eq 1 ]]; then
  echo "Applying fixes..."
  echo ""
  # Same guarantee as calling this directly without --force: MANAGED_FILES
  # re-sync (pure template, nothing to clobber), missing SCAFFOLD_FILES are
  # added, existing ones are left untouched.
  bash "$SCRIPT_DIR/install.sh" "$TARGET"
  echo ""
  if fix_copilot_sync; then
    echo "  [FIXED]  .github/copilot-instructions.md regenerated"
  fi
  echo ""
fi

echo "Checking MAP install at: $TARGET"
echo ""

for file in "${MANAGED_FILES[@]}" "${SCAFFOLD_FILES[@]}"; do
  src="$SCRIPT_DIR/stubs/$file"
  dst="$TARGET/$file"
  [[ -f "$src" ]] || continue
  if [[ ! -f "$dst" ]]; then
    echo "  [FIXABLE]  missing-file             $file"
    ((FIXABLE_FOUND++)) || true
  fi
done

for file in "${SCAFFOLD_FILES[@]}"; do
  src="$SCRIPT_DIR/stubs/$file"
  dst="$TARGET/$file"
  [[ -f "$src" && -f "$dst" ]] || continue
  if ! cmp -s "$src" "$dst"; then
    echo "  [REVIEW]   outdated-scaffold-file  $file  (differs from the current stub — merge by hand)"
    ((REVIEW_FOUND++)) || true
  fi
done

AGENTS_PATH="$TARGET/AGENTS.md"
if [[ -f "$AGENTS_PATH" ]]; then
  AGENTS_LINE_COUNT="$(wc -l < "$AGENTS_PATH" | tr -d ' ')"
  if (( AGENTS_LINE_COUNT > AGENTS_MD_MAX_LINES )); then
    echo "  [REVIEW]   agents-md-too-long      AGENTS.md ($AGENTS_LINE_COUNT lines, over the $AGENTS_MD_MAX_LINES line cap — trim by hand)"
    ((REVIEW_FOUND++)) || true
  fi
fi

if COPILOT_REGENERATED="$(regenerate_copilot "$TARGET")"; then
  COPILOT_PATH="$TARGET/.github/copilot-instructions.md"
  COPILOT_CURRENT=""
  [[ -f "$COPILOT_PATH" ]] && COPILOT_CURRENT="$(cat "$COPILOT_PATH")"

  if [[ "$(printf '%s' "$COPILOT_CURRENT" | tr -d '\r')" != "$(printf '%s' "$COPILOT_REGENERATED" | tr -d '\r')" ]]; then
    if copilot_is_superset "$COPILOT_CURRENT" "$COPILOT_REGENERATED"; then
      echo "  [FIXABLE]  copilot-out-of-sync      .github/copilot-instructions.md  (safe to regenerate)"
      ((FIXABLE_FOUND++)) || true
    else
      echo "  [REVIEW]   copilot-out-of-sync      .github/copilot-instructions.md  (regenerating would drop a line currently in the file — review first)"
      ((REVIEW_FOUND++)) || true
    fi
  fi
fi

echo ""

if [[ "$FIXABLE_FOUND" -eq 0 && "$REVIEW_FOUND" -eq 0 ]]; then
  echo "Clean — nothing to report."
  exit 0
fi

echo "Found $FIXABLE_FOUND fixable and $REVIEW_FOUND needing manual review."

if [[ "$FIX" -eq 0 && "$FIXABLE_FOUND" -gt 0 ]]; then
  echo "Run with --fix to apply the fixable ones."
fi

if [[ "$FIX" -eq 1 ]]; then
  # After fixing, only unresolved review items should fail the run.
  [[ "$REVIEW_FOUND" -gt 0 ]] && exit 1
  exit 0
fi

# Report-only mode: any drift at all should be visible to CI.
exit 1
