"""The walkthrough with urllib: bearer key, problem bodies, weak-ETag revalidation. Run: VD_API_KEY=… python3 examples/python/client.py"""
import json, os, sys, urllib.error, urllib.parse, urllib.request

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")  # a non-UTF-8 console codepage must not break the arrows below

BASE = os.environ.get("VD_BASE_URL", "http://localhost:8087")
KEY = os.environ.get("VD_API_KEY") or sys.exit("set VD_API_KEY")
_cache: dict[str, tuple[str, dict]] = {}
last_status = 0


class SameOriginAuth(urllib.request.HTTPRedirectHandler):
    """Follows redirects (a retired slug answers 301), but drops the key when one changes the scheme, host or port."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        new = super().redirect_request(req, fp, code, msg, headers, newurl)
        if new is not None and urllib.parse.urlsplit(req.full_url)[:2] != urllib.parse.urlsplit(new.full_url)[:2]:
            new.remove_header("Authorization")
        return new


urlopen = urllib.request.build_opener(SameOriginAuth).open


def get(path: str) -> dict:
    global last_status
    headers = {"Authorization": f"Bearer {KEY}", "Accept": "application/json"}
    if path in _cache:
        headers["If-None-Match"] = _cache[path][0]
    req = urllib.request.Request(BASE + path, headers=headers)
    try:
        with urlopen(req) as res:
            last_status = res.status
            body = json.load(res)
            if etag := res.headers.get("ETag"):
                _cache[path] = (etag, body)
            return body
    except urllib.error.HTTPError as e:
        last_status = e.code
        if e.code == 304:
            return _cache[path][1]
        problem = json.load(e)
        raise SystemExit(f"{problem['type']} ({problem['status']}) on {problem['instance']}, request {problem['request_id']}")


def get_problem(path: str) -> tuple[int, dict]:
    """Like get(), but returns (status, body) instead of raising - used to demonstrate the
    RFC 9457 problem shape (step 9) without aborting the rest of the walkthrough."""
    req = urllib.request.Request(BASE + path, headers={"Authorization": f"Bearer {KEY}", "Accept": "application/json"})
    try:
        with urlopen(req) as res:
            return res.status, json.load(res)
    except urllib.error.HTTPError as e:
        return e.code, json.load(e)


tax = get("/v1/taxonomies")
print("1.", " ".join(f"{t['name']}={t['id']}" for t in tax["data"]))
dacia = get("/v1/makes/dacia")
print("2.", dacia["data"]["id"], ",".join(s["key"] for s in dacia["sources"]))
print("3. by id →", get(f"/v1/makes/{dacia['data']['id']}")["data"]["slug"])
models = get("/v1/makes/dacia/models?sort=-ro_fleet_count&per_page=3")
print("4.", " ".join(f"{m['slug']}:{m.get('ro_fleet', {}).get('count', '-')}" for m in models["data"]))
diesel = get("/v1/models/dacia-duster/variants?fuel=diesel&euro_norm=euro_6d")
print("5.", " ".join(f"{v['id']} {v['engine_cc']}cc {v['power_kw']}kW {v['co2_wltp']}g {v['euro_norm']['code']}" for v in diesel["data"]))
n1 = get("/v1/models/fiat-ducato/variants?eu_category=n1")
print("6.", " ".join(f"{v['id']} {v['eu_category']['code']}" for v in n1["data"]))
nat = get("/v1/taxonomies/national_category?lang=en")
print("7.", " ".join(f"{t['code']}={t['label']}" for t in nat["data"]["terms"][:3]))
vin = get("/v1/vin/WVWZZZ3CZWE000001")
print("8.", vin["data"]["wmi"], vin["data"]["manufacturer"]["name"])

status9, problem = get_problem("/v1/makes?per_page=500")
print("9.", problem["type"], problem["status"], problem["errors"][0]["field"])
if status9 != 422:
    sys.exit(f"expected 422 on the problem demo, got {status9}")

get("/v1/makes/dacia")  # a conditional request now that the ETag is cached
if last_status != 304:
    sys.exit(f"expected 304 on revalidation, got {last_status}")
print("10. revalidated → 304 (cached body reused)")

print(f"OK: 10 calls, last id {dacia['data']['id']}")
