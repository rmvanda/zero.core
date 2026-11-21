# Acceptance: a bare site

`BareSiteTest` proves the claim core makes: a folder built by `core/skeleton/zero-init.sh`, an
empty database loaded with `core/sql/core.sql`, and a `database.ini` that names it give a home
page and an email-link sign-in with no other code.

Each request is the temp site's `index.php` run in its own PHP process (`request-driver.php`).
The tests: the home page, the login page, register / verify-link / sign-in-link sign-in and the
profile page, `/admin` with and without `allow.admin`, a module switched off in `modules.ini`
answering 404, and a probe that every request ran on the throwaway database.

```bash
./vendor/bin/phpunit --testsuite Acceptance    # from the site root
```

## Needs

- A mysql client whose option files (`~/.my.cnf`) may create and drop `zerotest_bare_site`.
- The site's own database account (the one in `app/config/database.ini`) must have rights on
  `zerotest_bare_site`: the temp site connects with that account and the throwaway database name.
  `GRANT ALL ON zerotest_bare_site.* TO '<user>'@'<host>'` once, by an administrator.

Without either, every test skips with a message. The database and the temp folder are removed
after the run, pass or fail. No mail is sent: the temp site sets `MAIL_CAPTURE_DIR`, and the test
reads the link from the captured message.

## One run at a time

The test holds a lock on `<tmp>/zerotest_bare_site.lock` for the whole class. A run that cannot
take it skips with "another run is using zerotest_bare_site" and drops and removes nothing; a run
removes only the database and folder it created. Folders of killed runs (`zerotest-baresite-*`
older than an hour) are removed at the start of the next run. `BareSiteLockTest` proves the guard.

## Known gaps on a bare site

- `Sitemap` is `#[RequireAuthLevel(1)]` and a new account has auth level 0, so `/sitemap` answers
  401 for a signed-in user until the site raises that account's `auth.level`. The test raises it.
  Its page list also names pages a bare site does not have (`/about`, `/help`, `/zero-framework`,
  `/ruin-the-web`).
