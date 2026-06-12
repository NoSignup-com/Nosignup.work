# Nosignup.work

# NOSIGNUP.WORK – Stateless Job & Resume Exchange

**One PHP file, pure HTTP, tile‑based sharding, no signup.**  
Zero barrier: type a few chars, click once, you're posting or applying.  
No signup/install/account/cookie. Single `index.php` over ordinary HTTP.  
Censorship resistance comes from disposable mirrors and proxy compatibility, not a smarter server.

---

## 0. PRIME DIRECTIVE

Zero barrier, instant, light as a feather.  
No signup, no account, no email, no captcha, no tracking.  
One PHP file, runs on the cheapest shared host or a static server (backend optional).  
When unsure, pick the simpler, more portable, server‑lighter option.  
Size discipline: readable source stays under ~150KB; minify after, not before.

---

## 1. HARD BOUNDARIES (each violation breaks the directive)

- **MUST NOT** use WebSockets, SSE, long‑poll, sessions, cookies, or any database.
- **MUST** remain a single `index.php` – NO NEW FILES.
- **MUST** route server‑side **only** on URL parameters (tile, radius, api) – never decode the JSON body for routing.
- **MUST** store data as flat JSON files in shard directories:  
  `/var/lib/nosignup/work/shards/{tile}/{jobs|resumes}/`  
  One file per listing. Lazy delete on read if mtime > 90 days.
- **MUST** keep diagnostics VERBOSE (HTTP headers, optional trace overlay) – it's how this gets debugged.
- **SHOULD** reduce moving parts over time. No build step, no dependencies.

---

## 2. FRONT‑END – PURE CSS NAVIGATION (Checkbox Hack)

The entire panel toggling uses **hidden checkboxes** with sibling selectors (`~`).  
**No JavaScript for navigation** – the map is the only JS dependency.

- Four hidden `<input type="checkbox">` **must appear immediately before** their corresponding panels (`.container`) and the map overlay (`.map-overlay`).
- Four corner **Back buttons** are pinned absolutely (`top:0/left:0` etc.) with fixed width 16.5% / height 16.33%.  
  **Never move or resize them**. Centre content in a safe column (`width:64%`) to clear the corners.
- **Nudge animations** (`@keyframes nudge-left` / `nudge-right`) are user‑loved – preserve exact timings (18s infinite, 2.5%/10%/12.5% keyframes).
- **Map overlay** toggles via `#toggleMapMenu:checked ~ .map-overlay`. Works without JS (canvas drawing still needs JS).

**JavaScript is allowed only for:**
- Canvas map drawing (720×360 grid, 2px/degree, 64 800 tiles).
- Click → tile selection (`floor(lat)+"_"+floor(lon)`).
- Radius slider (1–7, draws circle, radius = degree × ~111km).
- “Include remote” checkbox.
- **Zoom (7 levels)** via mouse wheel / two-finger pinch (around the cursor), or the
  zoom slider (around the laid point; disabled with a "drop a point first" tip until a
  point exists). Level 7 ≈ Europe fills the canvas. Zoom is not persisted.
- **Capital dots**: black 1°×1° squares + small labels for ~196 national capitals
  (Natural Earth, CC0) plus a curated set of self-determination / independence-movement
  seats, shown **only when zoomed in**; bigger capitals win label space on collision.
  Independence cases (city labelled with the nation): Naypyidaw (Burma), Kathmandu
  (Nepal), Jolo (Sulu), Lhasa (Tibet), Laâyoune (W. Sahara), Ramallah (Palestine),
  Erbil (Kurdistan), Taipei (Taiwan), Pristina (Kosovo), Hargeisa (Somaliland),
  Barcelona (Catalonia), Edinburgh (Scotland), Grozny (Chechnya), Jayapura (West
  Papua), Ürümqi (East Turkestan), Buka (Bougainville). Curated and easy to edit.
- `localStorage` persistence (`nsw_tile`, `nsw_radius`, `nsw_remote`).
- Carousel pagination (prev/next) – simple offset, fetches from API.

Everything else (forms, carousel layout, ad columns, bottom banner) stays **pure HTML/CSS**.

