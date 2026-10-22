# Deploying HunZala Pharmacy to pharmacy.9to5dev.com

Handoff notes for continuing this deployment. Written 2026-09-27.

Everything below happened on the **remote staging server**, not a local machine.
Nothing has been started yet — the stack is configured but not built or running,
and host-nginx has not been touched.

---

## 1. The server

| | |
|---|---|
| SSH | `ssh dine-decider` (alias already in `~/.ssh/config`) |
| Hostname | `vmi3521946` |
| User | `ahmad` |
| Public IP | `169.58.208.122` |
| Pharmacy checkout | `~/pharmacy` (i.e. `/home/ahmad/pharmacy`) |

**This box already runs two other production stacks.** Nothing new may disturb
them:

- **Dine Decider** — `~/Dine/DineDeciderBackend` + `~/Dine/DineDeciderFrontend`,
  compose projects `dine-decider-staging` / `dine-decider-frontend-staging`
- **Munsif** — `/home/omer/Munsif`, compose project `munsif`. Owned by another
  user; `ahmad` cannot read it.

There is **no PHP, no Composer and no Node on the host** — every app runs
entirely in Docker. Do not try to run `php artisan` directly on the host; use
`docker compose exec app php artisan ...`.

### Host nginx owns 80 and 443

Host nginx (systemd, active) terminates TLS for every site on this box and
reverse-proxies to a loopback port per stack. Existing vhosts in
`/etc/nginx/sites-enabled/`:

```
api.dinedecider.pk       -> 127.0.0.1:8090  (+ :8091 for Reverb)
app.dinedecider.pk       -> 127.0.0.1:3090
munsify.com
storage.dinedecider.pk
storage.munsify.com
default
```

Certificates are Let's Encrypt via **host** certbot (`sudo certbot --nginx -d ...`),
which rewrites the vhost in place to add the 443 block and the 80→443 redirect.

### Ports already taken

`80, 443, 22, 1026, 3090, 3307, 6382, 8026, 8027, 8080, 8082, 8090, 8091, 8108,
9003, 9004, 9013, 11434, 54322`

**Pharmacy was assigned `8100`**, bound to `127.0.0.1` only.

### sudo needs a password

`sudo -n true` fails — `sudo: a password is required`. Anything touching
`/etc/nginx/` or certbot must be run by a human, or the password must be
supplied. This is why the vhost is staged in the repo rather than installed.

---

## 2. What the repo is

`https://github.com/ahmadjabbar2015/HunzalaPharmacyLaravel`, branch `master`,
cloned to `~/pharmacy`.

Laravel 13 · PHP 8.3 · Livewire 4 · Tailwind 4 + Vite 8 · MySQL 8 · Redis 7.

The repo ships its own full Docker stack (`docker-compose.yml`, `docker/Dockerfile`
with an `assets` → `runtime` → `web` multi-stage build, `docker/entrypoint.sh`,
a `backup` service and a `certbot` service behind the `tools` profile).
Compose project name is `hunzala-pharmacy`, so its containers, volumes and
network cannot collide with the Dine or Munsif stacks.

Services: `app` (php-fpm, `expose: 9000`), `nginx`, `mysql`, `redis`, `queue`,
`scheduler`, `backup`, and `certbot` (profile `tools`, not run by `up`).

The `app` entrypoint does the deploy work itself on every boot: waits for the
MySQL healthcheck, then `migrate --force --isolated`, `config:cache`,
`route:cache`, `view:cache`, `storage:link`. Only the `app` role migrates.

---

## 3. The one real conflict, and how it was resolved

**Problem.** The repo's `docker-compose.yml` publishes `80:80` and `443:443` on
the `nginx` service, and its in-stack `certbot` service answers ACME on port 80.
Both are impossible here — host nginx owns 80/443 for Munsif and the two Dine
sites. `docker compose up` would fail to bind.

**Decision.** Follow the pattern this server already uses for
`api.dinedecider.pk` and `app.dinedecider.pk`: the container serves plain HTTP
on a loopback port, and host nginx is the public entry point and the only place
TLS is terminated.

```
internet :443 --> host nginx  (Let's Encrypt cert, host certbot)
                     |
                     v
        127.0.0.1:8100 -> nginx container :80  (plain HTTP)
                     |
                     v
               app:9000 (php-fpm)
```

The in-stack `certbot` service is left unused. The `01-ensure-certs.sh` hook
will still mint a self-signed placeholder inside the container; it is never
served and can be ignored.

---

## 4. Changes made in `~/pharmacy`

Four new files plus one edit. **None of this is committed yet.**

### `docker-compose.override.yml` (new, untracked)

Replaces the `nginx` service's published ports with `127.0.0.1:8100:80` and
bind-mounts the staging template. Uses the `!override` tag — without it Compose
*appends* to the base `ports` list and `80:80`/`443:443` would still be
published.

### `docker/nginx/staging.conf.template` (new)

Mounted **over** `/etc/nginx/templates/default.conf.template` (same filename on
purpose — mounting an extra template would make the nginx image render two
configs with competing server blocks).

Needed because the shipped `default.conf.template`'s `:80` block does
`return 301 https://$host$request_uri`. Behind a host proxy that is an infinite
redirect loop. The staging variant serves the app on plain `:80`, keeps the
`/healthz` endpoint the compose healthcheck hits, and drops the TLS server block.

### `docker/nginx/pharmacy.9to5dev.com.conf` (new)

The **host** nginx vhost, staged here because `/etc/nginx/sites-available` needs
sudo. Deliberately mirrors `api.dinedecider.pk`. Ignoring comments, the only
differences from that file are:

