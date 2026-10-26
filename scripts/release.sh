#!/usr/bin/env bash
#
# Cut a release in one step: promote the CHANGELOG's Unreleased notes to a dated
# version heading, commit them, create the annotated tag, and push both. The
# deploy workflow refuses to ship a tag that CHANGELOG.md does not document, so
# the notes and the tag have to land together.
#
#   scripts/release.sh 1.1.0
#   scripts/release.sh 1.1.0 --no-push
#
set -euo pipefail

changelog="CHANGELOG.md"
version=""
push=1

usage() {
    cat <<'EOF'
usage: scripts/release.sh <version> [--no-push]

Promotes the CHANGELOG's "Unreleased" notes to "## [<version>] - <today>",
commits that, tags it as v<version>, and pushes the branch and the tag.

  --no-push   commit and tag locally without pushing
EOF
}

die() {
    printf 'release: %s\n' "$1" >&2
    exit 1
}

while (($#)); do
    case "$1" in
        --no-push) push=0 ;;
        -h|--help) usage; exit 0 ;;
        -*) die "unknown option: $1" ;;
        *)
            [[ -z $version ]] || die "expected one version, got '$version' and '$1'"
            version="$1"
            ;;
    esac
    shift
done

if [[ -z $version ]]; then
    usage >&2
    exit 2
fi

if [[ ! $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$ ]]; then
    die "'$version' is not a semantic version such as 1.2.3 or 1.2.0-rc.1"
fi

cd "$(git rev-parse --show-toplevel)"

[[ -f $changelog ]] || die "$changelog not found"

branch=$(git symbolic-ref --quiet --short HEAD) \
    || die "HEAD is detached; check out the branch you want to release from"

while IFS= read -r line; do
    [[ -z $line ]] && continue
    [[ ${line:3} == "$changelog" ]] \
        || die "the working tree has uncommitted changes; commit or stash them first ($line)"
done < <(git status --porcelain --untracked-files=no)

git fetch --quiet --tags origin 2>/dev/null \
    || printf 'release: warning: could not fetch origin, using local refs\n' >&2

if git rev-parse --quiet --verify 'HEAD@{upstream}' >/dev/null; then
    behind=$(git rev-list --count 'HEAD..@{upstream}')
    ((behind == 0)) || die "$branch is $behind commit(s) behind @{upstream}; pull first"
fi

git rev-parse --quiet --verify "refs/tags/v$version" >/dev/null \
    && die "tag v$version already exists"

if ((push)) && git ls-remote --exit-code --tags origin "refs/tags/v$version" >/dev/null 2>&1; then
    die "tag v$version already exists on origin"
fi

latest=$(git tag --list 'v[0-9]*' --sort=-v:refname | head -n1)
if [[ -n $latest ]]; then
    newest=$(printf '%s\n%s\n' "${latest#v}" "$version" | sort -V | tail -n1)
    [[ $newest == "$version" ]] || die "v$version is older than the latest tag, $latest"
fi

grep -qxF '## [Unreleased]' "$changelog" \
    || die "$changelog has no '## [Unreleased]' heading"

if ! awk '
    /^## \[Unreleased\]$/ { inside = 1; next }
    /^## \[/ { inside = 0 }
    inside && NF { found = 1 }
    END { exit found ? 0 : 1 }
' "$changelog"; then
    die "the Unreleased section is empty; write the release notes first"
fi

released_on=$(date +%F)
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

awk -v version="$version" -v released_on="$released_on" '
    { print }
    /^## \[Unreleased\]$/ && !inserted {
        print ""
        print "## [" version "] - " released_on
        inserted = 1
    }
' "$changelog" > "$tmp"

mv "$tmp" "$changelog"

git add -- "$changelog"
git commit --quiet --message "Release v$version"
git tag --annotate "v$version" --message "Release v$version"

if ((push)); then
    git push origin "refs/heads/$branch:refs/heads/$branch" "refs/tags/v$version"
    printf 'Released v%s on %s. The deploy workflow ships it once CI passes.\n' \
        "$version" "$branch"
else
    printf 'Committed and tagged v%s locally. Push it with:\n  git push origin %s v%s\n' \
        "$version" "$branch" "$version"
fi
