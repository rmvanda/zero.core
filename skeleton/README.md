# The skeleton

`core/skeleton/` is two things:

- the template a new site is built from (`zero-init.sh`), and
- the default core falls back to: a site that has no frame piece (`head`, `header`,
  `sideNav`, `footer`) or no `error_codes.ini` of its own gets the one here. A site overrides
  by having the file.

It depends on nothing outside core: plain HTML, one stylesheet, no scripts, no web fonts.

## Starting a site

The site is a repository with core as a submodule at `core/`.

```bash
git init mysite && cd mysite
git submodule add <zero.core repository> core
git submodule update --init --recursive   # core's own entity/ submodule (Zero\Entity\Event); sign-in needs it
core/skeleton/zero-init.sh            # or: core/skeleton/zero-init.sh /path/to/site mysite.com
```

The script builds the site folder around `core/` and prints the next steps: fill in
`app/config/database.ini`, load `core/sql/core.sql`, make `storage/cache/` writable by the
web server, install a vhost from `etc/`. It never overwrites a file that exists, so running it
again is safe.

## What is here

| Path | What it is |
|------|------------|
| `frontend/frame/` | `head`, `header`, `sideNav`, `footer`: the page around every module view. Wording comes from `SITE_*` constants and falls back to English. |
| `frontend/www/` | Web root: `index.php` (defines `ZERO_ROOT` and loads core), `.htaccess` (sends every non-file URL to `index.php`), `assets/css/main.css` (light and dark by `prefers-color-scheme`). |
| `strings/site.ini` | The site's wording: `SITE_NAME` and the labels the frames show. |
| `modules.ini` | Which modules are on; lists only departures from the defaults. |
| `modules/Index/` | The landing page module. |
| `config-templates/` | Blank `config.ini`, `database.ini`, `constants.ini`, and `allowed-ips.lst` (the clients that are never banned). Copied to `app/config/` (never committed). |
| `config/` | Defaults core itself reads when `app/config/` has no file of the same name. |
| `etc/` | Apache and nginx vhost samples (`{{DOMAIN}}` and `{{SITE_DIR}}` are filled in). |
| `zero-init.sh` | Builds the site folder from the above. |
