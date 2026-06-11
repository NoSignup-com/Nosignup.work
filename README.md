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
- `localStorage` persistence (`nsw_tile`, `nsw_radius`, `nsw_remote`).
- Carousel pagination (prev/next) – simple offset, fetches from API.

Everything else (forms, carousel layout, ad columns, bottom banner) stays **pure HTML/CSS**.

---

## 3. BACKEND API – FILE SHARDS OVER HTTP

All endpoints respond with `Content-Type: application/json` unless serving a PDF.

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

**Body:** `multipart/form-data` or `application/x-www-form-urlencoded` with fields:

| Field | Required | Description |
|-------|----------|-------------|
| `tile` | yes | `lat_lon` from map |
| `category` | yes | one of the predefined categories (business_finance, administration, marketing_sales, engineering_technology, healthcare, education_training, construction_trades, arts, logistics_transportation, legal_government) |
| `pay_type` | yes | `remote_fixed`, `remote_hourly`, `inperson_fixed`, `inperson_hourly` |
| `pay_amount` | yes | free text, e.g. `$50/hour` |
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

### 3.3 Post a Resume (Text + optional PDF)

**POST** `index.php?api=post_resume`

**Fields:**

| Field | Required | Description |
|-------|----------|-------------|
| `tile` | yes | `lat_lon` from map |
| `name` | yes | full name |
| `email` | yes | contact email |
| `phone` | yes | contact phone |
| `min_salary` | no | free text |
| `keywords` | yes | skills, comma‑separated |
| `resume_text` | yes | plain text or markdown, max 5000 chars |
| `resume_pdf` | no | file upload (`application/pdf`), max 2MB |
| `remote` | yes | `0` or `1` (seeking remote work) |

**Storage:**
- Store `resume_text` in JSON.
- If PDF uploaded, save as `shards/{tile}/resumes/{filename}.pdf` and store `pdf_path` in JSON.
- Filename same as JSON (without extension) + `.pdf`.
- Lazy delete both files when JSON expires.

**Response:** `{"ok":true, "id":"filename"}`

### 3.4 Serve PDF

**GET** `index.php?api=serve_pdf&file=1700000000_crc32.pdf&tile=40_-74`

- Check file exists in `shards/{tile}/resumes/`.
- Set `Content-Type: application/pdf`, `Content-Disposition: inline` (or `attachment`).
- `readfile()` and exit.

### 3.5 Lazy Expiry

On every read (`glob`) inside listing endpoints, check each file’s `mtime`.  
If `time() - mtime > 90*86400`, `unlink()` the JSON (and PDF if exists).  
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

## 6. MIRROR DISCOVERY & FEDERATION (Future, but designed)

To achieve censorship resistance, the network must not rely on a central domain.

- Each mirror maintains `mirrors.json` – list of known mirror URLs (max 64).
- **Gossip protocol** (identical to nosignup.chat):
  - Client asks its home mirror `?api=mirror_random` → gets one random mirror URL.
  - Client then POSTs `?api=mirror_add&url=...` to that random mirror, telling it about its home mirror.
  - Mirrors cross‑pollinate without central coordination.
- **Job/resume federation**:
  - When a mirror receives a request for a foreign tile, it first checks its own shards. If not found, it **redirects** (HTTP 307) to a mirror that claims that tile (via a simple `tile_to_mirror` mapping that mirrors gossip).
  - Alternatively, simpler: every mirror stores **all tiles** – storage is cheap. Federation is only for redundancy, not sharding. For MVP, each mirror is independent. Federation can be added later.

Given the ethos of “single file, disposable mirrors”, the **recommended MVP**: no federation. Each instance stands alone. Users can manually copy the file to another host. If the original domain goes down, they use a mirror they already know (or discover via gossip in a future version).

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
        └── PHP backend (add later) – file shards, no database
               ├── GET ?api=jobs&tile=... → returns JSON listings
               ├── POST ?api=post_job → stores JSON in shards/{tile}/jobs/
               ├── POST ?api=post_resume → stores JSON + optional PDF
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
