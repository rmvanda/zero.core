#!/bin/bash
# zero-init.sh — Build a Zero Framework site folder from core's skeleton
#
# Usage:
#   core/skeleton/zero-init.sh [site-directory] [domain]
#
# The script lives at {site}/core/skeleton/zero-init.sh, with core/ a git
# submodule (or a plain checkout) of the site. Without arguments it builds the
# site in the folder that contains core/. Nothing is cloned and nothing needs
# root; the only thing it reads is the skeleton beside it.
#
# It is safe to run again: a file that already exists is left exactly as it is
# and named in the output, so a site's own config, strings, frames and modules
# are never overwritten.
#
# Builds:
#
#   {site}/
#   ├── core/                  (already there: the framework)
#   ├── etc/                   Apache and nginx vhost samples for the domain
#   ├── entity/                the site's own entity classes
#   ├── modules/               the site's own modules (core's Index serves / until the site has one)
#   ├── storage/cache/         what core caches (must be writable by the web server)
#   └── app/
#       ├── config/            settings and secrets: fill in database.ini (never committed)
#       ├── strings/site.ini   the site's wording
#       ├── modules.ini        which modules are on
#       └── frontend/
#           ├── frame/         head, header, sideNav, footer
#           └── www/           web root: index.php, .htaccess, assets/

set -euo pipefail

# ─── Colors ──────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

info()  { echo -e "${CYAN}[zero]${NC} $1"; }
ok()    { echo -e "${GREEN}[zero]${NC} $1"; }
warn()  { echo -e "${YELLOW}[zero]${NC} $1"; }
fail()  { echo -e "${RED}[zero]${NC} $1" >&2; exit 1; }

# ─── Where the skeleton is ───────────────────────────────────────────
# Logical path (no symlink resolution): when core/ is a symlink or submodule of
# the site, the folder that contains it is the site.
SKEL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -d "$SKEL/frontend" ] || fail "Skeleton not found. Expected: $SKEL/frontend"
DEFAULT_SITE="$(cd "$SKEL/../.." && pwd)"

if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
    echo -e "${BOLD}Usage:${NC} $0 [site-directory] [domain]"
    echo ""
    echo "  Builds the site folder from core's skeleton. Without arguments the site"
    echo "  is the folder that contains core/: $DEFAULT_SITE"
    echo "  Files that already exist are skipped, so it is safe to run again."
    exit 0
fi

SITE_DIR="$(realpath -m "${1:-$DEFAULT_SITE}")"
DOMAIN_GIVEN="${2:-}"
DOMAIN="${2:-$(basename "$SITE_DIR")}"

info "Building ${BOLD}$DOMAIN${NC} at ${BOLD}$SITE_DIR${NC}"
if [ -z "$DOMAIN_GIVEN" ]; then
    warn "No domain given: PRIMARY_DOMAIN is a placeholder, the folder name \"$DOMAIN\". Edit it in app/config/config.ini."
fi

if [ ! -f "$SITE_DIR/core/Application.php" ]; then
    warn "No core/ in $SITE_DIR. The site needs the framework there, for example:"
    warn "  git submodule add <zero.core repository> core"
elif [ ! -f "$SITE_DIR/core/entity/Entity.php" ]; then
    warn "core/entity/Entity.php is missing: core's entity submodule is not checked out, and sign-in needs it. Run:"
    warn "  git submodule update --init --recursive"
fi

# ─── Helpers ─────────────────────────────────────────────────────────
CREATED=()
SKIPPED=()

# copy_file <source> <destination>: copy unless the destination exists.
# Sets COPIED=1 when it copied, 0 when the destination was already there.
copy_file() {
    local src="$1" dst="$2"
    if [ -e "$dst" ] || [ -L "$dst" ]; then
        SKIPPED+=("$dst")
        warn "exists, skipped: $dst"
        COPIED=0
        return 0
    fi
    mkdir -p "$(dirname "$dst")"
    cp "$src" "$dst"
    CREATED+=("$dst")
    COPIED=1
}

# copy_tree <source dir> <destination dir>: copy_file for every file below it
# (dotfiles included).
copy_tree() {
    local src="$1" dst="$2" file
    while IFS= read -r -d '' file; do
        copy_file "$file" "$dst/${file#"$src"/}"
    done < <(find "$src" -type f -print0)
}

# sed_escape <text>: text safe on the replacement side of s|...|...|
sed_escape() {
    printf '%s' "$1" | sed -e 's/[\\&|]/\\&/g'
}

# ─── Directories ─────────────────────────────────────────────────────
mkdir -p "$SITE_DIR/app/config" "$SITE_DIR/modules" \
         "$SITE_DIR/entity" "$SITE_DIR/etc"
