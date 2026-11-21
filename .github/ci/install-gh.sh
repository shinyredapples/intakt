#!/bin/sh
# Installs GitHub's CLI from its official apt repository when the runner has none: a CI_RUNS_ON runner need not have it,
# and bin/journey-record, bin/journey-alarm and release-latest.sh need 2.48 or later (--slurp), newer than Ubuntu's.
# Only the signing key pinned below goes into apt's keyring, so a swapped key download installs nothing.
set -eu
if command -v gh > /dev/null; then
    exit 0
fi
fingerprint=7F38BBB59D064DBCB3D84D725612B36462313325
keyring=/etc/apt/keyrings/githubcli-archive-keyring.gpg
work=$(mktemp -d)
curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o "$work/download.gpg"
GNUPGHOME="$work" gpg --quiet --import "$work/download.gpg"
GNUPGHOME="$work" gpg --export "$fingerprint" > "$work/pinned.gpg"
if [ ! -s "$work/pinned.gpg" ]; then
    echo "The GitHub CLI key download lacks the key $fingerprint." >&2
    exit 1
fi
sudo install -D -m 644 "$work/pinned.gpg" "$keyring"
echo "deb [arch=$(dpkg --print-architecture) signed-by=$keyring] https://cli.github.com/packages stable main" \
    | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null
sudo apt-get update -qq
sudo apt-get install -y -qq gh
gh --version
