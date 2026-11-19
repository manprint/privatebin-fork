# Deploy di produzione — fork PrivateBin `2.1.1-fork`

Runbook per mettere in produzione l'immagine costruita da questo repository
(`fabiop85/privatebin:v2.1.1-fork`) con `compose.prod.yml`.

L'immagine contiene già il codice e le dipendenze: in produzione non si compila
nulla, si montano solo configurazione e dati. Configurazione e documenti non
fanno parte dell'immagine.

---

## 1. Prerequisiti

* Docker Engine 24+ e Docker Compose v2 (`docker compose version`)
* un host con spazio sufficiente per i documenti, e la possibilità di fare
  backup di quella directory
* un nome DNS e un reverse proxy TLS davanti al container (vedi §6): il token
  di amministrazione viaggia altrimenti in chiaro
* porte: il container ascolta `8080` internamente, l'host pubblica `8081` per
  default (`PRIVATEBIN_PORT`)

Le directory di lavoro consigliate, con l'utente di deploy `deploy`:

```bash
sudo mkdir -p /srv/privatebin/{data,cfg}
sudo chown -R 65534:82 /srv/privatebin/data      # il container gira come nobody:www-data
sudo chmod 750 /srv/privatebin/cfg
sudo chown -R deploy:deploy /srv/privatebin/cfg   # solo l'utente di deploy la modifica
```

Il container gira con uid `65534` (nobody) e gid `82` (www-data): la directory
dei dati **deve** appartenere a `65534:82`, altrimenti i documenti non vengono
scritti. `cfg/` viene montata in sola lettura, quindi il container non la
modifica mai; `conf.php` deve però essere **leggibile** da uid 65534 — un file
`600` di proprietà di `root` fa sì che PrivateBin usi in silenzio i valori
predefiniti, con l'area di amministrazione che risponde `404`.

## 2. Costruire e pubblicare l'immagine

Sul repository, alla versione da rilasciare:

```bash
git switch v2.1.1-fork
make test-php                       # suite (phpunit nel PATH, altrimenti
                                    # `cd tst && php /percorso/phpunit.phar --no-coverage`)
make image                          # fabiop85/privatebin:v2.1.1-fork
docker images fabiop85/privatebin   # verifica tag e dimensione (circa 250 MB)
make image-push                     # solo se il deploy tira l'immagine da un registry
```

Il tag deriva da `TAG ?= v$(VERSION)` nel `Makefile`. Per un tag diverso:

```bash
make image VERSION=2.1.1-fork TAG=prod-2026-11
```

Se il deploy avviene su un altro host, o si pubblica l'immagine (`make
image-push`, dopo `docker login`) o la si trasferisce:

```bash
docker save fabiop85/privatebin:v2.1.1-fork | gzip > privatebin-v2.1.1-fork.tar.gz
# sull'host di produzione
gunzip -c privatebin-v2.1.1-fork.tar.gz | docker load
```

## 3. Configurazione

```bash
cd /srv/privatebin
cp <repo>/cfg/conf.sample.php cfg/conf.php
```

Minimo indispensabile in `cfg/conf.php`:

```ini
[main]
name = "PrivateBin"
pathurls = true
sizelimit = 10485760

[model]
class = "Filesystem"

[model_options]
dir = "/srv/data"
```

`dir` **deve** essere `/srv/data` (il punto di mount), non un percorso relativo:
un percorso relativo viene risolto dentro il container e i documenti spariscono
al primo aggiornamento dell'immagine.

Area di amministrazione — generare token e hash:

```bash
docker run --rm --entrypoint php fabiop85/privatebin:v2.1.1-fork \
  /srv/bin/privatebin-admin-token.php
```

L'`--entrypoint php` è obbligatorio: l'entrypoint predefinito dell'immagine è
l'init di s6 e ignora gli argomenti. L'output contiene il token in chiaro (15
caratteri) e il blocco da incollare nella configurazione; nel file va **solo**
l'hash:

```ini
[admin]
enabled = true
tokenhash = "<sha256 stampato dal comando>"
```

Il token in chiaro va conservato fuori dal repository e fuori dalla directory
web (per esempio con `umask 077` in un file a parte). Non è recuperabile
dall'hash: se si perde, si rigenera la coppia e si aggiorna `tokenhash`.

Altre voci utili:

```ini
[expire]
default = "1week"

