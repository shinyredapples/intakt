#!/bin/sh
# Installs GitHub's CLI from its official apt repository when the runner has none or one older than 2.48: a CI_RUNS_ON
# runner need not have it, and bin/journey-record and bin/journey-alarm pass --slurp, which needs 2.48 (newer than
# Ubuntu's package). Only the signing key pinned below goes into apt's keyring, so a swapped key download installs nothing.
set -eu
recent_enough() {
    [ -n "$1" ] || return 1
    version=$("$1" --version | sed -n '1s/^gh version \([0-9][0-9]*\)\.\([0-9][0-9]*\).*/\1 \2/p')
    [ -n "$version" ] || return 1
    major=${version% *}
    minor=${version#* }
    [ "$major" -gt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -ge 48 ]; }
}
current=$(command -v gh || true)
if recent_enough "$current"; then
    "$current" --version
    exit 0
fi
fingerprint=7F38BBB59D064DBCB3D84D725612B36462313325
keyring=/etc/apt/keyrings/githubcli-archive-keyring.gpg
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
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
installed=$(dpkg -L gh | sed -n '/\/bin\/gh$/{p;q;}')
if ! recent_enough "$installed"; then
    echo "The installed GitHub CLI ${installed:-(not found in the gh package)} is older than 2.48." >&2
    exit 1
fi
# An older gh earlier on PATH would still run in the job's later steps, so the installed one's folder goes ahead of it.
current=$(command -v gh || true)
if [ "$current" != "$installed" ]; then
    echo "${installed%/*}" >> "${GITHUB_PATH:?The older GitHub CLI $current comes before $installed on PATH and GITHUB_PATH is unset}"
fi
"$installed" --version
