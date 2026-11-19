# [![PrivateBin](https://raw.githubusercontent.com/PrivateBin/assets/master/images/preview/logoSmall.png)](https://privatebin.info/)

*Current version: 2.1.1-fork*

**PrivateBin** is a minimalist, open source online
[pastebin](https://en.wikipedia.org/wiki/Pastebin)
where the server has zero knowledge of stored data.

Data is encrypted and decrypted in the browser using 256bit AES in
[Galois Counter mode](https://en.wikipedia.org/wiki/Galois/Counter_Mode).

This is a fork of ZeroBin, originally developed by
[Sébastien Sauvage](https://github.com/sebsauvage/ZeroBin). PrivateBin was
refactored to allow easier and cleaner extensions and has many additional
features.

## What PrivateBin provides

+ As a server administrator you don't have to worry if your users post content
  that is considered illegal in your country. You have plausible deniability of
  any of the pastes content. If requested or enforced, you can delete any paste
  from your system.

+ Pastebin-like system to store text documents, code samples, etc.

+ Encryption of data sent to server.

+ Possibility to set a password which is required to read the paste. It further
  protects a paste and prevents people stumbling upon your paste's link
  from being able to read it without the password.

## What it doesn't provide

- As a user you have to trust the server administrator not to inject any
  malicious code. For security, a PrivateBin installation *has to be used over*
  *HTTPS*! Otherwise you would also have to trust your internet provider, and
  any jurisdiction the traffic passes through. Additionally the instance should
  be secured by
  [HSTS](https://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security). It can
  use traditional certificate authorities and/or use a
  [DNSSEC](https://en.wikipedia.org/wiki/Domain_Name_System_Security_Extensions)
  protected
  [DANE](https://en.wikipedia.org/wiki/DNS-based_Authentication_of_Named_Entities)
  record.

- The “key” used to encrypt the paste is part of the URL (in
  [the fragment part separated by the `#`](https://en.wikipedia.org/wiki/URL#fragment)).
  If you publicly post the URL of a paste that is not password-protected, anyone
  can read it.
  Use a password if you want your paste to remain private. In that case, make
  sure to use a strong password and share it privately and end-to-end-encrypted.

- A server admin can be forced to hand over access logs to the authorities.
  PrivateBin encrypts your text and the discussion contents, but who accessed a
  paste (first) might still be disclosed via access logs.

- In case of a server breach your data is secure as it is only stored encrypted
  on the server. However, the server could be abused or the server admin could
  be legally forced into sending malicious code to their users, which logs
  the decryption key and sends it to a server when a user accesses a paste.
  Therefore, do not access any PrivateBin instance if you think it has been
  compromised. As long as no user accesses this instance with a previously
  generated URL, the content can't be decrypted.

## Options

Some features are optional and can be enabled or disabled in the [configuration
file](https://github.com/PrivateBin/PrivateBin/wiki/Configuration):

* Password protection

* Discussions, anonymous or with nicknames and IP based identicons or vizhashes

* Expiration times, including a "forever" and "burn after reading" option

* Markdown format support for HTML formatted pastes, including preview function

* Syntax highlighting for source code using prettify.js, including 4 prettify
  themes

* File upload support, image, media and PDF preview (disabled by default, size
  limit adjustable)

* Templates: By default there are bootstrap5, bootstrap CSS and darkstrap
  to choose from and it is easy to adapt these to your own websites layout or
  create your own.

* Translation system and automatic browser language detection (if enabled in
  browser)

* Language selection (disabled by default, as it uses a session cookie)

* QR code for paste URLs, to easily transfer them over to mobile devices

## Container image

`make image` builds the image of this repository as
`fabiop85/privatebin:v2.1.1-fork` (override with `IMAGE=` and `VERSION=`):

```bash
make image                # docker build --tag fabiop85/privatebin:v2.1.1-fork
make image-run PORT=8081  # run it, bind mounting ./cfg and ./data
make image-push           # push the tag to the registry
```

`./cfg` must contain a `conf.php` (start from `cfg/conf.sample.php`), `./data`
must be writable by the container user (`65534:82`), otherwise `image-run`
starts a container that cannot work.

`compose.prod.yml` deploys the built image, with no build step, mounting the
host configuration (read only) and data directory:

```bash
cp cfg/conf.sample.php cfg/conf.php     # then edit it
sudo chown -R 65534:82 data             # the container runs as nobody:www-data
docker compose -f compose.prod.yml up -d
```

Environment variables override the defaults (`PRIVATEBIN_IMAGE`,
`PRIVATEBIN_CONTAINER`, `PRIVATEBIN_PORT`, `PRIVATEBIN_CFG`, `PRIVATEBIN_DATA`),
for example `PRIVATEBIN_PORT=8080 docker compose -f compose.prod.yml up -d`.
The mount of `cfg/` is read only on purpose: the container never writes the
configuration. Note that `cfg/conf.php` must be readable by the container user,
a `600` file owned by `root` makes PrivateBin silently fall back to its
defaults, which also disables the administration interface.

The build uses `docker/Dockerfile`, based on
`privatebin/nginx-fpm-alpine:2.1.1`, and needs no composer run: the production
dependencies come from the base image, as `composer.json` and `composer.lock`
are unchanged. Configuration and data are not baked into the image, mount them
at `/srv/cfg` (read only) and `/srv/data`; the container serves HTTP on port
`8080` internally. An s6 service inside the container purges expired documents
and removes empty directories once per hour.

## Production deployment

`README_DEPLOY_PROD_FORK.md` is the operational runbook: build and transfer of
the image, minimal `[model]`/`[admin]` configuration, `compose.prod.yml` with
bind mounts, TLS reverse proxy, verification, backup, upgrades and the usual
failure modes.

## Further resources

* [FAQ](https://github.com/PrivateBin/PrivateBin/wiki/FAQ)

* [Installation guide](https://github.com/PrivateBin/PrivateBin/blob/master/doc/Installation.md#installation)

* [Configuration guide](https://github.com/PrivateBin/PrivateBin/wiki/Configuration)

* [Templates](https://github.com/PrivateBin/PrivateBin/wiki/Templates)

* [Translation guide](https://github.com/PrivateBin/PrivateBin/wiki/Translation)

* [Developer guide](https://github.com/PrivateBin/PrivateBin/wiki/Development)

Run into any issues? Have ideas for further developments? Please
[report](https://github.com/PrivateBin/PrivateBin/issues) them!