[purge]
limit = 300
batchsize = 10
```

`pathurls = true` richiede che il reverse proxy inoltri i percorsi inesistenti a
`index.php`: con il `try_files` dell'immagine funziona, con un proxy che
riscrive gli URL va verificato (vedi §6).

Infine, permessi del file:

```bash
sudo chown 65534:82 cfg/conf.php
sudo chmod 640 cfg/conf.php
```

## 4. Compose di produzione

`compose.prod.yml` è nel repository e non contiene `build:`, usa l'immagine già
costruita:

```yaml
name: privatebin
services:
  privatebin:
    image: ${PRIVATEBIN_IMAGE:-fabiop85/privatebin:v2.1.1-fork}
    container_name: ${PRIVATEBIN_CONTAINER:-privatebin}
    restart: unless-stopped
    ports:
      - "${PRIVATEBIN_PORT:-8081}:8080"
    volumes:
      - type: bind
        source: ${PRIVATEBIN_DATA:-./data}
        target: /srv/data
      - type: bind
        source: ${PRIVATEBIN_CFG:-./cfg}
        target: /srv/cfg
        read_only: true
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- http://127.0.0.1:8080/ >/dev/null || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 20s
```

Copiare `compose.prod.yml` in `/srv/privatebin/` e creare un `.env` accanto:

```ini
PRIVATEBIN_IMAGE=fabiop85/privatebin:v2.1.1-fork
PRIVATEBIN_CONTAINER=privatebin
PRIVATEBIN_PORT=127.0.0.1:8081
PRIVATEBIN_CFG=/srv/privatebin/cfg
PRIVATEBIN_DATA=/srv/privatebin/data
```

`PRIVATEBIN_PORT=127.0.0.1:8081` pubblica la porta solo su loopback: obbligatorio
se davanti c'è un reverse proxy sullo stesso host, così il servizio non è
raggiungibile direttamente dall'esterno scavalcando TLS.

```bash
cd /srv/privatebin
docker compose -f compose.prod.yml config    # verifica la resa dei volumi
docker compose -f compose.prod.yml up -d
```

## 5. Verifica del deploy

```bash
docker compose -f compose.prod.yml ps                       # Up (healthy)
docker logs privatebin 2>&1 | tail -5
docker exec privatebin s6-svstat /run/services/purge        # up
docker exec privatebin ls -l /srv/cfg /srv/data
```

Controlli HTTP:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8081/            # 200
curl -s http://127.0.0.1:8081/ | grep -o 'text-center">[^<]*'              # 2.1.1-fork
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8081/admin.php   # 200
```

`admin.php` che risponde `404` significa: `[admin] enabled` assente o `false`,
`tokenhash` non di 64 caratteri esadecimali, oppure `conf.php` illeggibile dal
container. `500` con "missing [model] section" significa `conf.php` senza
`[model]`/`[model_options]`.

Prova funzionale completa: creare un documento dall'interfaccia, copiare il
comando `curl` mostrato, eseguirlo e verificare di ottenere il testo in chiaro.
Poi eliminarlo dall'area di amministrazione e controllare che in `data/` non
restino directory:

```bash
sudo find /srv/privatebin/data -mindepth 1 -type d
```

## 6. Reverse proxy TLS

Il container parla HTTP: TLS va terminato davanti. Esempio nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name paste.example.com;

    ssl_certificate     /etc/letsencrypt/live/paste.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/paste.example.com/privkey.pem;

    client_max_body_size 12m;          # sopra il sizelimit di PrivateBin

    add_header Strict-Transport-Security "max-age=31536000" always;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Esempio Caddy:

```caddy
paste.example.com {
    encode zstd gzip
    request_body {
        max_size 12MB
    }
    reverse_proxy 127.0.0.1:8081
}
```

Note:

* la chiave di cifratura sta nel frammento dell'URL (`#...`), che il browser
  non invia al server: nessuna configurazione del proxy la vede o la può
  perdere, ma un redirect o uno shortener che riscrive l'URL può tagliarla
* `admin.php` non è linkato dalla home: si apre per URL diretto. Volendo si può
  limitare per IP nel proxy
* se il servizio sta in una sottodirectory, impostare `basepath` in
  `[main]` e servire quel prefisso dal proxy
* non attivare trasformazioni/minify lato proxy sui file statici: gli attributi
  `integrity` bloccano gli script modificati e la pagina resta su "Loading…"

## 7. Manutenzione

* **Purge automatico**: un servizio s6 dentro il container esegue ogni ora
  `bin/administration --purge` (documenti scaduti) e `--empty-dirs` (directory
  residue). Il primo giro parte subito dopo l'avvio, poi ogni 3600 s.
  ```bash
  docker logs privatebin 2>&1 | grep -i purg
  ```
* **Nessun VACUUM**: con `class = "Filesystem"` non esiste database. Con il
  backend `Database` la manutenzione fisica resta al DBMS (`autovacuum` su
  PostgreSQL, `OPTIMIZE TABLE` su MySQL, `VACUUM` su SQLite).
