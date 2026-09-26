#!/usr/bin/env bash
# Regenerates the committed fixtures from the public sources. Run rarely; review the diff; fixtures stay ≤ 100 KB.
set -euo pipefail
FX=packages/vehicle-data-core/database/fixtures
mkdir -p "$FX"

# EEA — Romania, 2024 final, from 15 common makes, selected columns only, deduplicated to one row per
# (Mk, Cn, Ft) and then CAPPED PER MAKE (mk_rank <= 13, i.e. floor(200 / 15 makes)) instead of a plain
# `TOP 200 ... ORDER BY Mk, Cn, Ft`: an un-capped top-N cut is dominated alphabetically by whichever make
# has the most model/fuel combinations (BMW alone has 176 of the first 200 rows for RO 2024), starving
# every make ordered after it. The per-make cap is fully deterministic (no NEWID(), no hand edits) and
# guarantees every one of the 15 filtered makes that has any RO 2024 rows appears in the fixture, while
# staying well under the 200-row / 100 KB budget (182 rows, ~13 per make, fewer for makes with < 13
# distinct combinations). Discodata rejects a `WITH` CTE ("query is not allowed execution"), so the two
# ROW_NUMBER() passes are nested subqueries instead of named CTEs.
EEA_SQL="SELECT ID, MS, Mk, Cn, Mh, Man, Ct, Ft, Fm, [Ec (cm3)], [Ep (KW)], [M (kg)], [Ewltp (g/km)], [Enedc (g/km)], [Z (Wh/km)], Ech, Year, TAN, T, Va, Ve, Fc FROM (SELECT *, ROW_NUMBER() OVER (PARTITION BY Mk ORDER BY Cn, Ft, ID) AS mk_rank FROM (SELECT *, ROW_NUMBER() OVER (PARTITION BY Mk, Cn, Ft ORDER BY ID) AS rn FROM [CO2Emission].[latest].[co2cars_2024Fv30] WHERE MS='RO' AND Mk IN ('DACIA','VOLKSWAGEN','TESLA','TOYOTA','SKODA','RENAULT','FORD','HYUNDAI','BMW','MERCEDES-BENZ','KIA','FIAT','PEUGEOT','OPEL','SUZUKI')) t WHERE rn = 1) c WHERE mk_rank <= 13 ORDER BY Mk, Cn, Ft"
curl -sG "https://discodata.eea.europa.eu/sql" --data-urlencode "query=$EEA_SQL" --data-urlencode "p=1" --data-urlencode "nrOfHits=250" -o "$FX/eea_ro_2024.json"
python3 - "$FX/eea_ro_2024.json" <<'PY'
import json, sys
from collections import Counter
d = json.load(open(sys.argv[1], encoding='utf-8'))
rows = d['results']; assert 50 <= len(rows) <= 200, len(rows)
json.dump({'table': 'co2cars_2024Fv30', 'country': 'RO', 'retrieved': __import__('datetime').date.today().isoformat(), 'results': rows}, open(sys.argv[1], 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
print('eea rows:', len(rows))
print('eea per-make counts:', dict(sorted(Counter(r['Mk'] for r in rows).items())))
PY

# DRPCIV — Parc auto România, AUTOTURISM rows only, capped per make at floor(199 / 15) = 13 across the
# same 15 makes the EEA fixture covers, for diversity: a plain `head -n 200` after filtering is dominated by whichever
# make's AUTOTURISM rows sort first in the file (in practice BMW/MERCEDES-BENZ, which have 15-25k rows
# each nationally vs. a few hundred for TESLA/SKODA), so several of the 15 demo makes would never appear.
# The awk counter below takes the first 13 AUTOTURISM rows per make **in the file's own (deterministic,
# unsorted) order** — no NEWID(), no hand edits, same output on every re-run against the same upstream
# file. It also replaces `| head -n 200`: head closes its read end after N lines while curl is
# still writing, which raises SIGPIPE in curl and — under `set -euo pipefail` — aborts the script non-zero
# even though the fixture was written correctly. The awk process instead reads the whole stream to EOF
# and only *prints* the rows it keeps, so curl's pipe is never closed early and the script exits 0.
RO_URL="https://data.gov.ro/dataset/b93e0946-2592-4ed7-a520-e07cba6acd07/resource/822c3ee4-0496-4fe2-b921-f497dd597a9b/download/parc-auto-fara-combustibil.csv"
curl -sL "$RO_URL" | awk -F';' '
    NR==1 { print; next }
    $3=="AUTOTURISM" && $5 ~ /^(BMW|DACIA|FIAT|FORD|HYUNDAI|KIA|MERCEDES-BENZ|OPEL|PEUGEOT|RENAULT|SKODA|SUZUKI|TESLA|TOYOTA|VOLKSWAGEN)$/ {
        if (count[$5] < 13 && total < 199) { print; count[$5]++; total++ }
    }
' > "$FX/ro_fleet_2025.csv"
python3 - "$FX/ro_fleet_2025.csv" <<'PY'
import csv, sys
from collections import Counter
rows = list(csv.reader(open(sys.argv[1], encoding='utf-8'), delimiter=';'))
header, data = rows[0], rows[1:]
assert 1 <= len(data) <= 199, len(data)
print('ro-fleet lines (incl. header):', len(rows))
print('ro-fleet per-make counts:', dict(sorted(Counter(r[4] for r in data).items())))
PY

# Wikidata — manufacturers for a fixed list of make labels (CC0)
cat > /tmp/vd.sparql <<'SPARQL'
SELECT ?item ?itemLabel ?label ?countryCode ?inception ?parent ?website ?logo WHERE {
  VALUES ?label { "Dacia"@en "Volkswagen"@en "Tesla, Inc."@en "Toyota"@en "Škoda Auto"@en "Renault"@en "Ford Motor Company"@en "Hyundai Motor Company"@en "BMW"@en "Mercedes-Benz"@en "Kia"@en "Fiat"@en "Peugeot"@en "Opel"@en "Suzuki"@en "Honda"@en "Ferrari"@en "Audi"@en "SEAT"@en "Nissan"@en "Mazda"@en "Volvo Cars"@en "Citroën"@en "Alfa Romeo"@en "Mitsubishi Motors"@en }
  ?item rdfs:label ?label ; wdt:P31/wdt:P279* wd:Q786820 .
  OPTIONAL { ?item wdt:P17 ?country . ?country wdt:P297 ?countryCode }
  OPTIONAL { ?item wdt:P571 ?inception }
  OPTIONAL { ?item wdt:P749 ?parent }
  OPTIONAL { ?item wdt:P856 ?website }
  OPTIONAL { ?item wdt:P154 ?logo }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en" . }
}
ORDER BY ?item
SPARQL
curl -sG "https://query.wikidata.org/sparql" -H "Accept: application/sparql-results+json" -H "User-Agent: vehicle-data-api-fixtures/1.0 (open-data importer)" --data-urlencode "query@/tmp/vd.sparql" -o /tmp/vd-sparql.json
python3 - /tmp/vd-sparql.json "$FX/wikidata_manufacturers.json" <<'PY'
import json, sys, urllib.parse, urllib.request
sparql = json.load(open(sys.argv[1], encoding='utf-8'))
files = sorted({urllib.parse.unquote(b['logo']['value'].split('/Special:FilePath/')[1]) for b in sparql['results']['bindings'] if 'logo' in b})
commons = {}
for f in files:
    q = urllib.parse.urlencode({'action': 'query', 'titles': 'File:' + f, 'prop': 'imageinfo', 'iiprop': 'extmetadata', 'iiextmetadatafilter': 'LicenseShortName', 'format': 'json'})
    req = urllib.request.Request('https://commons.wikimedia.org/w/api.php?' + q, headers={'User-Agent': 'vehicle-data-api-fixtures/1.0'})
    page = next(iter(json.load(urllib.request.urlopen(req))['query']['pages'].values()))
    commons[f] = page.get('imageinfo', [{}])[0].get('extmetadata', {}).get('LicenseShortName', {}).get('value')
json.dump({'sparql': sparql, 'commons': commons}, open(sys.argv[2], 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
print('wikidata bindings:', len(sparql['results']['bindings']), 'logos:', len(commons))
PY

# vPIC — WMIs for ten manufacturer names (US public domain)
python3 - "$FX/vpic_wmi.json" <<'PY'
import json, sys, urllib.request, time
rows = []
for name in ['volkswagen', 'toyota', 'ford', 'hyundai', 'bmw', 'mercedes', 'kia', 'honda', 'tesla', 'renault']:
    data = json.load(urllib.request.urlopen(f'https://vpic.nhtsa.dot.gov/api/vehicles/GetWMIsForManufacturer/{name}?format=json'))
    rows += [{k: r.get(k) for k in ('WMI', 'Name', 'Country', 'VehicleType')} for r in data['Results']]
    time.sleep(0.3)
rows = rows[:200]
json.dump({'results': rows}, open(sys.argv[1], 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
print('vpic rows:', len(rows))
PY
