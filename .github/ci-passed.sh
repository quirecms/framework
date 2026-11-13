#!/usr/bin/env bash
# Waits for CI's run on main for a commit and fails unless it passed: a version tag publishes nothing
# (no release, no feed, no packages) for a commit that hasn't. The tag is usually pushed while that run
# is still going, so it waits, 45 minutes at most. Only CI's runs for the push to main count, the latest
# one first: to try again, re-run that run ("Run workflow" by hand makes another kind of run, which doesn't).
#
#   .github/ci-passed.sh <commit>
#
# Needs GH_TOKEN (actions: read) and GITHUB_REPOSITORY.
set -euo pipefail

commit=${1:?Usage: .github/ci-passed.sh <commit>}

# CI's state for the commit; GitHub's API answering with an error is tried again, 3 times at most.
ci_state() {
    local runs
    for attempt in 1 2 3; do
        if runs=$(gh api -X GET "repos/$GITHUB_REPOSITORY/actions/workflows/ci.yaml/runs" \
            -f head_sha="$commit" -f branch=main -f event=push -F per_page=100 --paginate --slurp); then
            jq -r 'map(.workflow_runs[]) | sort_by(.run_started_at) | last
                | if . == null then "missing" elif .status != "completed" then "running" else .conclusion end' <<<"$runs"
            return
        fi
        [ "$attempt" = 3 ] || sleep 10
    done
    return 1
}

for _ in $(seq 90); do
    state=$(ci_state)
    case "$state" in
        success) echo "CI passed on $commit."; exit 0 ;;
        running|missing) sleep 30 ;;
        *) echo "::error::CI on main for $commit: $state. Nothing is published without it: re-run CI's run for that commit, then this workflow."; exit 1 ;;
    esac
done

echo "::error::CI on main for $commit: still $state after 45 minutes. Re-run this workflow once CI's run for that commit has passed."
exit 1