- `server_name pharmacy.9to5dev.com`
- `proxy_pass http://127.0.0.1:8100`
- no `/app/` or `/apps/` Reverb blocks — this stack has no reverb container, so
  they would proxy to nothing, and `/app/` as a prefix would shadow any real
  route under that path
- no `# managed by Certbot` TLS blocks yet — certbot adds those on first run

Identical to `api`: `client_max_body_size 350M`, `proxy_read_timeout` /
`proxy_send_timeout 300s`, the five `proxy_set_header` lines, all four security
headers with the same values, and no explicit `listen` in the server block
(certbot adds the `:80` block itself).

### `.env` (new, untracked, `chmod 600`)

Generated from `.env.docker.example`. Ignored at `.gitignore:3`. All four
"CHANGE ME" values are filled:

- `APP_KEY` — generated (`base64:` + 32 random bytes)
- `DB_PASSWORD`, `DB_ROOT_PASSWORD` — generated, 40 hex chars each
- `APP_URL=https://pharmacy.9to5dev.com`
- `NGINX_SERVER_NAME=pharmacy.9to5dev.com`
- `CERTBOT_EMAIL=hanzalaahmad2005@gmail.com`

Read the real values with
`grep -E '^(APP_KEY|DB_PASSWORD|DB_ROOT_PASSWORD)=' ~/pharmacy/.env`.
**Save `DB_ROOT_PASSWORD`** — it is needed for
`docker compose exec mysql mysql -u root -p` and for restores.

Left at the example defaults: `APP_ENV=production`, `APP_DEBUG=false`,
`SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=redis`,
`QUEUE_CONNECTION=redis`, `LOG_CHANNEL=stderr`, `PHARMACY_DEVICE_ID=WEB`,
`BACKUP_HOUR=3`, `BACKUP_RETAIN_DAYS=30`.

### `bootstrap/app.php` (edited — the only source change)

Added `$middleware->trustProxies(at: '*');` inside `withMiddleware`.

This is **required**, not cosmetic. TLS terminates at host nginx one hop in
front of the app. Without trusted proxies Laravel sees plain `http`, so it
generates `http://` links and refuses to set the secure-only session cookie
(`SESSION_SECURE_COOKIE=true`) — every login would bounce straight back to the
login page. The proxy is on the container network, not the internet, so `'*'`
is safe here.

---

## 5. State right now

- Repo cloned and configured. `docker compose config --quiet` passes, and the
  merged config publishes **only** `127.0.0.1:8100->80` (443 gone — `!override`
  confirmed working).
- `docker compose build` was started, then **deliberately cancelled** partway.
  No images finished building.
- **Nothing is running.** No pharmacy containers exist.
- **Host nginx is untouched.** No file in `/etc/nginx/`, no symlink, no cert.
- Munsif and Dine Decider were never modified.

DNS is already correct: `pharmacy.9to5dev.com` resolves to `169.58.208.122`
(this server). No DNS work needed. Note `9to5dev.com` itself points elsewhere
(`13.60.101.242`) — only the `pharmacy` subdomain is here.

---

## 6. Remaining steps

### Build and start (no sudo)

```bash
cd ~/pharmacy
docker compose build
docker compose up -d
docker compose ps
```

On first boot MySQL initialises its data directory; its healthcheck has a 40s
`start_period`, so `mysql` will show `starting` and `app` may restart a couple
of times. Wait a minute, then re-run `docker compose ps`. If `app` is still
cycling: `docker compose logs app --tail=50`.

### Verify on loopback before touching host nginx

```bash
curl -sI -H 'Host: pharmacy.9to5dev.com' http://127.0.0.1:8100/healthz   # expect 200
curl -sI -H 'Host: pharmacy.9to5dev.com' http://127.0.0.1:8100/          # expect 200 or 302
```

A `502` means the app container is not up yet.

### Install the vhost and get a certificate (needs sudo password)

```bash
sudo cp ~/pharmacy/docker/nginx/pharmacy.9to5dev.com.conf \
        /etc/nginx/sites-available/pharmacy.9to5dev.com
sudo ln -s /etc/nginx/sites-available/pharmacy.9to5dev.com \
           /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
sudo certbot --nginx -d pharmacy.9to5dev.com
```

`nginx -t` passes even with nothing on 8100; the site just 502s until the
containers are up. Certbot only needs port 80, which host nginx answers itself.

### Confirm

```bash
curl -sI https://pharmacy.9to5dev.com/
docker compose logs app --tail=30
```

---

## 7. Open questions / not done

- **No admin user.** Migrations create the schema, but nobody checked whether a
  seeder creates a first login. Worth checking `database/seeders/` and running
  `docker compose exec app php artisan db:seed` if there is one.
- **Nothing committed.** The four new files and the `bootstrap/app.php` edit are
  working-tree only. `docker-compose.override.yml` and `.env` are arguably
  server-local and may be intended to stay untracked, but
  `docker/nginx/staging.conf.template`,
  `docker/nginx/pharmacy.9to5dev.com.conf` and the `trustProxies` fix all belong
  in the repo. The `trustProxies` change in particular is a correctness fix for
  any proxied deployment, not just this server.
- **Not tested end to end.** Nothing has been built or requested yet, so no
  claim here about the app actually working is verified.
- **Backups.** The `backup` service bind-mounts `./backups` and dumps nightly at
  03:00 with 30-day retention. Nothing copies them off the server — that is
  still a manual rsync someone has to set up.
- **Vite assets** are built in the image's `assets` stage. If the UI renders
  unstyled, check that stage's output in the build log rather than looking for a
  missing `npm run build` on the host.
