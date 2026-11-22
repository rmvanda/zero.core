# The skeleton

`core/skeleton/` is two things:

- the template a new site is built from (`zero-init.sh`), and
- the default core falls back to: a site that has no frame piece, no config file of a name
  in `config/`, or no wording for a key in `strings/` gets the one here. A site overrides by
  having the file or the key. See `core/docs/ZERO.md`, "What a site may leave out".

It depends on nothing outside core: plain HTML, one stylesheet, no scripts, no web fonts.

## Starting a site

The site is a repository with core as a submodule at `core/`.

```bash
git init mysite && cd mysite
git submodule add <zero.core repository> core
git submodule update --init --recursive   # core's own entity/ submodule (Zero\Entity\Event); sign-in needs it
core/skeleton/zero-init.sh            # or: core/skeleton/zero-init.sh /path/to/site mysite.com
```

The script builds the site folder around `core/`: `app/` (config, strings, `modules.ini`,
frames and web root), an empty `modules/` and an empty `entity/` for the site's own entity classes,
`storage/cache/` and `etc/`. It clones nothing and needs no root. It never overwrites a file
that exists, so running it again is safe. Without a domain argument the folder's name stands
in for `PRIMARY_DOMAIN` in `app/config/config.ini`.

It prints the next steps: fill in `app/config/database.ini`, load `core/sql/core.sql`, make
`storage/cache/` writable by the web server, install a vhost from `etc/` (document root
`app/frontend/www/`).

## What is here

| Path | What it is |
|------|------------|
| `frontend/frame/` | `head`, `header`, `sideNav`, `footer`: the page around every module view, and the partials the bundled views load (`_head-scripts`, `_oauth-brand`, `_oauth-buttons`, `_google-signin`). Wording comes from `SITE_*` constants. |
| `frontend/www/` | Web root: `index.php` (defines `ZERO_ROOT` and loads core), `.htaccess` (sends every non-file URL to `index.php`), `assets/css/main.css` (light and dark by `prefers-color-scheme`). |
| `strings/site.ini` | The site's wording: `SITE_NAME` and the labels the frames show. Also core's English default for each label a site leaves out. |
| `modules.ini` | Which modules are on; lists only departures from the defaults. Copied to `app/modules.ini`. |
| `config-templates/` | Blank `config.ini`, `database.ini`, `constants.ini`, and `allowed-ips.lst` (the clients that are never banned). Copied to `app/config/` (never committed). |
| `config/` | Defaults core itself reads when `app/config/` has no file of the same name: `constants.ini`, `error_codes.ini`. Not copied. |
| `etc/` | Apache and nginx vhost samples. `{{DOMAIN}}` and `{{SITE_DIR}}` are filled in on the way to the site's `etc/`. |
| `zero-init.sh` | Builds the site folder from the above. |