* **Pulizia manuale straordinaria**:
  ```bash
  docker exec -u 65534:82 privatebin php /srv/bin/administration --empty-dirs
  docker exec -u 65534:82 privatebin php /srv/bin/administration --purge
  docker exec -u 65534:82 privatebin php /srv/bin/administration --statistics
  ```
* **Backup** (dati + configurazione, permessi preservati):
  ```bash
  sudo tar -czf /var/backups/privatebin-$(date +%F).tar.gz \
    -C /srv privatebin/data privatebin/cfg
  ```
  Il backup contiene i documenti cifrati e gli hash dei token, non le chiavi:
  senza i link completi (`#chiave`) il contenuto resta illeggibile anche a chi
  ottiene il backup.
* **Aggiornamento immagine**:
  ```bash
  make image VERSION=<nuova>-fork && make image-push
  # sull'host
  cd /srv/privatebin && sed -i 's/v2.1.1-fork/<nuova>-fork/' .env
  docker compose -f compose.prod.yml pull
  docker compose -f compose.prod.yml up -d
  ```
  Con immagine trasferita con `docker save`/`load`: sostituire il tag in `.env`
  e `up -d`. I volumi non cambiano, i documenti restano.
* **Rollback**: rimettere il tag precedente in `.env` e `up -d`; le immagini
  vecchie restano locali finché non si fa `docker image prune`.

## 8. Sicurezza

* esporre la porta solo su loopback (`PRIVATEBIN_PORT=127.0.0.1:8081`) e
  pubblicare solo il proxy TLS
* il token admin viaggia nel corpo di una richiesta POST: senza TLS è in chiaro
* il parametro `key` del download da riga di comando fa decifrare il documento
  al server per quella richiesta: finisce nei log di nginx e nella cronologia
  della shell. Per i documenti che devono restare zero-knowledge usare il
  download cifrato e `bin/privatebin-decrypt.php` in locale
* `data/` non deve essere servita dal web server: `.htaccess` è incluso, per
  nginx il mount è fuori dalla document root per costruzione
* non mettere `conf.php`, il token o `.env` nel repository; `data/` e
  `cfg/conf.php` sono già in `.gitignore`

## 9. Problemi frequenti

| Sintomo | Causa | Rimedio |
| --- | --- | --- |
| `500` con "missing [model] section" | `conf.php` senza `[model]`/`[model_options]` | aggiungere le sezioni, riavviare |
| `admin.php` risponde `404` | admin disabilitata, `tokenhash` non valido, `conf.php` non leggibile da uid 65534 | controllare le tre cose |
| admin in browser: "Failed to fetch" | policy di sicurezza che blocca le richieste all'origine | nella versione attuale `connect-src 'self'` è presente; se si modifica `Admin::CSP_HEADER` non toglierlo |
| pagina bloccata su "Loading…" | hash `integrity` non aggiornato dopo una modifica ai file JS | rigenerare i valori `sri` in `lib/Configuration.php` e ricostruire l'immagine |
| i documenti spariscono dopo un riavvio | `[model_options] dir` relativo | impostare `dir = "/srv/data"` |
| "permission denied" scrivendo un documento | `data/` non di `65534:82` | `sudo chown -R 65534:82 /srv/privatebin/data` |
| container `unhealthy` | porta interna o configurazione errata | `docker exec privatebin wget -qO- http://127.0.0.1:8080/` |
| link `/nome-documento` che apre la home | `pathurls` disattivo o proxy che riscrive i percorsi | attivare `pathurls`, verificare `try_files`/proxy |

## 10. Checklist di go-live

- [ ] `make test-php` verde (a parte `ControllerWithGcsTest`, errore ambientale con PHP 8.5)
- [ ] immagine taggata `fabiop85/privatebin:v2.1.1-fork` e pubblicata o trasferita
- [ ] `data/` di proprietà `65534:82`, `cfg/conf.php` leggibile da 65534
- [ ] `dir = "/srv/data"`, sezioni `[model]` e `[model_options]` presenti
- [ ] `[admin] enabled = true` con `tokenhash`, token conservato fuori dal repo
- [ ] `docker compose -f compose.prod.yml config` senza errori, un solo container `privatebin`
- [ ] `Up (healthy)`, `admin.php` 200, footer `2.1.1-fork`
- [ ] documento di prova creato, scaricato in chiaro con `curl`, eliminato
- [ ] reverse proxy TLS attivo, porta pubblicata solo su loopback
- [ ] backup di `data/` e `cfg/` pianificato
- [ ] servizio di purge visibile nei log e `s6-svstat` a `up`
