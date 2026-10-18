#!/usr/bin/env bash
set -euo pipefail

# Partial phpBB source backup for upgrades. Excludes the forum's files/ and
# config.php; the database is not backed up by this script.
usage() {
    cat <<'EOF'
Usage: backup-forum.sh FORUM_DIR [BACKUP_DIR]

Create a timestamped .tar.gz archive of FORUM_DIR, excluding its top-level
files/ directory and config.php. Hidden files and symlinks are preserved.
BACKUP_DIR defaults to the current directory and must be outside FORUM_DIR.

Example: ./backup-forum.sh /var/www/forum /var/backups/phpbb
EOF
}

if [[ ${1:-} == --help || ${1:-} == -h ]]; then
    usage
    exit 0
fi

if (( $# < 1 || $# > 2 )); then
    usage >&2
    exit 1
fi

if [[ ! -d $1 ]]; then
    printf 'Error: forum directory does not exist: %s\n' "$1" >&2
    exit 1
fi

forum_dir=$(cd -- "$1" && pwd -P)
mkdir -p -- "${2:-.}"
backup_dir=$(cd -- "${2:-.}" && pwd -P)

# Archiving into the source tree could include the archive itself.
if [[ $forum_dir == / || $backup_dir == "$forum_dir" || $backup_dir == "$forum_dir/"* ]]; then
    printf 'Error: backup directory must be outside the forum directory.\n' >&2
    exit 1
fi

# mktemp prevents collisions and creates a private archive (mode 600).
archive_path=$(mktemp "$backup_dir/forum-source-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX.tar.gz")
trap 'rm -f -- "$archive_path"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

tar -czf "$archive_path" \
    --exclude='./files' \
    --exclude='./config.php' \
    -C "$forum_dir" .

trap - EXIT HUP INT TERM
printf 'Source backup created: %s\n' "$archive_path"
