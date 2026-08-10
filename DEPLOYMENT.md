# OzuPay Theme — Deployment

This is the theme source for **`ozupay.com`** — a WordPress block child theme (`Template:
twentytwentyfive`), its own git repo (`git@github.com:edwanyoike/ozupaytheme.git`), independent
of the `ozupay/` plugin monorepo. There is no build step and no packaging script: deploy is a
direct file copy to the live server. Read `ozulabs/STEERING.md` first if you haven't already —
the server table and deploy ritual there are binding background for everything below.

---

## Deploy target

| | |
|---|---|
| Site | `ozupay.com` only |
| Server | `root@152.53.52.32`, port `9304` |
| Path on server | `/var/www/ozupay/wp-content/themes/ozupay-theme/` |

**Never deploy this theme to `thogotodeli` or `ozulabs.com`.** `ozulabs.com` runs its own
independent *fork* of this theme, turned multi-plugin, sourced from the sibling
`ozulabs/ozulabs-theme/` repo — same codebase lineage, but a separate copy that is edited and
deployed separately. Don't bind-mount, symlink, or copy this directory into that site's install,
and don't assume a fix made here also needs porting there — check with the user, it's a
case-by-case call. `thogotodeli` doesn't run this theme at all.

The other WordPress install on this same server, `demo_ozupay`, also doesn't run this theme —
it uses `ozupay/demo/ozupay-demo-theme/` (a different repo, documented in `ozupay/DEPLOYMENT.md`).
If a change here is purely visual/OzuPay-branding, it does **not** need mirroring to the demo
theme by default — confirm with the user before touching that separate repo.

---

## Version discipline (mandatory before every deploy)

`style.css`'s header has a `Version:` field:

```
Version:     1.8.26
```

WordPress appends this as `?ver=X.Y.Z` on the enqueued stylesheet URL, and browsers cache by
that exact URL. Deploying a CSS change — or a `functions.php`/template change that alters
markup in a way existing CSS depends on — **without bumping this field** means returning
visitors keep getting the old, now-mismatched stylesheet against new markup. That's not merely
stale, it's actively broken: new HTML rendered against old CSS/JS reads as "the feature is
broken," not "your browser is out of date." This exact failure mode hit `ozupay/` for real on
2026-08-02 — see `ozulabs/STEERING.md`'s "Versioning discipline" section.

**Rule, no exceptions:** every time `style.css` changes, or `functions.php`/anything under
`templates/`/`parts/`/`woocommerce/` changes rendered HTML in a way that depends on CSS
classes, bump `Version:` (PATCH increment, e.g. `1.8.26` → `1.8.27`) in the same commit —
even for a one-line tweak. A version that has already been deployed is immutable: never edit
its assets and redeploy under the same number; bump first, then change. It's fine to fold
several edits into one version as long as none of them have been deployed yet — the line is
deployment, not the commit.

There is no build script for this theme — bump the field by hand, in the same commit as the
change that requires it.

---

## Deploy

Individual file `scp` is the normal path for this theme (no zip, no `wp plugin install`) —
mirror the source tree onto the server path 1:1. Run every command below from this repo's root
(`ozulabs/ozupay-theme/`).

```bash
# Example: deploy whatever actually changed (adjust the file list to match your diff)
scp -P 9304 style.css \
  root@152.53.52.32:/var/www/ozupay/wp-content/themes/ozupay-theme/

scp -P 9304 templates/front-page.html \
  root@152.53.52.32:/var/www/ozupay/wp-content/themes/ozupay-theme/templates/

scp -P 9304 parts/header.html parts/footer.html \
  root@152.53.52.32:/var/www/ozupay/wp-content/themes/ozupay-theme/parts/

scp -P 9304 functions.php \
  root@152.53.52.32:/var/www/ozupay/wp-content/themes/ozupay-theme/
```

For a change that touches many files, mirror the relevant subtree directly instead of listing
every file:

```bash
scp -P 9304 -r templates/ parts/ woocommerce/ \
  root@152.53.52.32:/var/www/ozupay/wp-content/themes/ozupay-theme/
```

### File ownership — always, right after the copy

`scp` writes files as `root`; PHP-FPM runs as `www-data`. Root-owned files under this path break
WordPress's own theme-editor writes and any runtime file access until fixed:

```bash
ssh -p 9304 root@152.53.52.32 '
  chown -R www-data:www-data /var/www/ozupay/wp-content/themes/ozupay-theme
'
```

**Verify before moving on** — don't just assume the `chown` ran clean:

```bash
ssh -p 9304 root@152.53.52.32 '
  find /var/www/ozupay/wp-content/themes/ozupay-theme ! -user www-data -print
'
```

Empty output means every file is correctly owned. Any line printed means a root-owned leftover
remains — re-run the `chown -R` above before proceeding. Do not flush caches while root-owned
files remain.

### Cache flush — always, every deploy

```bash
ssh -p 9304 root@152.53.52.32 '
  wp --path=/var/www/ozupay --allow-root cache flush
  rm -rf /var/cache/nginx/fastcgi_cache/*
  nginx -s reload
'
```

The nginx fastcgi cache is cleared and nginx reloaded on every deploy regardless of what else
was touched — that's the standing rule for any write under `/var/www` on this server, not a
theme-specific step.

---

## Rules

- Deploy every changed file in the same session as the commit — never leave a committed theme
  change undeployed.
- Bump `Version:` in `style.css` in the same commit as any change that requires it (see
  "Version discipline" above) — before deploying, not after.
- Never deploy to `thogotodeli` or `ozulabs.com` — see "Deploy target" above.
- Never deploy to `demo_ozupay` — that site runs the separate demo theme repo.
- `chown -R www-data:www-data` and verify with `find ! -user www-data` after every deploy,
  before the cache flush — every time, not just when something looks broken.
- Commit and push normally for this repo (plain `git commit`/`git push` — there is no release
  script to run instead, unlike the plugin repos). Still always surface deploy as an explicit
  next step and get express permission before running any command in this file against the
  live server — see "Always surface commit/push/deploy" in `ozulabs/STEERING.md`.
