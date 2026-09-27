# Deploying with Docker

Everything the shop's server needs, in one `docker compose up`. Targets the
1 GB / 1 vCPU VPS described in `../DEPLOYMENT_WEB.md` §2.

The Windows Server 2008 machine on the shop floor is **a browser and nothing
else** — it does not run any of this. See `../DEPLOYMENT_WEB.md` §1.

---

## 1. What comes up

| Service | Image | Does |
|---|---|---|
| `app` | built, stage `runtime` | PHP-FPM. Runs migrations and builds caches on boot. |
| `nginx` | built, stage `web` | TLS, static files, front controller. The only published ports. |
| `mysql` | `mysql:8.0` | The shop's data. Not published. |
| `redis` | `redis:7-alpine` | Cache and queue. Not published. |
| `queue` | same as `app` | Queue worker, recycled hourly. |
| `scheduler` | same as `app` | `schedule:work`, no cron daemon. |
| `backup` | built | Nightly `mysqldump` into `./backups`. |
| `certbot` | `certbot/certbot` | Profile `tools` — run on demand, not part of `up`. |

`app`, `queue` and `scheduler` are the same image and differ only in
`CONTAINER_ROLE`. They are separate containers so that a worker crashing during
a long report cannot take the point of sale down with it.

---

## 2. First deployment

```bash
git clone https://github.com/ahmadjabbar2015/HunzalaPharmacyLaravel.git
cd HunzalaPharmacyLaravel

cp .env.docker.example .env
```

Now edit `.env`. Four values must change:

| Key | Set it to |
|---|---|
| `APP_KEY` | `docker compose run --rm app php artisan key:generate --show` |
| `DB_PASSWORD` | something long and random |
| `DB_ROOT_PASSWORD` | something long and random, **different** |
| `APP_URL` / `NGINX_SERVER_NAME` | the real domain, both matching |

`APP_KEY` is not optional. Without it every session cookie and every encrypted
value becomes unreadable the first time a container restarts.

Then:

```bash
docker compose up -d --build
docker compose logs -f app       # watch the migrations run
```

The `app` container waits for MySQL to answer before migrating, so a cold start
on a slow VPS is fine — it is not a failure, just a wait.

### Create the first user

There is no registration screen: staff accounts are created by the owner.

```bash
docker compose exec app php artisan tinker
```

```php
\App\Models\User::create([
    'username'      => 'owner',
    'full_name'     => 'Shop Owner',
    'role'          => 'owner',
    'password_hash' => \Illuminate\Support\Facades\Hash::make('change-this-now'),
]);
```

Change that password at the first login.

---

## 3. TLS

The stack comes up with a **self-signed** certificate so nginx can start at all.
Browsers will warn, and staff log in over this connection, so replace it before
the shop uses it:

```bash
# DNS for NGINX_SERVER_NAME must already point at this server.
docker compose run --rm certbot
docker compose exec nginx nginx -s reload
```

Renewal is the same two commands; put them in a monthly host cron:

```cron
0 4 1 * * cd /srv/pharmacy && docker compose run --rm certbot && docker compose exec -T nginx nginx -s reload
```

Certbot uses the `webroot` challenge rather than `standalone` because nginx
already owns port 80 and must keep serving the HTTPS redirect throughout.

---

## 4. Updating

```bash
git pull
docker compose up -d --build
```

Migrations, `config:cache`, `route:cache` and `view:cache` all run from the
`app` entrypoint, so there is no separate deploy script to remember. Only the
`app` container migrates — `--isolated` takes a lock, so even several app
containers run migrations exactly once between them.

Caches are built at boot rather than baked into the image, because they capture
`.env`, which arrives at runtime. Baking them would freeze build-time config
into every deployment.

---

## 5. Backups

Written to `./backups` on the host every night at `BACKUP_HOUR` (default 03:00
UTC), kept for `BACKUP_RETAIN_DAYS` (default 30).

```bash
docker compose exec backup backup.sh          # run one now
ls -lh backups/
```

**A backup on the same disk does not survive the disk.** Copy them off:

```cron
30 4 * * * rsync -a /srv/pharmacy/backups/ backups@elsewhere:/pharmacy/
```

### Test the restore before you need it

```bash
# Into a scratch database - does not touch live data.
docker compose run --rm backup restore.sh pharmacy-2026-09-27_0300.sql.gz pharmacy_restore_test
```

Restoring over the live database asks you to type its name first. `restore.sh`
refuses a dump that fails `gzip -t`, because a corrupt dump that restores
halfway is worse than one that refuses.

---

## 6. Day-to-day

```bash
docker compose ps                      # what is up, and healthy
docker compose logs -f app queue       # application and worker logs
docker compose exec app php artisan tinker
docker compose exec mysql mysql -u root -p hunzala_pharmacy

docker compose restart queue           # after changing a job
docker compose down                    # stop (volumes survive)
```

Logs go to stdout, so `docker compose logs` is the single place to look rather
than a file inside a container that may no longer exist.

### Checking stock integrity

Derived stock is the source of truth and `current_stock_qty` is only a cache. If
the two ever disagree, something wrote the column without going through
`StockService`:

```bash
docker compose exec app php artisan tinker
```

```php
app(\App\Services\StockService::class)->cacheDrift();        // should be []
app(\App\Services\StockService::class)->recomputeAllCaches(); // rebuild
```

`negativeStockItems()` lists items the ledger says are below zero. That is never
corrected automatically — only someone looking at the shelf can say whether the
ledger or the shelf is wrong.

---

## 7. Notes on the choices

**Why MySQL and redis are not published.** Neither has a host port. They are
reachable only from the compose network, so a firewall mistake cannot expose the
database. Use `docker compose exec` to get at them.

**Why `SESSION_DRIVER=database` and not redis.** A logged-in manager must not be
thrown out because the cache was flushed.

**Why `innodb_flush_log_at_trx_commit = 1`.** It costs throughput. The
alternative is losing up to a second of committed transactions on power loss,
and "the till is out by one sale" is precisely the failure this system exists to
prevent.

**Why the worker recycles hourly.** `--max-time=3600` means a slow leak cannot
grow over weeks of uptime, and a worker still holding pre-deploy code is
replaced on its own.

---

## 8. Known gaps

- **The image build has not been run in CI.** It builds a Laravel 13 / PHP 8.3
  image with `bcmath`, `gd`, `intl`, `opcache`, `pcntl`, `pdo_mysql` and `zip`,
  but confirm `docker compose build` succeeds on the target host before relying
  on a maintenance window for it.
- **No lockfile for the front end.** `package-lock.json` is not committed, so
  the build falls back from `npm ci` to `npm install` and asset versions are not
  reproducible between builds. Commit a lockfile to fix this.
- **Single host, no replication.** Appropriate for one pharmacy; the backup is
  the recovery plan. If the shop grows to a second branch, revisit.
