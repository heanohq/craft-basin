#!/usr/bin/env bash
# Writes the thread the triage run reads into .triage/: the issue and its
# comments (thread.json) and the repository's other issues (issues.json).
#
# With FIXTURE set, uses .github/triage/fixtures/$FIXTURE.json as the thread
# instead of a real issue. Otherwise needs GH_TOKEN (issues: read), GH_REPO and
# ISSUE.
set -euo pipefail

mkdir -p .triage

if [ -n "${FIXTURE:-}" ]; then
  [[ "$FIXTURE" =~ ^[a-z0-9-]+$ ]] || { echo "Bad fixture name"; exit 1; }
  cp ".github/triage/fixtures/$FIXTURE.json" .triage/thread.json
  echo '[]' > .triage/issues.json
  exit 0
fi

gh api "repos/$GH_REPO/issues/$ISSUE" > .triage/issue.json
gh api --paginate "repos/$GH_REPO/issues/$ISSUE/comments?per_page=100" | jq -s 'add // []' > .triage/comments.json
jq -n --slurpfile i .triage/issue.json --slurpfile c .triage/comments.json '
  $i[0] as $i | {
    number: $i.number, title: $i.title, state: $i.state,
    labels: [$i.labels[].name],
    author: $i.user.login, author_association: $i.author_association,
    created_at: $i.created_at, body: ($i.body // ""),
    comments: [$c[0][] | {author: .user.login, author_association, created_at, body: (.body // "")}]
  }' > .triage/thread.json
rm .triage/issue.json .triage/comments.json

gh api --paginate "repos/$GH_REPO/issues?state=all&per_page=100" \
  --jq ".[] | select(.pull_request | not) | select(.number != $ISSUE) | {number, title, state}" |
  jq -s '.' > .triage/issues.json
