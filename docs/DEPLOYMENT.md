# Deploying to Hostinger

This is the operational guide for getting `DevFolio` live on **Hostinger shared
hosting** (Premium / Business) with automatic deploys from GitHub.

The pipeline is:

```
push to `production`
        │
        ▼
  tests.yml  ── runs Pint, PHPStan, the 1700-test suite ──┐
        │                                                 │ fails → stop
        ▼ (only on success)                               │
   deploy.yml                                             │
        ├─ build: composer install --no-dev, npm run build│
        ├─ upload the release over SSH to *.incoming      │
        ├─ run deploy/hostinger-deploy.sh on the host     │
        └─ swap the release into place (previous kept)    │
```

Because the deploy is gated on `workflow_run`, a red test suite can never reach
the live site.

---

## 1. What the host must have

Hostinger shared hosting is a constrained environment. This setup assumes:

| Requirement | Why | Where to check |
| --- | --- | --- |
| **PHP 8.3+** | `composer.json` requires `^8.3` | hPanel → Advanced → PHP Configuration |
| **PHP extensions**: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl` | Laravel + MySQL | hPanel → PHP Configuration → Extensions |
| **MySQL database** | Replaces the local SQLite file | hPanel → Databases → MySQL Databases |
| **SSH access** | The deploy uploads over SSH/rsync | hPanel → Advanced → SSH Access |
| **Cron** (optional) | Draining the database queue | hPanel → Advanced → Cron Jobs |

Node and Composer are **not** required on the host — both run in GitHub Actions.

> **If your plan has no SSH**, stop here and see §8; the workflow needs a
> different (FTP) transport.

---

## 2. Create the database

1. hPanel → **Databases → MySQL Databases**.
2. Create a database and a user. Hostinger prefixes both with your account id,
   e.g. database `u123456789_devfolio`, user `u123456789_devfolio`.
3. Give the user **all privileges** on that database.
4. Note the four values — they fill `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
   in the environment secret (§4).

---

## 3. Set up the directory layout

Shared hosting points the web root at `public_html`, but a Laravel app's entry
point is its `public/` directory. **The application itself must live above the
web root**, so that `.env`, `storage/` and `vendor/` are never web-reachable.

We deploy the app to a directory beside `public_html`, then point `public_html`
at the app's `public/` folder.

Assume the account is `u123456789` and the domain `example.com`:

```
/home/u123456789/
├── domains/example.com/
│   ├── devfolio/            <-- DEPLOY PATH (the whole Laravel app)
│   │   ├── app/  bootstrap/  config/  database/  deploy/
│   │   ├── public/          <-- the real web root
│   │   ├── resources/  routes/  storage/  vendor/
│   │   └── .env
│   └── public_html/         <-- must serve the contents of ../devfolio/public
```

**Create the deploy directory:**

```bash
ssh u123456789@<host>
mkdir -p ~/domains/example.com/devfolio
```

**Make `public_html` serve `devfolio/public`.** Two options — pick one:

**Option A — replace `public_html` with a symlink (cleanest):**

```bash
cd ~/domains/example.com
rm -rf public_html
ln -s devfolio/public public_html
```

> Some Hostinger plans block following a symlinked document root. If you see a
> blank page or a 403 after this, use Option B.

**Option B — keep `public_html` a real directory and point `index.php` at the app:**

Create `~/domains/example.com/public_html/index.php` containing:

```php
<?php

// Forward every request to the Laravel front controller that lives outside the
// web root, so the application's .env and vendor/ stay unreachable over HTTP.
require __DIR__.'/../devfolio/public/index.php';
```

Then copy the built assets so they are served from `public_html`:

```bash
ln -s ~/domains/example.com/devfolio/public/build public_html/build
```

Both options keep PHP, `.env` and `vendor/` outside the document root — this is
the whole point, and doing it the other way (moving the app *into*
`public_html`) exposes `.env` to anyone who requests `/.env`.

> **The deploy path value** you will put in `HOSTINGER_DEPLOY_PATH` is the
> absolute path to the app directory:
> `/home/u123456789/domains/example.com/devfolio`

---

## 4. GitHub secrets

Add these under **Repository → Settings → Secrets and variables → Actions →
New repository secret**.

