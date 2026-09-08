#!/usr/bin/env bash

set -Eeuo pipefail

release_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$release_root"

if [[ ! -d .git ]]; then
  echo "build-release must run from a Git checkout." >&2
  exit 1
fi
if [[ -n "$(git status --porcelain)" && "${ALLOW_DIRTY_RELEASE:-0}" != "1" ]]; then
  echo "Refusing to package a dirty working tree." >&2
  exit 1
fi

version="$(tr -d '[:space:]' < VERSION)"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
  echo "VERSION is not a supported semantic version." >&2
  exit 1
fi

output_dir="${1:-$release_root/dist}"
mkdir -p "$output_dir"
output_dir="$(cd "$output_dir" && pwd)"
archive="$output_dir/telegram-cpanel-manager-$version.zip"
checksum="$archive.sha256"
temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/tcm-release.XXXXXX")"
stage="$temporary_root/package"
trap 'rm -rf -- "$temporary_root"' EXIT
mkdir -p "$stage"

git archive --format=tar HEAD | tar -xf - -C "$stage"
rm -rf -- "$stage/.github" "$stage/tests" "$stage/scripts"
rm -f -- "$stage/.gitignore" "$stage/phpunit.xml.dist"

for directory in cache logs temp sessions locks backups downloads; do
  mkdir -p "$stage/storage/$directory"
done
printf '%s\n' 'Options -Indexes' 'Require all denied' > "$stage/storage/.htaccess"

source_commit="$(git rev-parse HEAD)"
source_tree="$(git rev-parse HEAD^{tree})"
source_epoch="${SOURCE_DATE_EPOCH:-$(git show -s --format=%ct HEAD)}"
built_at="$(date -u -d "@$source_epoch" '+%Y-%m-%dT%H:%M:%SZ')"
file_count="$(find "$stage" -type f | wc -l | tr -d '[:space:]')"
printf '{\n  "name": "telegram-cpanel-manager",\n  "version": "%s",\n  "source_commit": "%s",\n  "source_tree": "%s",\n  "built_at": "%s",\n  "files_before_manifest": %s\n}\n' \
  "$version" "$source_commit" "$source_tree" "$built_at" "$file_count" > "$stage/BUILD-MANIFEST.json"

if grep -ERq --exclude='.env.example' --exclude='CHECKSUMS.sha256' '[0-9]{6,12}:[A-Za-z0-9_-]{30,}' "$stage"; then
  echo "A Telegram-token-shaped value was found in the release payload." >&2
  exit 1
fi
if grep -ERq -- '-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----' "$stage"; then
  echo "A private key was found in the release payload." >&2
  exit 1
fi
if [[ -e "$stage/.env" || -e "$stage/storage/installed.lock" ]]; then
  echo "Runtime configuration leaked into the release payload." >&2
  exit 1
fi

(
  cd "$stage"
  while IFS= read -r -d '' file; do
    sha256sum "$file"
  done < <(find . -type f ! -name CHECKSUMS.sha256 -print0 | LC_ALL=C sort -z) \
    | sed 's#  \./#  #' > CHECKSUMS.sha256
)
find "$stage" -exec touch -h -d "@$source_epoch" {} +

rm -f -- "$archive" "$checksum"
(
  cd "$stage"
  find . -mindepth 1 -print | LC_ALL=C sort | zip -q -X "$archive" -@
)
unzip -tqq "$archive"

for required in .htaccess VERSION composer.json README.md README.fa.md docs/IMPLEMENTED.md docs/TESTED.md docs/SECURITY.md docs/INSTALLATION_TEST.md docs/LIMITATIONS.md docs/ACCEPTANCE.md public/install.php public/index.php public/miniapp/index.html cli/cron.php cli/worker.php cli/update.php database/migrations/001_initial.sql CHECKSUMS.sha256 BUILD-MANIFEST.json; do
  if ! unzip -Z1 "$archive" | sed 's#^\./##' | grep -Fxq "$required"; then
    echo "Release archive is missing $required." >&2
    exit 1
  fi
done
if unzip -Z1 "$archive" | sed 's#^\./##' | grep -Eq '(^|/)\.env$|(^|/)installed\.lock$'; then
  echo "Release archive contains runtime secrets or an installation lock." >&2
  exit 1
fi

sha256sum "$archive" | sed "s#  $output_dir/##" > "$checksum"
printf 'Release: %s\nSHA-256: %s\n' "$archive" "$(cut -d ' ' -f1 "$checksum")"
