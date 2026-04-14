#!/bin/bash
# zero-init.sh — Stand up a new Zero Framework project
#
# Usage:
#   ./zero-init.sh <site-directory> [domain]
#
# Examples:
#   ./zero-init.sh /var/www/html/mysite.com
#   ./zero-init.sh /var/www/html/mysite.com mysite.com
#
# If domain is omitted, it's derived from the directory name.
#
# Creates:
#
#   mysite.com/
#   ├── etc/                                (Apache/Nginx vhost configs)
#   ├── www -> zero/app/frontend/www/       (symlink — document root)
#   └── zero/
#       ├── core/                           (cloned from rmvanda/zero.core)
#       │   └── skeleton/                   (template files — left in place for reference)
#       ├── app/                            (your app — git-initialized)
#       │   ├── _config_templates/          (reference copies — don't edit)
#       │   ├── config/                     (your config — edit these)
#       │   └── frontend/
#       │       ├── frame/                  (head, header, footer, sideNav)
#       │       └── www/                    (webroot: index.php, .htaccess, assets/)
#       │           └── shadow-component/   (cloned from rmvanda/shadowjs)
#       └── modules/
#           └── Index/                      (barebones landing page module)

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

# ─── Validate input ─────────────────────────────────────────────────
if [ $# -lt 1 ]; then
    echo -e "${BOLD}Usage:${NC} $0 <site-directory> [domain]"
    echo ""
    echo "  Examples:"
    echo "    $0 /var/www/html/mysite.com"
    echo "    $0 /var/www/html/mysite.com mysite.com"
    echo ""
    echo "  The directory will be created if it doesn't exist."
    echo "  If it already exists, it must be empty."
    exit 1
fi

SITE_DIR="$(realpath -m "$1")"
DOMAIN="${2:-$(basename "$SITE_DIR")}"

command -v git >/dev/null 2>&1 || fail "git is required but not installed."
command -v php >/dev/null 2>&1 || fail "php is required but not installed."

# If directory exists, make sure it's empty
if [ -d "$SITE_DIR" ]; then
    if [ "$(ls -A "$SITE_DIR" 2>/dev/null)" ]; then
        fail "$SITE_DIR already exists and is not empty."
    fi
fi

info "Creating project ${BOLD}$DOMAIN${NC} at ${BOLD}$SITE_DIR${NC}"

# ─── Clone zero.core ─────────────────────────────────────────────────
# Core ships the skeleton — clone it first, then copy skeleton out
mkdir -p "$SITE_DIR/zero"
info "Cloning zero.core..."
git clone git@github.com:rmvanda/zero.core "$SITE_DIR/zero/core"
ok "zero.core cloned."

SKEL="$SITE_DIR/zero/core/skeleton"

if [ ! -d "$SKEL/frontend" ]; then
    fail "Skeleton not found in cloned core. Expected: $SKEL/frontend"
fi

# ─── Copy app from skeleton ──────────────────────────────────────────
info "Setting up app directory..."
mkdir -p "$SITE_DIR/zero/app"

cp -r "$SKEL/frontend"          "$SITE_DIR/zero/app/frontend"
cp -r "$SKEL/_config_templates" "$SITE_DIR/zero/app/_config_templates"

# Create editable config from templates
mkdir -p "$SITE_DIR/zero/app/config"
cp "$SKEL/_config_templates/"*.ini "$SITE_DIR/zero/app/config/"
ok "App skeleton copied."

# ─── Clone ShadowComponent ──────────────────────────────────────────
info "Cloning shadow-component..."
git clone git@github.com:rmvanda/shadowjs "$SITE_DIR/zero/app/frontend/www/shadow-component"
ok "shadow-component cloned."

# ─── Set up Index module ─────────────────────────────────────────────
info "Creating Index module..."
mkdir -p "$SITE_DIR/zero/modules/Index/view"
cp "$SKEL/modules/Index/Index.php"      "$SITE_DIR/zero/modules/Index/Index.php"
cp "$SKEL/modules/Index/view/index.php" "$SITE_DIR/zero/modules/Index/view/index.php"
ok "Index module created."

# ─── Create www symlink ──────────────────────────────────────────────
info "Creating www symlink..."
ln -s "$SITE_DIR/zero/app/frontend/www" "$SITE_DIR/www"
ok "www -> zero/app/frontend/www/"

# ─── Generate vhost configs ─────────────────────────────────────────
info "Generating webserver configs..."
mkdir -p "$SITE_DIR/etc"

for TPL in "$SKEL/etc/"*.conf; do
    BASENAME="$(basename "$TPL")"
    sed -e "s|{{DOMAIN}}|$DOMAIN|g" \
        -e "s|{{SITE_DIR}}|$SITE_DIR|g" \
        "$TPL" > "$SITE_DIR/etc/$BASENAME"
done
ok "Configs written to etc/  (apache.conf, apache-ssl.conf, nginx.conf)"

# ─── Substitute placeholders in app config ───────────────────────────
info "Configuring for ${BOLD}$DOMAIN${NC}..."
sed -i "s|example.com|$DOMAIN|g"                          "$SITE_DIR/zero/app/config/config.ini"
sed -i "s|/var/www/html/example.com/www|$SITE_DIR/www|g"  "$SITE_DIR/zero/app/config/config.ini"
sed -i "s|My Zero App|$DOMAIN|g"                          "$SITE_DIR/zero/app/config/config.ini"
ok "Config updated."

# ─── Git init the app directory ──────────────────────────────────────
info "Initializing git repo for app..."
git -C "$SITE_DIR/zero/app" init
git -C "$SITE_DIR/zero/app" add -A
git -C "$SITE_DIR/zero/app" commit -m "Initial app skeleton"
ok "app/ is now a git repo."

# ─── Set permissions ─────────────────────────────────────────────────
info "Setting permissions..."
find "$SITE_DIR" -type d -exec chmod 775 {} \;
find "$SITE_DIR" -type f -exec chmod 664 {} \;

CURRENT_USER="$(whoami)"
CURRENT_GROUP="$(id -gn)"
ok "Permissions set (owner: $CURRENT_USER:$CURRENT_GROUP)."

# ─── Summary ─────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}Project created successfully!${NC}"
echo ""
echo -e "  ${BOLD}Domain:${NC}    $DOMAIN"
echo -e "  ${BOLD}Location:${NC}  $SITE_DIR"
echo -e "  ${BOLD}Webroot:${NC}   $SITE_DIR/www  (symlink)"
echo ""
echo -e "${BOLD}Next steps:${NC}"
echo ""
echo "  1. Edit your config:"
echo "       $SITE_DIR/zero/app/config/config.ini"
echo "       $SITE_DIR/zero/app/config/database.ini"
echo ""
echo "  2. Install the vhost config (pick one):"
echo ""
echo "     Apache:"
echo "       sudo cp $SITE_DIR/etc/apache.conf /etc/httpd/sites-available/$DOMAIN.conf"
echo "       sudo ln -s /etc/httpd/sites-available/$DOMAIN.conf /etc/httpd/sites-enabled/"
echo "       sudo systemctl reload httpd"
echo ""
echo "     Nginx:"
echo "       sudo cp $SITE_DIR/etc/nginx.conf /etc/nginx/conf.d/$DOMAIN.conf"
echo "       sudo nginx -t && sudo systemctl reload nginx"
echo ""
echo "  3. (Optional) Set up SSL:"
echo "       sudo certbot --apache -d $DOMAIN"
echo "       # or: sudo certbot --nginx -d $DOMAIN"
echo ""
echo "  4. Start building modules:"
echo "       $SITE_DIR/zero/modules/"
echo ""

# Ownership note
if [ "$CURRENT_USER" != "apache" ] && [ "$CURRENT_USER" != "www-data" ] && [ "$CURRENT_USER" != "nginx" ]; then
    warn "Files are owned by ${BOLD}$CURRENT_USER:$CURRENT_GROUP${NC}. If your webserver runs"
    warn "as a different user, you may need to:  chown -R apache:apache $SITE_DIR"
fi
