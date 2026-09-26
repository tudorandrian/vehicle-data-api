# Runnable examples

The same ten-step walkthrough — taxonomies, a make with its provenance, the same make by id, its models, diesel/Euro 6d variants, N1 variants, an English taxonomy, a VIN decode, a deliberate RFC 9457 problem, and a weak-ETag revalidation — implemented four times, so you can pick the language you actually work in. All four clients make exactly the same ten calls, in the same order, and print the same last line.

| File | Language / runtime |
|---|---|
| [`curl.sh`](curl.sh) | plain `curl` + `jq` (falls back to raw output if `jq` is missing) |
| [`javascript/fetch.mjs`](javascript/fetch.mjs) | Node.js 20+, no dependencies (built-in `fetch`) |
| [`php/client.php`](php/client.php) | PHP 8.4 with `ext-curl`, no dependencies |
| [`python/client.py`](python/client.py) | Python 3.11+, standard library only (`urllib`) |

[`csv-and-snapshot.md`](csv-and-snapshot.md) covers the two bulk formats (`?format=csv` and gzipped JSON Lines snapshots) and the attribution headers, which the four clients above do not exercise.

## Get a key

```bash
docker compose exec -T app php artisan vehicle:client create --name=me --owner=me --scopes=catalogue:read,vin:decode,snapshot:read
```

The command prints the key once; it is stored only as a hash. The four runnable clients only need `catalogue:read` and `vin:decode` — `snapshot:read` is for the recipes in `csv-and-snapshot.md`.

## Environment variables

- `VD_API_KEY` — required; a key with at least `catalogue:read` and `vin:decode`.
- `VD_BASE_URL` — optional; defaults to `http://localhost:8087`.

## Running them

```bash
export VD_API_KEY=<key with catalogue:read,vin:decode>
bash examples/curl.sh
node examples/javascript/fetch.mjs
php examples/php/client.php
python3 examples/python/client.py
```

Each client exits 0 and ends with the same last line, `OK: 10 calls, last id <26-character id>` — the same id in all four, since they all resolve it from `GET /v1/makes/dacia`. Step 9 deliberately triggers the RFC 9457 problem response (an over-large `per_page`) and step 10 deliberately revalidates a cached `ETag`; a client exits non-zero if either does not answer exactly the expected status (422, then 304).

These files run in CI against the seeded stack on every pull request (`.github/workflows/docs-e2e.yml`).

The ids and counts in the README and `docs/api.md` come from a freshly seeded database (`php artisan migrate:fresh --seed --force`, which wipes the development database, keys included); after other imports, the counts these clients print can differ. `php artisan vehicle:examples render --check`, which compares the committed examples with a fresh render, assumes such a freshly seeded database.

On a redirect (a retired slug answers `301`), no client sends the key to another origin: the Python client drops `Authorization` when the scheme, host or port changes, the PHP client follows redirects only on the base URL's scheme and leaves libcurl's cross-host protection on, Node's `fetch` drops the header on a cross-origin redirect by itself, and `curl.sh` does not follow redirects.
