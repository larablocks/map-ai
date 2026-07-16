# BUGS.md
_Known bugs — updated by Claude on discovery or after test failures_
_Claude writes immediately on discovery — do not wait for session end_
_Fixed and verified bugs move to docs/BUGS_ARCHIVE.md immediately — one at a time, never batched_

<!-- Severity: blocking=no further work | high=no workaround | medium=workaround exists | low=minor -->
<!-- Merge conflicts: this file has merge=union in .gitattributes, so concurrent additions from
     different branches combine automatically instead of producing conflict markers. That does
     NOT catch two branches independently assigning the same BUG-N. After merging, scan the
     combined file (and docs/BUGS_ARCHIVE.md) for duplicate BUG-N headers — keep whichever entry
     comes first, renumber the other to the next free number, and fix any references to the old
     number in this file, docs/BUGS_ARCHIVE.md, and docs/qa/*.md. -->

## Open bugs
<!-- BUG-N: scan BOTH this file and docs/BUGS_ARCHIVE.md for the highest existing number and increment by 1 — numbers are never reused -->
<!-- Format:
### BUG-[N] — [Short title]
- **Discovered:** YYYY-MM-DD via [test failure / code review / runtime]
- **Affects:** [file or module]
- **Severity:** [blocking / high / medium / low]
- **Description:** [What is wrong]
- **Blocking:** [What this prevents, or NONE]
- **Status:** open / investigating
-->

## Fixed bugs
<!-- Move here when resolved, then to docs/BUGS_ARCHIVE.md as soon as the fix is verified — do not let this section accumulate -->
<!-- Format:
### BUG-[N] — [Short title] ✓
- **Fixed:** YYYY-MM-DD
- **Fix:** [What was done]
- **Covered by:** [test name or file]
-->
