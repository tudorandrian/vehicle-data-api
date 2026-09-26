#!/usr/bin/env bash
# The README walkthrough as plain curl. Needs: VD_API_KEY (catalogue:read, vin:decode); optional VD_BASE_URL.
set -euo pipefail
: "${VD_API_KEY:?set VD_API_KEY to a key with catalogue:read and vin:decode}"
BASE="${VD_BASE_URL:-http://localhost:8087}"
H=(-sS -f -H "Authorization: Bearer $VD_API_KEY" -H "Accept: application/json")
json() { if command -v jq >/dev/null; then jq -c "$1"; else cat; echo; fi; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "1. taxonomies with their stable ids";        curl "${H[@]}" "$BASE/v1/taxonomies" | json '[.data[] | {id, name}]'

echo "2. one make, with provenance"
curl "${H[@]}" -D "$TMP/dacia_headers.txt" -o "$TMP/dacia_body.json" "$BASE/v1/makes/dacia"
json '{id: .data.id, slug: .data.slug, fleet: .data.ro_fleet, sources: [.sources[].key]}' < "$TMP/dacia_body.json"
if command -v jq >/dev/null; then
  ID=$(jq -r .data.id < "$TMP/dacia_body.json")
else
  # Anchored on the "data":{"id": prefix (not a bare greedy "id":" match) so it cannot
  # pick up an unrelated id-shaped key elsewhere in the body.
  ID=$(sed -E 's/.*"data":\{"id":"([0-9A-HJKMNP-TV-Z]{26})".*/\1/' < "$TMP/dacia_body.json")
fi
ETAG=$(grep -i '^ETag:' "$TMP/dacia_headers.txt" | sed -E 's/^[Ee][Tt][Aa][Gg]: *//; s/\r$//')

echo "3. the same make by id: $ID";                 curl "${H[@]}" -o /dev/null -w '   HTTP %{http_code}\n' "$BASE/v1/makes/$ID"
echo "4. its models by fleet size";                 curl "${H[@]}" "$BASE/v1/makes/dacia/models?sort=-ro_fleet_count&per_page=3" | json '[.data[] | {slug, fleet: .ro_fleet.count}]'
echo "5. diesel Euro 6d Duster variants (fuel + euro_norm)"; curl "${H[@]}" "$BASE/v1/models/dacia-duster/variants?fuel=diesel&euro_norm=euro_6d" | json '[.data[] | {id, engine_cc, power_kw, co2_wltp, euro: .euro_norm.code}]'
echo "6. N1 (light commercial) variants (eu_category)"; curl "${H[@]}" "$BASE/v1/models/fiat-ducato/variants?eu_category=n1" | json '[.data[] | {id, eu_category: .eu_category.code}]'
echo "7. national_category labels in English";     curl "${H[@]}" "$BASE/v1/taxonomies/national_category?lang=en" | json '[.data.terms[:3][] | {code, label}]'
echo "8. structural VIN decode (synthetic VIN)";   curl "${H[@]}" "$BASE/v1/vin/WVWZZZ3CZWE000001" | json '{wmi: .data.wmi, manufacturer: .data.manufacturer.name, check_digit_valid: .data.check_digit.valid}'

echo "9. the 422 problem on /v1/makes?per_page=500 (this call is expected to fail; it must not abort the walkthrough)"
STATUS9=$(curl -sS -H "Authorization: Bearer $VD_API_KEY" -H "Accept: application/json" -o "$TMP/problem.json" -w '%{http_code}' "$BASE/v1/makes?per_page=500")
json '{type, status, field: .errors[0].field}' < "$TMP/problem.json"
if [ "$STATUS9" != "422" ]; then
  echo "   expected 422, got $STATUS9" >&2
  exit 1
fi

echo "10. revalidation of /v1/makes/dacia with If-None-Match"
STATUS10=$(curl -sS -H "Authorization: Bearer $VD_API_KEY" -H "Accept: application/json" -H "If-None-Match: $ETAG" -o /dev/null -w '%{http_code}' "$BASE/v1/makes/dacia")
echo "   HTTP $STATUS10"
if [ "$STATUS10" != "304" ]; then
  echo "   expected 304, got $STATUS10" >&2
  exit 1
fi

echo "OK: 10 calls, last id $ID"