---

## 3. BACKEND API – FILE SHARDS OVER HTTP

All endpoints respond with `Content-Type: application/json`.

### 3.1 List Jobs / Resumes

**GET** `index.php?api=jobs&tile=40_-74&radius=2&remote=1&keyword=developer&page=0`  
**GET** `index.php?api=resumes&tile=40_-74&radius=2&remote=1&keyword=plumber&page=0`

**Parameters:**
| Param | Type | Description |
|-------|------|-------------|
| `tile` | string | `lat_lon` (e.g. `40_-74`) – required |
| `radius` | int | 1–7, number of neighbour tiles in each direction (Manhattan distance) |
| `remote` | 0/1 | if 1, also return listings where `remote=true` (no tile restriction) |
| `keyword` | string | search in title, description, keywords (case‑insensitive) |
| `page` | int | 0‑based, returns 10 listings per page |

**Response:**
```json
{
  "total": 42,
  "listings": [
    {
      "id": "1700000000_crc32",
      "tile": "40_-74",
      "title": "Bus Driver Needed",
      "category": "logistics_transportation",
      "pay_type": "inperson_hourly",
      "pay_amount": "$25/hr",
      "description": "...",
      "contact_email": "driver@example.com",
      "contact_phone": "+123456789",
      "keywords": "bus,driver,CDL",
      "timestamp": 1700000000
    }
  ]
}
```

**Server logic:**
- Parse `tile` → `$lat = (int)$parts[0]`, `$lon = (int)$parts[1]`.
- Build set of tiles to scan:  
  For `$dx = -radius .. radius`, `$dy = -radius .. radius`, add `($lat+$dy).'_'.($lon+$dx)`.
- For each tile directory, `glob()` all `.json` files in `shards/{tile}/jobs/` (or `resumes/`).
- Read each file, check mtime > 90d → unlink.  
- Filter by `remote` flag (if remote=1 and listing.remote===true, keep regardless of tile; else require listing.tile matches the requested tile exactly).
- Filter by keyword (if present, match against title, description, keywords fields).
- Sort by timestamp desc, slice by page (10 per page).
- Return JSON.

### 3.2 Post a Job

**POST** `index.php?api=post_job`

**Pay is mandatory.** A job with no `pay_amount` is rejected (400) — *no pay specified means no job posted*. The field stays free text so the variance survives (`$25/hr`, `$500 flat`, `$20‑25 DOE`); it just may not be blank.

**Body:** `application/x-www-form-urlencoded` (or `multipart/form-data`) with fields:

| Field | Required | Description |
|-------|----------|-------------|
| `tile` | yes | `lat_lon` from map |
| `category` | yes | one of the predefined categories (business_finance, administration, marketing_sales, engineering_technology, healthcare, education_training, construction_trades, arts, logistics_transportation, legal_government) |
| `pay_type` | yes | `remote_fixed`, `remote_hourly`, `inperson_fixed`, `inperson_hourly` |
| `pay_amount` | **yes** | free text, e.g. `$50/hour` — **may not be blank** |
| `title` | yes | max 120 chars |
| `keywords` | yes | comma‑separated, max 200 chars |
| `email` | no | for contact |
| `phone` | no | for contact |
| `details` | yes | free text, max 2000 chars |
| `remote` | yes | `0` or `1` (whether the job can be done remotely) |

**Anti‑spam (optional but recommended):**
- IP‑based rate limit: max 5 posts per hour per IP (store in `/dev/shm/nosignup/work/ip_posts/{ip}.count` with timestamp).
- No CAPTCHA, no proof‑of‑work – keeps zero barrier. Admin can delete spam via filesystem.

**Storage:**
- Sanitise all fields (strip HTML tags, escape JSON).
- Generate filename: `{timestamp}_{crc32(ip . microtime)}.json`.
- Write to `shards/{tile}/jobs/{filename}`.
- Return `{"ok":true, "id":"filename"}`.

### 3.3 Post a Resume (Text only)

**POST** `index.php?api=post_resume`

