# AGENTS.md

Notes for coding agents and maintainers working on this repository.

## What this repository is

A fork of [PrivateBin](https://github.com/PrivateBin/PrivateBin), maintained on
the branch `v2.1.1-fork`, based on upstream release `2.1.1`. Compared to
upstream it adds:

* optional document names and tags, the name is used as the document ID
* path style URLs (`/my-paste`) and the `pathurls` option
* an administration interface (`admin.php`, not linked from the home page)
* plaintext command line downloads (`?raw=1&key=<fragment>`) and
  `bin/privatebin-decrypt.php` for local decryption
* server side decryption helpers in `lib/Decryptor.php`
* deletion of empty directories in the filesystem store
* a container image build in `docker/` and the `image*` targets of the Makefile

The version is `2.1.1-fork`: the footer shows `PrivateBin` as the name and this
string as the version. Fork only files (no upstream counterpart):

* `lib/Admin.php`, `lib/Decryptor.php`, `bin/privatebin-admin-token.php`,
  `bin/privatebin-decrypt.php`, `tpl/admin.php`, `js/admin.js`,
  `css/admin.css`, `docker/`

## Remotes

`origin` points at the fork (`manprint/PrivateBin`). Upstream is not configured
by default:

```bash
git remote add upstream https://github.com/PrivateBin/PrivateBin.git
git fetch upstream --tags
```

## Aligning with a new upstream release

1. Create a branch for the merge and merge the release into it, do not work
   directly on `v2.1.1-fork`:

   ```bash
   git switch -c align-upstream-<version> v2.1.1-fork
   git merge upstream/master        # or the release tag, e.g. upstream/2.2.0
   ```

2. Resolve the conflicts, the files that usually conflict are:

   * `lib/Controller.php` - `const VERSION` (keep the `-fork` suffix), the
     `key`/`password` request parameters, the admin link assignment
   * `lib/Request.php` - the `filter_var_array` whitelist of parameters, it
     must keep `key` and `password`
   * `lib/View.php` - `_getCacheBuster()`, it appends `filemtime` to the
     version
   * `tpl/bootstrap5.php` - the `#curlhint` block and the footer name/version
   * `js/privatebin.js` - `CurlHint.show()` after `history.pushState`, the
     burn after reading handling, the copy button label
   * `js/admin.js`, `tpl/admin.php`, `css/admin.css` - fork only, but they
     depend on the JSON contract of `lib/Admin.php`
   * `i18n/*.json` - the fork removes the decrypt hint keys and adds the
     plaintext explanation, keep all locales in sync
   * `lib/Configuration.php` - the `sri` hashes, see below
   * `cfg/conf.sample.php` - new upstream options must be carried over

3. Set the new version everywhere, `make increment VERSION=<upstream>-fork`
   after adjusting `CURRENT_VERSION` in the `Makefile`, or edit by hand:
   `lib/Controller.php`, `README.md`, `SECURITY.md`, `doc/Installation.md`,
   `js/package.json`, `Makefile`.

4. Regenerate the subresource integrity hashes of every changed script, a
   mismatch makes the browser block the script and the application stays on
   "Loading…":

   ```bash
   NEW="sha512-$(openssl dgst -sha512 -binary js/privatebin.js | base64 -w0)"
   sed -i -E "s#'(js/privatebin\.js)'[[:space:]]*=>[[:space:]]*'sha512-[^']*'#'\1' => '$NEW'#" \
     lib/Configuration.php
   grep -n "privatebin.js' =>" lib/Configuration.php
   ```

   Same for `js/admin.js`, exchange the file name in both places. The `sri`
   array holds every script the templates load with an `integrity` attribute.

5. Run the tests and fix what breaks:

   ```bash
   make test-php     # cd tst && phpunit --no-coverage
   make test-js      # cd js && mocha
   ```

   `ControllerWithGcsTest` fails with PHP 8.5 because of a deprecation in
   `google/cloud-core`, that one is environmental.

6. Add a `CHANGELOG.md` entry and, when the fork behaviour changed, the
   matching documentation in `doc/Installation.md` and `README.md`.

7. Rebuild and verify the image and the local deployment (see below):

   ```bash
   make image
   ```

## Deploying the image

`README_DEPLOY_PROD_FORK.md` is the production runbook (build, configuration,
compose, TLS proxy, verification, backup, upgrades, troubleshooting).
`compose.prod.yml` runs the image built by `make image` with bind mounts and no
build step (`PRIVATEBIN_IMAGE`, `PRIVATEBIN_PORT`, `PRIVATEBIN_DATA`,
`PRIVATEBIN_CFG` override the defaults). The bind mounted `cfg/` is read only
and `conf.php` must be readable by uid 65534, otherwise PrivateBin falls back to
its defaults and the administration interface answers 404.

## Local deployment

The sandbox deployment lives in `/home/agent/privatebin` (`app/` snapshot of
this repository, `cfg/conf.php`, `data/`, Dockerfile, `docker-compose.yml`,
`ADMIN_TOKEN.txt`). It serves on port `8081` and is rebuilt with:

```bash
rsync -a --delete --exclude-from=... /path/to/repo/ app/   # see its README.md
cd /home/agent/privatebin && docker compose up -d --build
docker compose up -d --force-recreate
```

After changing `js/`, always rebuild: the integrity hashes and the modification
time based cache buster are baked into the rendered page, a stale combination
ends in a blank page.

## Housekeeping and conventions

* Never commit `vendor/` dev dependencies (phpunit, google/cloud, grpc, ...):
  the production packages are already tracked, the rest are test artifacts.
  `data/` and `cfg/conf.php` are ignored and must stay out of the repository.
* `lib/Decryptor.php` and the `key` parameter of the raw endpoint mean the
  server decrypts the document for that request: it is opt-in, never a default,
  and the plaintext variants are documented as breaking zero knowledge for the
  request they serve.
* Translations live in `i18n/*.json`; the English key is the lookup key, so a
  renamed string needs the key changed in the template, in the JS and in every
  locale.
* Conventional Commits are used: `feat:`, `fix:`, `docs:`, `build:`,
  `chore:`.