| Secret | Value | Notes |
| --- | --- | --- |
| `HOSTINGER_HOST` | e.g. `145.14.100.20` or `ssh.example.com` | From hPanel → SSH Access |
| `HOSTINGER_PORT` | `65002` (Hostinger's usual SSH port) | hPanel → SSH Access |
| `HOSTINGER_USER` | `u123456789` | Your account user |
| `HOSTINGER_SSH_KEY` | the **private** key | See §5 — paste the whole file, including the BEGIN/END lines |
| `HOSTINGER_KNOWN_HOSTS` | output of `ssh-keyscan` | See §5 |
| `HOSTINGER_DEPLOY_PATH` | `/home/u123456789/domains/example.com/devfolio` | The app dir from §3 |
| `HOSTINGER_APP_ENV` | the full production `.env` | See §6 |

---

## 5. SSH key

Generate a **deploy-only** keypair locally. Do not reuse your personal key:

```bash
ssh-keygen -t ed25519 -C "github-deploy-devfolio" -f ~/.ssh/devfolio_deploy -N ""
```

**Install the public key on the host:**

```bash
ssh-copy-id -i ~/.ssh/devfolio_deploy.pub -p 65002 u123456789@<host>
# or paste the contents of ~/.ssh/devfolio_deploy.pub into
# hPanel → Advanced → SSH Access → SSH Keys
```

**Put the private key into `HOSTINGER_SSH_KEY`:**

```bash
cat ~/.ssh/devfolio_deploy        # paste the ENTIRE output into the secret
```

**Populate `HOSTINGER_KNOWN_HOSTS`** so the workflow can verify the server's
identity (without it, `StrictHostKeyChecking=yes` in the workflow would fail):

```bash
ssh-keyscan -p 65002 <host> 2>/dev/null
# paste the output into the HOSTINGER_KNOWN_HOSTS secret
```

Verify the key works before relying on CI:

```bash
ssh -i ~/.ssh/devfolio_deploy -p 65002 u123456789@<host> 'echo OK && php -v | head -n1'
```

---

## 6. The environment secret (`HOSTINGER_APP_ENV`)

Copy `.env.production.example`, fill in every blank (database credentials, mail
password, and a **fresh** `APP_KEY`), and paste the whole file as the
`HOSTINGER_APP_ENV` secret.

Generate the key:

```bash
php artisan key:generate --show        # prints base64:....
```

Two values deserve emphasis:

- **`APP_KEY`** — must be unique to production. Sessions are encrypted with it;
  sharing the dev key means a leaked dev key compromises live sessions.
- **`SESSION_SECURE_COOKIE=true`** — without it the session cookie travels over
  plain HTTP.
- **`TRUSTED_PROXIES=*`** — Hostinger terminates TLS upstream, so without this
  the app sees `http`, generates `http://` URLs, and refuses to mark cookies
  `Secure`.

The workflow rewrites `.env` on **every** deploy from this secret, so rotating a
credential is a secret change plus a re-run — never a manual edit on the server.

---

## 7. First deploy

1. Merge into `production` (or push directly).
2. Watch **Actions → tests** go green.
3. **Actions → deploy** starts automatically.
4. On success, visit your domain.

If you need to run it by hand: **Actions → deploy → Run workflow**.

**After the first deploy, confirm:**

```bash
ssh -i ~/.ssh/devfolio_deploy -p 65002 u123456789@<host>
cd ~/domains/example.com/devfolio
php artisan about          # shows the resolved environment and drivers
php artisan migrate:status # every migration should read "Ran"
```

Then check in a browser that the site loads over **https** and that a login
round-trips (which proves sessions and the database work).

---

## 8. No SSH? Use the FTP variant

If your plan lacks SSH, the transport changes but the build does not. Replace
the upload/remote-steps/swap steps in `deploy.yml` with an FTP action such as
`SamKirkland/FTP-Deploy-Action`, and:

- add `HOSTINGER_FTP_HOST`, `HOSTINGER_FTP_USER`, `HOSTINGER_FTP_PASSWORD` secrets;
- run the artisan steps over **cron** instead (see §9), because FTP cannot
  execute commands on the host;
- accept that the swap is not atomic — FTP writes files in place, so the site
  serves a mix of old and new files for the duration of the upload.

This is why SSH is recommended.

---

## 9. Cron (queue, scheduler)

Shared hosting has no long-running worker, so `QUEUE_CONNECTION=database` means
jobs wait until something drains them. If you rely on queued jobs, add a cron
entry in hPanel → **Advanced → Cron Jobs**:

```bash
# Every minute: drain the queue, bounded at 55 seconds so runs never overlap.
* * * * * cd /home/u123456789/domains/example.com/devfolio && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1

# Laravel's scheduler, if any command is scheduled.
* * * * * cd /home/u123456789/domains/example.com/devfolio && php artisan schedule:run >> /dev/null 2>&1
```

---

## 10. Rollback

Every deploy keeps the outgoing release at `<deploy_path>.previous`. To roll
back the code:

```bash
ssh -i ~/.ssh/devfolio_deploy -p 65002 u123456789@<host> \
  'bash /home/u123456789/domains/example.com/devfolio/deploy/hostinger-rollback.sh \
        /home/u123456789/domains/example.com/devfolio'
```

The failed release is kept as `<deploy_path>.failed` for inspection.

> **Migrations are not reversed.** Write migrations to be backward compatible —
> add columns and tables rather than renaming or dropping — so the previous code
> keeps working against the newer schema. If a release shipped a destructive
> migration, restore the database from a Hostinger backup instead
> (hPanel → Files → Backups).

---

## 11. Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Blank page / 500 | App can't read `.env`, or `storage/` not writable | `php artisan about` over SSH; `chmod -R 775 storage bootstrap/cache` |
| `No application encryption key` | `APP_KEY` empty in the secret | regenerate with `php artisan key:generate --show`, redeploy |
| Assets 404 (`/build/...`) | `public/build` not served | confirm §3 Option A or B; the build ships inside the release |
| `php: command not found` during deploy | versioned binary on host | set `PHP_BIN=/usr/bin/php8.3` (check `ls /usr/bin/php*`) |
| Deploy hangs on SSH | host key mismatch | refresh `HOSTINGER_KNOWN_HOSTS` with `ssh-keyscan` |
| Login doesn't stick | `SESSION_*` misconfigured | ensure `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true` on https |
| Redirect loop / wrong scheme | trusted proxies | ensure `TRUSTED_PROXIES=*` is set |
| `could not find driver` | `pdo_mysql` off | enable it in hPanel → PHP Configuration → Extensions |
| Old code after deploy | caches stale | the deploy script rebuilds them; re-run the workflow |

**Read the real error.** With `APP_DEBUG=false` the browser shows a generic
page. The actual message is in `storage/logs/laravel.log` on the host:

```bash
tail -n 50 ~/domains/example.com/devfolio/storage/logs/laravel.log
```

---

## 12. Never committed

`.env`, `vendor/`, `public/build/` and `database/*.sqlite` are all gitignored.
The deploy assembles them in CI and uploads them inside the release, which is
why the artifact is built from the working tree rather than from a bare
`git archive`.