Résumés are plain text — no file uploads. The applicant pastes their whole
résumé body into the `resume_text` field.

**Fields:**

| Field | Required | Description |
|-------|----------|-------------|
| `tile` | yes | `lat_lon` from map |
| `name` | yes | full name |
| `email` | yes | contact email |
| `phone` | no | contact phone |
| `min_salary` | no | free text |
| `keywords` | no | skills, comma‑separated |
| `resume_text` | yes | the whole résumé as plain text, max 20000 chars |
| `remote` | yes | `0` or `1` (seeking remote work) |

**Storage:**
- Store `resume_text` (and the other fields) as one JSON file in `shards/{tile}/resumes/`.

**Response:** `{"ok":true, "id":"filename"}`

### 3.4 Lazy Expiry

On every read (`glob`) inside listing endpoints, check each file’s `mtime`.  
If `time() - mtime > 90*86400`, `unlink()` the JSON.  
No separate cron job.

---

## 4. GEOGRAPHIC TILE SYSTEM

- **Canvas**: 720×360 pixels, 2px per degree.  
  `x = (lon + 180) * 2`, `y = (90 - lat - 1) * 2`.
- **Tile format**: `floor(latitude)_floor(longitude)`.  
  Example: New York City (40.7128°N, 74.0060°W) → `"40_-74"`.
- **Radius**: integer 1–7, each step adds ±1 degree in both latitude and longitude.  
  Radius 1 includes the centre tile and its 8 neighbours (Chebyshev distance ≤1).
- **Remote flag**: when checked, the API **also** returns listings that have `"remote": true` regardless of tile. These listings are stored once (in the poster’s own tile) but appear in every search when `remote=1`.

**Why tiles, not lat/lon ranges?**  
File sharding by tile keeps directory sizes small (<1000 files per tile in most cases).  
No database, no geospatial index – just filesystem glob.

---

## 5. LOCALSTORAGE PREFERENCES (Client‑side only)

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `nsw_tile` | string | `"40_-74"` | Last selected tile |
| `nsw_radius` | int | `1` | 1–7 |
| `nsw_remote` | boolean | `false` | Include remote listings |

These are **never sent to the server** except as URL parameters when the user explicitly searches (the search button builds `?tile=...&radius=...&remote=...&keyword=...`).

---

## 6. MIRRORS — SELF-DISTRIBUTION, GOSSIP & BYTE PARITY

Censorship resistance comes from many disposable, independent mirrors, not a
central domain. nosignup.work follows the **same staged rollout as nosignup.chat**:
build the cheap, abuse-free pieces now; defer server-side gossip until it earns
its moving parts.

### 6.1 BUILT NOW

- **`index.php?src=1`** — serves this file's own raw bytes verbatim (`readfile(__FILE__)`),
  plus an `X-NSW-Source-SHA256` header. This is both the self-distribution download
  ("drop it on any PHP host") and the basis for the parity check below.
- **Host-a-Mirror UI** — the centred `＋ Host a Mirror` button opens a modal with the
  `?src=1` download and a URL/IP field. Submitted mirrors are **staged client-side
  only**, in `localStorage['nosignup_pending_mirrors']` (FIFO, cap 64). No server
  endpoint, no `mirrors.json`, no write surface — nothing a spammer can target.

### 6.2 LOCKED DESIGN — SERVER GOSSIP (build later, identical to nosignup.chat)

Deliberately **not** built yet (matches chat's stance: a write endpoint open to the
public needs an abuse story first). When it lands, both apps implement it the same way:

- Each mirror keeps `mirrors.json` (known mirror URLs, cap 64) in the data dir.
- `GET ?api=mirror_list` → the list. `GET ?api=mirror_random` → one random mirror.
- `POST ?api=mirror_add&url=…` adds a candidate **only after a byte-parity check**:
  1. Fetch `{candidate}/index.php?src=1`.
  2. `hash('sha256', body)` must equal **our own** `hash_file('sha256', __FILE__)`.
  3. Identical bytes ⇒ it is genuinely this app (not a look-alike / honeypot) ⇒ add it.
     Anything else ⇒ reject, never store.
