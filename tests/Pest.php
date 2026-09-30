<?php

/**
 * Found running --fix on larablocks.com: it swapped the project's own italic area note
 * back to the stub's, and re-added placeholder lines the project had deleted.
 */
function fillLikeARealProject(string $root): void
{
    $edits = [
        'docs/TESTING_COVERAGE.md' => [
            "_Files where a bug causes data loss, credential exposure, or irreversible side-effects._\n",
            "_Auth, tenant DB routing, and schema migration commands — a bug here causes wrong-database writes, auth bypass, or a broken schema migration._\n",
        ],
        'docs/CODE_PATTERNS.md' => ["[Things in the codebase that should not be copied. Name the specific file/class.]\n", ''],
        'docs/METRICS_HISTORY.md' => ["| Milestones complete | [N]/[M] | — (remove row if not using phases) |\n", ''],
    ];
    foreach ($edits as $file => [$from, $to]) {
        $path = "$root/$file";
        file_put_contents($path, str_replace($from, $to, (string) file_get_contents($path)));
    }
}
