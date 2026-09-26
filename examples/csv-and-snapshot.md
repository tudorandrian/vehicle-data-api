# CSV and snapshot recipes

Two bulk formats sit alongside the JSON envelope: `?format=csv` on any list route, and gzipped JSON Lines snapshots under `/v1/snapshots/{resource}`. Both need `Authorization: Bearer <key>`; the snapshot additionally needs the `snapshot:read` scope.

## The CSV page

```bash
curl -H "Authorization: Bearer $VD_API_KEY" "${VD_BASE_URL:-http://localhost:8087}/v1/makes?format=csv&per_page=3"
```

The body starts with a UTF-8 byte-order mark (BOM), which Excel needs to open the file as UTF-8 rather than guessing a legacy codepage. The BOM is not shown below; the header row and the first three data rows (from `packages/vehicle-data-core/resources/examples/makes-csv.json`) are:

```
id;slug;name;kind;manufacturer;ro_fleet.count;ro_fleet.year
KHPH8CFY72YX7G6TZ706QV0D13;bmw;BMW;car;bmw;567;2025
JA47BF995RFMPBB3ECP36BM0QR;dacia;Dacia;car;dacia;10415;2025
E9ZEQCW0SFG7WRNTGB1MBQQTH7;fiat;Fiat;car;fiat;254;2025
```

## The snapshot

```bash
curl -H "Authorization: Bearer $VD_API_KEY" -o makes.ndjson.gz "${VD_BASE_URL:-http://localhost:8087}/v1/snapshots/makes"
gunzip -c makes.ndjson.gz | head -2
```

This needs a key with the `snapshot:read` scope. Each line of the decompressed file is one JSON record, in the same shape as a single item of the list response.

## Attribution headers

```bash
curl -sS -D - -o /dev/null -H "Authorization: Bearer $VD_API_KEY" "${VD_BASE_URL:-http://localhost:8087}/v1/snapshots/makes"
```

`-D -` prints the response headers to stdout. Look for `X-Data-Attribution` (the human-readable credit line for every source contributing to the resource), `X-Data-Licences` (the comma-separated licence identifiers), and a `Link: <…>; rel="license"` header pointing at this repository's data-sources document. The CSV page carries the same `X-Data-Attribution` header; single-record JSON responses carry the equivalent detail per source in `sources[]` instead.

Every reuse of this data — CSV, snapshot or JSON — must carry the attribution lines the response gives you; see [../docs/data-sources.md](../docs/data-sources.md) for the full obligations, per-source licence text and known data issues.