# The cache holds what core generates at runtime; the web server writes to it.
mkdir -p -m 775 "$SITE_DIR/storage/cache"
chmod 775 "$SITE_DIR/storage" 2>/dev/null || true
touch "$SITE_DIR/entity/.gitkeep" 2>/dev/null || true

# ─── app/ ────────────────────────────────────────────────────────────
info "Setting up app/..."
copy_tree "$SKEL/frontend" "$SITE_DIR/app/frontend"
copy_tree "$SKEL/strings"  "$SITE_DIR/app/strings"
STRINGS_NEW="$COPIED"
copy_file "$SKEL/modules.ini" "$SITE_DIR/app/modules.ini"
# The Admin modules screen writes a lock file and temp files beside app/modules.ini;
# app/modules.ini itself is the site's committed choice.
for PATTERN in 'modules.ini.lock' '.modules.ini.*.tmp'; do
    grep -qxF -- "$PATTERN" "$SITE_DIR/app/.gitignore" 2>/dev/null || printf '%s\n' "$PATTERN" >> "$SITE_DIR/app/.gitignore"
done

# Blank templates, committed; the editable copy in app/config/ is never committed.
for TPL in "$SKEL/config-templates/"*.ini "$SKEL/config-templates/"*.lst; do
    NAME="$(basename "$TPL")"
    # TODO: the templates are core's (skeleton/config-templates/); a site copy in
    # app/config-tmpl/ is to be reworked.
    #copy_file "$TPL" "$SITE_DIR/app/config-tmpl/$NAME"
    copy_file "$TPL" "$SITE_DIR/app/config/$NAME"
    # Fill in what the script knows, only in a config.ini it has just created.
    if [ "$NAME" = "config.ini" ] && [ "$COPIED" = 1 ]; then
        D="$(sed_escape "$DOMAIN")"
        W="$(sed_escape "$SITE_DIR/app/frontend/www")"
        sed -i -e "s|/var/www/html/example.com/www|$W|g" \
               -e "s|example.com|$D|g" "$SITE_DIR/app/config/config.ini"
    fi
done
# The site's name is its domain until it is given a better one.
if [ "$STRINGS_NEW" = 1 ] && [ -f "$SITE_DIR/app/strings/site.ini" ]; then
    sed -i "s|My Zero App|$(sed_escape "$DOMAIN")|g" "$SITE_DIR/app/strings/site.ini"
fi
ok "app/ ready."

# ─── Web server samples ──────────────────────────────────────────────
info "Writing webserver samples..."
for TPL in "$SKEL/etc/"*.conf; do
    NAME="$(basename "$TPL")"
    DST="$SITE_DIR/etc/$NAME"
    if [ -e "$DST" ]; then
        SKIPPED+=("$DST")
        warn "exists, skipped: $DST"
        continue
    fi
    sed -e "s|{{DOMAIN}}|$(sed_escape "$DOMAIN")|g" \
        -e "s|{{SITE_DIR}}|$(sed_escape "$SITE_DIR")|g" "$TPL" > "$DST"
    CREATED+=("$DST")
done
ok "Samples in etc/  (apache.conf, apache-ssl.conf, nginx.conf)"

# ─── Summary ─────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}Done.${NC} ${#CREATED[@]} file(s) created, ${#SKIPPED[@]} already existed and were left alone."
echo ""
echo -e "  ${BOLD}Domain:${NC}    $DOMAIN"
echo -e "  ${BOLD}Location:${NC}  $SITE_DIR"
echo -e "  ${BOLD}Web root:${NC}  $SITE_DIR/app/frontend/www"
echo ""
echo -e "${BOLD}Next steps:${NC}"
echo ""
echo "  1. Fill in the database settings (this file is never committed):"
echo "       $SITE_DIR/app/config/database.ini"
echo "     and check the domain and mail addresses in:"
echo "       $SITE_DIR/app/config/config.ini"
echo ""
echo "  2. Create the tables core needs:"
echo "       mysql <database> < $SITE_DIR/core/sql/core.sql"
echo ""
echo "  3. Make storage/ writable by the web server user:"
echo "       $SITE_DIR/storage/cache"
echo ""
echo "  4. Install a vhost from etc/ (document root: $SITE_DIR/app/frontend/www):"
echo "       Apache:  $SITE_DIR/etc/apache.conf  (or apache-ssl.conf)"
echo "       Nginx:   $SITE_DIR/etc/nginx.conf"
echo ""
echo "  5. Open the site, then start building modules in:"
echo "       $SITE_DIR/modules/"
