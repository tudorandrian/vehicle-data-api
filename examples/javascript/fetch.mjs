// The walkthrough with fetch(). The client's get() below also implements following a 301
// (a retired slug) and honouring Retry-After on 429, but neither is exercised by this
// walkthrough: the seed has no retired slug, and the per-key rate (60/min) is never hit here.
// On a redirect to another origin (scheme, host or port), Node's fetch drops the Authorization
// header, as the Fetch standard requires, so the key never leaves the API's origin.
const base = process.env.VD_BASE_URL ?? 'http://localhost:8087';
const key = process.env.VD_API_KEY;
if (!key) { console.error('set VD_API_KEY'); process.exit(2); }

async function get(path, attempt = 0) {
  const res = await fetch(base + path, { headers: { Authorization: `Bearer ${key}`, Accept: 'application/json' }, redirect: 'follow' });
  if (res.status === 429 && attempt < 3) {
    const wait = Number(res.headers.get('Retry-After') ?? '1');
    await new Promise((r) => setTimeout(r, wait * 1000));
    return get(path, attempt + 1);
  }
  const body = await res.json();
  if (!res.ok) throw new Error(`${body.type} (${body.status}) on ${body.instance}, request ${body.request_id}`);
  return { body, attribution: res.headers.get('X-Data-Attribution'), etag: res.headers.get('ETag') };
}

const taxonomies = await get('/v1/taxonomies');
console.log('1.', taxonomies.body.data.map((t) => `${t.name}=${t.id}`).join(' '));
const dacia = await get('/v1/makes/dacia');
console.log('2.', dacia.body.data.id, dacia.body.sources.map((s) => s.key).join(','));
const byId = await get(`/v1/makes/${dacia.body.data.id}`);
console.log('3. by id →', byId.body.data.slug);
const models = await get('/v1/makes/dacia/models?sort=-ro_fleet_count&per_page=3');
console.log('4.', models.body.data.map((m) => `${m.slug}:${m.ro_fleet?.count ?? '-'}`).join(' '), '|', models.attribution);
const diesel = await get('/v1/models/dacia-duster/variants?fuel=diesel&euro_norm=euro_6d');
console.log('5.', diesel.body.data.map((v) => `${v.id} ${v.engine_cc}cc ${v.power_kw}kW ${v.co2_wltp}g ${v.euro_norm.code}`).join(' '));
const n1 = await get('/v1/models/fiat-ducato/variants?eu_category=n1');
console.log('6.', n1.body.data.map((v) => `${v.id} ${v.eu_category.code}`).join(' '));
const nat = await get('/v1/taxonomies/national_category?lang=en');
console.log('7.', nat.body.data.terms.slice(0, 3).map((t) => `${t.code}=${t.label}`).join(' '));
const vin = await get('/v1/vin/WVWZZZ3CZWE000001');
console.log('8.', vin.body.data.wmi, vin.body.data.manufacturer.name);

// Step 9 deliberately triggers the RFC 9457 problem response; a raw fetch() (not get())
// is used here so the walkthrough is not aborted by get()'s own error handling.
const problemRes = await fetch(base + '/v1/makes?per_page=500', { headers: { Authorization: `Bearer ${key}`, Accept: 'application/json' } });
const problem = await problemRes.json();
console.log('9.', problem.type, problem.status, problem.errors[0].field);
if (problemRes.status !== 422) throw new Error(`expected 422 on the problem demo, got ${problemRes.status}`);

// Step 10: the weak ETag must answer 304 with no body.
const again = await fetch(base + '/v1/makes/dacia', { headers: { Authorization: `Bearer ${key}`, 'If-None-Match': dacia.etag } });
if (again.status !== 304) throw new Error(`expected 304 on revalidation, got ${again.status}`);
console.log('10. revalidated → 304');

console.log(`OK: 10 calls, last id ${dacia.body.data.id}`);
