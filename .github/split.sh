#!/usr/bin/env bash
# Splits each directory listed in .github/split.json off into the history of its own repository, the
# read-only copy that Composer installs it from. The same history always gives the same commits, so
# every push is a fast-forward.
#
# An entry with a "build" script (the skeleton, built from the application) is that script's output
# instead, at that commit: committed on top of the repository's main (or its main itself when nothing
# changed), with the commit's message, author and dates, so building it again gives the same commit.
#
#   .github/split.sh <ref>          shows the commit of each repository at <ref>
#   .github/split.sh <ref> --push   pushes them: a branch to that branch, a tag to the same tag
#
# Pushing needs a token that may write to the repositories, in GIT_TOKEN. SPLIT_REMOTE replaces
# https://github.com, e.g. to try it with local repositories.
set -euo pipefail

ref=${1:?Usage: .github/split.sh <ref> [--push]}
push=${2:-}

case "$ref" in
	refs/heads/* | refs/tags/*) target=$ref ;;
	*) target=refs/heads/$ref ;;
esac

if [ "$push" = '--push' ]; then
	auth=$(printf 'x-access-token:%s' "${GIT_TOKEN:?GIT_TOKEN is needed to push}" | base64 -w0)
	if [ -n "${GITHUB_ACTIONS:-}" ]; then
		echo "::add-mask::$auth"
	fi
fi

commit=$(git rev-parse --verify "$ref^{commit}")
git_dir=$(git rev-parse --absolute-git-dir)

# Git with the token, when there is one (reading the public repositories needs none)
remote_git() {
	if [ -n "${auth:-}" ]; then
		git -c http.extraheader="AUTHORIZATION: basic $auth" "$@"
	else
		git "$@"
	fi
}

# The commit of a built entry: its build script's output at $commit, on top of the repository's main
built() {
	local repository=$1 build=$2 work tree parent=''
	work=$(mktemp -d)
	git worktree add --quiet --detach "$work/source" "$commit"
	"$work/source/$build" "$work/out" >&2
	git worktree remove --force "$work/source"
	tree=$(cd "$work/out" && GIT_DIR=$git_dir GIT_WORK_TREE=$work/out GIT_INDEX_FILE=$work/index git add --all --force . && GIT_DIR=$git_dir GIT_INDEX_FILE=$work/index git write-tree)
	rm -rf "$work"

	if remote_git fetch --quiet "${SPLIT_REMOTE:-https://github.com}/$repository.git" refs/heads/main 2>/dev/null; then
		parent=$(git rev-parse FETCH_HEAD)
	fi

	if [ -n "$parent" ] && [ "$(git rev-parse "$parent^{tree}")" = "$tree" ]; then
		echo "$parent"

		return
	fi

	GIT_AUTHOR_NAME=$(git log -1 --format=%an "$commit") GIT_AUTHOR_EMAIL=$(git log -1 --format=%ae "$commit") GIT_AUTHOR_DATE=$(git log -1 --format=%aI "$commit") \
	GIT_COMMITTER_NAME=$(git log -1 --format=%cn "$commit") GIT_COMMITTER_EMAIL=$(git log -1 --format=%ce "$commit") GIT_COMMITTER_DATE=$(git log -1 --format=%cI "$commit") \
		git commit-tree "$tree" ${parent:+-p "$parent"} -m "$(git log -1 --format=%s "$commit")" -m "Built from quirecms/framework $commit (.github/skeleton.sh)."
}

jq -r '.[] | "\(.path) \(.repository) \(.build // "")"' "$(dirname "$0")/split.json" | while read -r path repository build; do
	if [ -n "$build" ]; then
		split=$(built "$repository" "$build")
	else
		split=$(git subtree split --quiet --prefix="$path" "$commit")
	fi
	echo "$path at $commit: $repository $split"

	if [ "$push" = '--push' ]; then
		remote_git push --quiet "${SPLIT_REMOTE:-https://github.com}/$repository.git" "$split:$target"
	fi
done