- **Re-check parity before gossiping a mirror onward.** A mirror that passed once may
  later be replaced with divergent code; re-fetch `?src=1` and re-compare its hash to
  ours before handing it to another peer in `mirror_random`/`mirror_list`. Drop on mismatch.
- The staged client-side list (6.1) is what a browser would flush into `mirror_add`
  calls during a "gossip pass" once the endpoint exists.

**Constraint to resolve before building:** `mirror_add` makes the server fetch an
arbitrary URL (SSRF-shaped). Mitigations: only ever fetch the fixed `?src=1` path,
require http(s), short timeout, response-size cap, and the parity hash (a response
that isn't our exact bytes is discarded anyway). Until that's locked down, gossip
stays client-side.

### 6.3 FEDERATION OF LISTINGS

Not needed for the MVP. Storage is cheap, so each mirror simply stands alone and
serves whatever tiles it has. Cross-mirror tile routing (307 redirects, `tile_to_mirror`
gossip) remains a future option, not a requirement — an independent mirror is fully useful.

---

## 7. PRESERVE – NEVER BREAK THESE

- **Checkbox order** – hidden `<input>` must appear **directly before** the panels and map overlay they control. The `~` sibling selector depends on it.
- **Corner Back buttons** – fixed width 16.5%, height 16.33%. Content kept in `width:64%` centred column.
- **Nudge animations** – exact keyframes (`nudge-left`, `nudge-right`) with 18s cycle. Do not smooth or remove.
- **Tile drawing** – 2px/degree. `tileAt()` must use `Math.floor(mx / 2) - 180` and `89 - Math.floor(my / 2)`.
- **No JavaScript for panel navigation** – the checkbox hack is the spec.
- **PHP routes only by URL parameters** – never decode JSON body to decide which tile or shard.

---

## 8. LANDMINES – DO NOT REINTRODUCE

| Landmine | Why It Breaks |
|----------|----------------|
| Moving hidden checkboxes | Sibling selectors fail – panels won’t open. |
| Adding JavaScript toggles | Violates “pure CSS navigation” and adds moving parts. |
| Changing Back button dimensions | Content safe column no longer clears corners. |
| Using database | Adds setup, destroys portability, violates “single file”. |
| Storing tile/radius in cookies | Cookies are sent to server – tracking vector. Use localStorage only. |
| Allowing HTML in job details | XSS risk. Sanitise: `strip_tags()` or `htmlspecialchars()` on output. |
| Forgetting lazy delete | Shards fill up with stale listings. |
| Routing by request body | Breaks the stateless, cacheable design. |
| Overwriting nudge animation | Users complain – it’s a signature delight. |

---

## 9. SUMMARY – THE BIG PICTURE

**One file to rule them all.**

```
[User downloads index.php]
        │
        ├── static HTML/CSS – 4 quadrants, map, forms (works offline)
        ├── JavaScript – map, localStorage, carousel fetch
        └── PHP backend – file shards, no database
               ├── GET ?api=jobs&tile=... → returns JSON listings
               ├── POST ?api=post_job → stores JSON in shards/{tile}/jobs/ (pay required)
               ├── POST ?api=post_resume → stores JSON in shards/{tile}/resumes/ (text only)
               └── lazy expiry >90 days
```

**Why this is maximally useful within the nosignup ethos:**

- **Zero barrier** – no signup, no email verification, no captcha. Post a job in 10 seconds.
- **Geographic search without a database** – tile sharding scales to millions of listings.
- **Remote work support** – the `remote` flag lets users find remote jobs/resumes globally.
- **File‑based storage** – runs on any PHP host; backup by copying the shard directory.
- **Spam mitigation** – IP rate limiting (optional) and automatic expiry keep the board clean without admin overhead.
- **Censorship resistance** – each mirror is independent; users can host their own. Federation via gossip can be added later without changing the core.
- **Single file** – drop it, configure nothing, it works.

**The contract is simple: preserve the checkbox hack, protect the corner buttons, keep storage flat, and never weaken the rules to excuse broken code.**

---

*NOSIGNUP.WORK – work without barriers or sabotage.*
