#!/usr/bin/env sh
# Fails when a workflow uses an action by tag or branch instead of a full commit SHA.
# A tag can be moved to malicious code; a SHA cannot. Dependabot keeps the pins current.
# Local actions (./path) and docker images pinned by digest are allowed.

unpinned=$(grep -nE '^[[:space:]-]*uses:' .github/workflows/*.yml \
  | grep -vE 'uses:[[:space:]]*["'\'']?\./' \
  | grep -vE 'uses:[[:space:]]*["'\'']?docker://[^[:space:]]+@sha256:[0-9a-f]{64}' \
  | grep -vE 'uses:[[:space:]]*["'\'']?[^[:space:]@]+@[0-9a-f]{40}["'\'']?([[:space:]]|$)')

if [ -n "$unpinned" ]; then
  echo "error: these actions are not pinned to a full commit SHA:"
  echo "$unpinned"
  echo "pin them as owner/repo@<40-character sha> # vX.Y.Z"
  exit 1
fi

echo "every action is pinned to a commit SHA"
