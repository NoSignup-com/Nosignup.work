<?php
/* ============================================================
   NOSIGNUP.WORK — single-file stateless job/resume exchange.
   API: index.php?api=jobs|resumes|post_job|post_resume   (text-only; no file uploads)
   Everything else falls through to the HTML/CSS/JS front-end below.

   STORAGE IS DROP-IN PORTABLE. Resolution order (first writable wins):
     1. env NSW_DATA_DIR (set this on a real host to live outside webroot)
     2. /var/lib/nosignup/work        (README default; Linux w/ access)
     3. <dir-of-index.php>/.nsw-data   (works literally anywhere)
   A protective .htaccess + index guard is dropped into the data dir so
   the JSON shards are not directly web-readable on Apache. On nginx,
   set NSW_DATA_DIR to a path outside your webroot.
   ============================================================ */
define('NSW_MAX_POSTS_HOUR', 5);
define('NSW_EXPIRY_DAYS',    90);                 // listings auto-delete after this many days
define('NSW_EXPIRY_SEC',     NSW_EXPIRY_DAYS * 86400);
define('NSW_PAGE_SIZE',      10);

/* ---- portable base-directory resolution (cached) ---- */
function nsw_base() {
    static $base = null;
    if ($base !== null) return $base;
    $candidates = [];
    if (getenv('NSW_DATA_DIR')) $candidates[] = rtrim(getenv('NSW_DATA_DIR'), "/\\");
    $candidates[] = '/var/lib/nosignup/work';
    $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . '.nsw-data';
    foreach ($candidates as $c) {
        if ((is_dir($c) && is_writable($c)) || @mkdir($c, 0755, true) || is_dir($c)) {
            // best-effort: keep shards out of the browser on Apache hosts
            if (!file_exists("$c/.htaccess")) @file_put_contents("$c/.htaccess", "Require all denied\nDeny from all\n");
            if (!file_exists("$c/index.html")) @file_put_contents("$c/index.html", "");
            $base = $c;
            return $base;
        }
    }
    // last resort: system temp (non-persistent, but never fatal)
    $base = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'nsw-data';
    @mkdir($base, 0755, true);
    return $base;
}
function nsw_shards()    { return nsw_base() . DIRECTORY_SEPARATOR . 'shards'; }
function nsw_ratedir()   {
    // prefer a RAM disk when present (POSIX /dev/shm); else live under the data dir.
    // Guard on DIRECTORY_SEPARATOR so Windows doesn't fabricate a bogus C:\dev\shm.
    if (DIRECTORY_SEPARATOR === '/' && is_dir('/dev/shm') && is_writable('/dev/shm')) {
        $d = '/dev/shm/nosignup_work_ip';
        if ((is_dir($d) || @mkdir($d, 0700, true)) && is_writable($d)) return $d;
    }
    return nsw_base() . DIRECTORY_SEPARATOR . 'ip_posts';
}
/* The aggregate shard that makes remote listings visible from any search. */
define('NSW_REMOTE_TILE', '_remote');

function nsw_json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-NSW-Backend: file-shard');           // verbose diagnostics (README §0/§1)
    header('X-NSW-Data-Dir: ' . nsw_base());
    echo json_encode($data);
    exit;
}
function nsw_san($s, $max = 2000) {
    return htmlspecialchars(substr(strip_tags(trim((string)($s ?? ''))), 0, $max), ENT_QUOTES, 'UTF-8');
}
function nsw_tile_ok($t) {
    return $t && preg_match('/^-?\d{1,3}_-?\d{1,3}$/', $t);
}
function nsw_tiles($tile, $r) {
    [$lat, $lon] = array_map('intval', explode('_', $tile));
    $out = [];
    for ($dy = -$r; $dy <= $r; $dy++)
        for ($dx = -$r; $dx <= $r; $dx++)
            $out[] = ($lat + $dy) . '_' . ($lon + $dx);
    return $out;
}
function nsw_rate_ok($ip) {
    $dir = nsw_ratedir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $f   = $dir . DIRECTORY_SEPARATOR . md5($ip) . '.json';
    $now = time();
    $arr = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : [];
    $arr = array_values(array_filter($arr, fn($t) => $now - $t < 3600));
    if (count($arr) >= NSW_MAX_POSTS_HOUR) return false;
    $arr[] = $now;
    @file_put_contents($f, json_encode($arr), LOCK_EX);
    return true;
}
function nsw_new_id($ip) {
    return time() . '_' . sprintf('%08x', crc32($ip . microtime()));
}
function nsw_mkdir($path) {
    if (!is_dir($path)) @mkdir($path, 0755, true);
    return is_dir($path);
}
function nsw_expire($file) {
    if (filemtime($file) < time() - NSW_EXPIRY_SEC) {
        @unlink($file);
        return true;
    }
    return false;
}
/* Store one listing JSON under a tile dir; also mirror remote ones into the
   _remote aggregate so they surface in every remote-enabled search. */
function nsw_store($type, $tile, $id, $data) {
    $primary = nsw_shards() . "/$tile/$type/";
    if (!nsw_mkdir($primary)) return false;
    $ok = @file_put_contents($primary . $id . '.json', json_encode($data), LOCK_EX) !== false;
    if ($ok && !empty($data['remote'])) {
        $agg = nsw_shards() . '/' . NSW_REMOTE_TILE . "/$type/";
        if (nsw_mkdir($agg)) @file_put_contents($agg . $id . '.json', json_encode($data), LOCK_EX);
    }
    return $ok;
}
function nsw_listings($type, $tile, $radius, $with_remote, $keyword, $page) {
    $tiles = nsw_tiles($tile, $radius);
    if ($with_remote) $tiles[] = NSW_REMOTE_TILE;     // pull in globally-remote listings
    $all  = [];
    $seen = [];
    foreach ($tiles as $t) {
        $dir = nsw_shards() . "/$t/$type/";
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '*.json') ?: [] as $f) {
            if (nsw_expire($f)) continue;
            $d = json_decode(@file_get_contents($f), true);
            if (!$d || !isset($d['id']) || isset($seen[$d['id']])) continue;
            // in the _remote aggregate, every entry is by definition remote-eligible
            if ($t !== NSW_REMOTE_TILE && $d['tile'] !== $t && !($with_remote && !empty($d['remote']))) continue;
            if (!$with_remote && !empty($d['remote']) && $d['tile'] !== $t) continue;
            $seen[$d['id']] = 1;
            $all[] = $d;
        }
    }
    if ($keyword !== '') {
        $kw  = function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword);
        $low = fn($s) => function_exists('mb_strtolower') ? mb_strtolower((string)$s) : strtolower((string)$s);
        $all = array_filter($all, fn($l)
            => strpos($low($l['title']       ?? ''), $kw) !== false
            || strpos($low(($l['description'] ?? '') . ' ' . ($l['resume_text'] ?? '')), $kw) !== false
            || strpos($low($l['keywords']    ?? ''), $kw) !== false
        );
    }
    usort($all, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
    $total = count($all);
    return ['total' => $total, 'page' => $page, 'listings' => array_values(array_slice($all, $page * NSW_PAGE_SIZE, NSW_PAGE_SIZE))];
}
if (isset($_GET['api'])) {
    $api = $_GET['api'];

    /* --- GET jobs / resumes --- */
    if ($api === 'jobs' || $api === 'resumes') {
        $tile = $_GET['tile'] ?? '';
        if (!nsw_tile_ok($tile)) nsw_json_out(['error' => 'bad tile'], 400);
        $radius = max(1, min(7, (int)($_GET['radius'] ?? 1)));
        $remote = !empty($_GET['remote']) && $_GET['remote'] !== '0';
        $kw     = trim($_GET['keyword'] ?? '');
        $page   = max(0, (int)($_GET['page'] ?? 0));
        nsw_json_out(nsw_listings($api, $tile, $radius, $remote, $kw, $page));
    }

    /* --- POST job --- */
    if ($api === 'post_job') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') nsw_json_out(['error' => 'POST only'], 405);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!nsw_rate_ok($ip)) nsw_json_out(['error' => 'rate limited (max 5/hr)'], 429);
        $tile = $_POST['tile'] ?? '';
        if (!nsw_tile_ok($tile)) nsw_json_out(['error' => 'bad tile'], 400);
        $cats      = ['business_finance','administration','marketing_sales','engineering_technology','healthcare','education_training','construction_trades','arts','logistics_transportation','legal_government'];
        $pay_types = ['remote_fixed','remote_hourly','inperson_fixed','inperson_hourly'];
        $cat        = in_array($_POST['category'] ?? '', $cats, true)      ? $_POST['category'] : '';
        $pay_type   = in_array($_POST['pay_type'] ?? '', $pay_types, true) ? $_POST['pay_type'] : '';
        $pay_amount = trim((string)($_POST['pay_amount'] ?? ''));
        // Pay is MANDATORY — no pay specified means no job posted. Free text keeps
        // the variance ($25/hr, $500 flat, "$20-25 DOE"); it just may not be blank.
        if (!$cat || !$pay_type || $pay_amount === '' || empty($_POST['title']) || empty($_POST['details']))
            nsw_json_out(['error' => 'missing required fields (category, pay type, pay amount, title, details)'], 400);
        $id   = nsw_new_id($ip);
        $remote = (!empty($_POST['remote']) && $_POST['remote'] !== '0') || strpos($pay_type, 'remote') === 0;
        $data = [
            'id'            => $id,
            'tile'          => $tile,
            'title'         => nsw_san($_POST['title'],      120),
            'category'      => $cat,
            'pay_type'      => $pay_type,
            'pay_amount'    => nsw_san($pay_amount, 80),
            'description'   => nsw_san($_POST['details'],    2000),
            'contact_email' => nsw_san($_POST['email']   ?? '', 200),
            'contact_phone' => nsw_san($_POST['phone']   ?? '', 40),
            'keywords'      => nsw_san($_POST['keywords'] ?? '', 200),
            'remote'        => $remote,
            'timestamp'     => time(),
        ];
        if (!nsw_store('jobs', $tile, $id, $data)) nsw_json_out(['error' => 'storage not writable'], 500);
        nsw_json_out(['ok' => true, 'id' => $id]);
    }

    /* --- POST resume --- */
    if ($api === 'post_resume') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') nsw_json_out(['error' => 'POST only'], 405);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!nsw_rate_ok($ip)) nsw_json_out(['error' => 'rate limited (max 5/hr)'], 429);
        $tile = $_POST['tile'] ?? '';
        if (!nsw_tile_ok($tile)) nsw_json_out(['error' => 'bad tile'], 400);
        // text-only resumes — name, email, and the pasted resume body are required
        if (empty($_POST['name']) || empty($_POST['email']) || empty($_POST['resume_text']))
            nsw_json_out(['error' => 'missing required fields (name, email, resume_text)'], 400);
        $id  = nsw_new_id($ip);
        $remote = !empty($_POST['remote']) && $_POST['remote'] !== '0';
        $data = [
            'id'          => $id,
            'tile'        => $tile,
            'name'        => nsw_san($_POST['name'],          120),
            'email'       => nsw_san($_POST['email'],         200),
            'phone'       => nsw_san($_POST['phone'] ?? '',    40),
            'min_salary'  => nsw_san($_POST['min_salary'] ?? '', 80),
            'keywords'    => nsw_san($_POST['keywords'] ?? '', 200),
            'resume_text' => nsw_san($_POST['resume_text'], 20000),  // whole resume body pasted in
            'remote'      => $remote,
            'timestamp'   => time(),
        ];
        if (!nsw_store('resumes', $tile, $id, $data)) nsw_json_out(['error' => 'storage not writable'], 500);
        nsw_json_out(['ok' => true, 'id' => $id]);
    }

    nsw_json_out(['error' => 'unknown api'], 400);
}

/* Source self-distribution + parity basis (same as nosignup.chat's ?src=1).
   Serves THIS file's own raw bytes — no new files. A mirror-adder fetches
   {mirror}/index.php?src=1 and compares its hash to ours; identical bytes =
   trusted mirror. See README §6 for the (not-yet-built) gossip protocol. */
if (isset($_GET['src'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="index.php"');
    header('X-NSW-Source-SHA256: ' . hash_file('sha256', __FILE__));
    header('Content-Length: ' . filesize(__FILE__));
    readfile(__FILE__);
    exit;
}
?>
<!DOCTYPE html>
<!--
================================================================================
DO NOT DELETE/REMOVE THIS BLOCK — NOSIGNUP.WORK — DO NOT DELETE/REMOVE THIS BLOCK
================================================================================
NOSIGNUP.WORK — stateless job/resume exchange. No signup, no tracking.
localStorage allowed. PHASE: front-end + PHP file-shard backend WIRED. Text-only
listings; jobs MUST specify pay; posters can't edit/remove a post; auto-deletes at 90 days.

ARCHITECTURE (do not break):
- The four quadrants AND the map overlay toggle via a pure-CSS checkbox hack.
  The hidden checkboxes MUST stay directly before the elements they reveal
  (the "~" sibling selectors depend on this DOM ordering).
- JavaScript is required ONLY for the map (canvas grid, click->tile, radius slider,
  remote toggle, localStorage). Everything else is HTML/CSS — keep it that way.

PRESERVE (intentional — do NOT "fix"):
- The .LeftArrow / .RightArrow nudge animations (@keyframes nudge-left / nudge-right).
- The four corner Back buttons are pinned in their corners. NEVER move or resize them.
  Clear them by keeping content in a centred safe column (~18%–82%) instead.

WHEN THE BACKEND LANDS:
- Tile = floor(lat)_floor(lon); canvas is 720x360 (2px per degree => 64,800 cells).
- localStorage keys: nsw_tile, nsw_radius (1–7), nsw_remote (boolean).
- One listing = one JSON file in shards/{tile}/{jobs|resumes}/; lazy-delete >90d on read.
- Carousel = simple offset pagination. Ad slots = static grey left/right columns.
================================================================================
-->
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>No Signup Work</title>
<style>
:root{
  --green:#4CAF50; --green-d:#43a047;
  --orange:#ff9800; --orange-d:#fb8c00;
  --blue:#008CBA;   --blue-d:#0277a8;
  --red:#f44336;    --red-d:#e53935;
  --ink:#222; --muted:#667; --line:#d7dce3;
  --shadow:0 12px 30px rgba(20,30,50,.14);
  --radius:16px;
  /* Back buttons are 16.5% wide; content stays in this centred band to clear them. */
  --col:64%; --col-max:820px;
}
*{box-sizing:border-box}
body,html{margin:0;height:100%;width:100%;font-family:Arial,Helvetica,sans-serif;color:var(--ink);overflow:hidden}

/* hidden checkboxes — the checkbox hack */
.button-toggle{display:none}

/* home screen: four quadrants */
.btn-container{position:absolute;height:100%;width:100%}
.btn-label{position:absolute;width:49.5%;height:49%;border:1px solid #0003;color:#fff;
  font-size:3vw;letter-spacing:.5px;display:flex;justify-content:center;align-items:center;
  cursor:pointer;transition:filter .15s}
.btn-label:hover{filter:brightness(1.06)}
.BrowseJobs {top:0;left:0;background:var(--green)}
.PostJobs   {top:0;right:0;background:var(--orange)}
.DropResumes{bottom:0;left:0;background:var(--blue)}
.GetResumes {bottom:0;right:0;background:var(--red)}

/* panels */
.container{position:fixed;inset:0;display:none;z-index:10;background:#fff;overflow:hidden;text-align:center}
#toggleBrowseJobs:checked  ~ #containerBrowseJobs,
#togglePostJobs:checked    ~ #containerPostJobs,
#toggleDropResumes:checked ~ #containerDropResumes,
#toggleGetResumes:checked  ~ #containerGetResumes{display:block}
#toggleBrowseJobs:checked  ~ .btn-container .BrowseJobs,
#togglePostJobs:checked    ~ .btn-container .PostJobs,
#toggleDropResumes:checked ~ .btn-container .DropResumes,
#toggleGetResumes:checked  ~ .btn-container .GetResumes{display:none}

/* corner Back buttons — pinned, never moved (see header) */
.close-button{position:absolute;width:16.5%;height:16.33%;border:1px solid #0003;color:#fff;
  font-size:1.4vw;font-weight:bold;display:flex;justify-content:center;align-items:center;
  cursor:pointer;z-index:30;transition:filter .15s}
.close-button:hover{filter:brightness(1.06)}
.close-button.BrowseJobs {top:0;left:0;background:var(--green-d)}
.close-button.PostJobs   {top:0;right:0;background:var(--orange-d)}
.close-button.DropResumes{bottom:0;left:0;background:var(--blue-d)}
.close-button.GetResumes {bottom:0;right:0;background:var(--red-d)}

/* shared top strip with the Map button (identical in all four panels) */
.top-menu{position:absolute;top:0;left:0;width:100%;height:7vh;display:flex;
  justify-content:center;align-items:center;z-index:15}
.top-map-label{display:inline-flex;align-items:center;gap:.4em;padding:.5em 1.8em;
  font-size:16px;font-weight:bold;color:var(--ink);background:#fff;border:1px solid var(--line);
  border-radius:999px;box-shadow:0 2px 8px rgba(0,0,0,.08);cursor:pointer;
  transition:transform .06s,box-shadow .15s}
.top-map-label:hover{box-shadow:0 4px 14px rgba(0,0,0,.16)}
.top-map-label:active{transform:translateY(1px)}

/* carousel (Browse Jobs / Browse Resumes) */
.carousel{position:absolute;top:7vh;bottom:13vh;left:0;right:0;
  display:flex;align-items:center;justify-content:center;background:#eef1f4}
.job-card,.resume-card{width:var(--col);max-width:var(--col-max);height:92%;
  background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);
  padding:18px;display:flex;flex-direction:column;gap:12px;overflow:hidden}
.JobSectionTop,.JobSectionMedium,.JobSectionBottom{border-radius:12px;display:flex;
  flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:14px}
.JobSectionTop{background:rgba(244,67,54,.14);border:1px solid rgba(244,67,54,.25)}
.JobSectionTop h3{margin:0;font-size:clamp(20px,2.4vw,30px)}
.JobSectionTop small{font-size:13px;color:var(--muted);margin-top:4px}
.JobSectionMedium{flex:1;background:rgba(33,150,243,.12);border:1px solid rgba(33,150,243,.22);
  font-size:clamp(15px,1.4vw,18px);line-height:1.5;color:#334;overflow-y:auto}
.JobSectionBottom{background:rgba(76,175,80,.16);border:1px solid rgba(76,175,80,.28);gap:6px;
  font-size:clamp(15px,1.5vw,19px);font-weight:bold}

/* search row — narrowed to the safe column so it clears the bottom corner buttons */
.search-container{position:absolute;bottom:2.5vh;left:50%;transform:translateX(-50%);
  width:var(--col);max-width:var(--col-max);display:flex;gap:10px}
.search-box{flex:1;height:8vh;min-height:48px;padding:12px 16px;font-size:16px;
  border:1px solid var(--line);border-radius:12px;background:#fff;resize:none;transition:border-color .15s}
.search-box:focus{outline:none;border-color:var(--blue)}
.filter-button{width:28%;min-width:96px;font-size:16px;font-weight:bold;color:#fff;
  background:var(--ink);border:none;border-radius:12px;cursor:pointer;transition:filter .15s}
.filter-button:hover{filter:brightness(1.18)}

/* nudging arrows — DO NOT ALTER THE ANIMATION (user-loved) */
.LeftArrow,.RightArrow{position:absolute;top:10vh;width:3%;height:80vh;z-index:20;
  display:flex;align-items:center;justify-content:center;font-size:2em;color:#fff;
  background:#8a929c;cursor:pointer;border-radius:8px}
.LeftArrow {left:0;animation:nudge-left  18s infinite}
.RightArrow{right:0;animation:nudge-right 18s infinite}
@keyframes nudge-left{
  0%,5%,20%,100%{transform:translateX(0)}
  2.5%{transform:translateX(-50%)}
  10%{transform:translateX(50%)}
  12.5%{transform:translateX(-50%)}
}
@keyframes nudge-right{
  0%,5%,20%,100%{transform:translateX(0)}
  2.5%{transform:translateX(50%)}
  10%{transform:translateX(-50%)}
  12.5%{transform:translateX(50%)}
}

/* forms (Post a Job / Leave a Resume) */
.LeftSection,.RightSection{position:absolute;top:7vh;bottom:5vh;width:10%;background:#f4f5f7}
.LeftSection{left:0}.RightSection{right:0}
.CenterSection{position:absolute;top:7vh;bottom:5vh;left:10%;width:80%;background:#fff;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.2vh;
  padding:2vh 0;overflow-y:auto}
/* controls sit at 80% of the 80%-wide centre => ~18%–82% of the screen => clears corners */
.CenterSection>select,.CenterSection>input,.CenterSection>textarea,.row{width:80%}
.CenterSection select,.CenterSection input[type=text],.CenterSection input[type=email],.CenterSection textarea{
  font-size:18px;padding:14px;text-align:center;border:1px solid var(--line);
  border-radius:12px;background:#fff;transition:border-color .15s}
.CenterSection select:focus,.CenterSection input:focus,.CenterSection textarea:focus{outline:none;border-color:var(--blue)}
.row{display:flex;gap:10px}
.row>*{flex:1}
.JobDetailsTextarea{height:24vh;min-height:120px;resize:none}
.ResumeTextarea{height:16vh;min-height:80px;resize:none}
/* loud permanence warning shown in both post forms */
.PermWarning{width:80%;background:rgba(244,67,54,.12);border:2px solid var(--red);
  border-radius:12px;color:#b71c1c;font-size:14px;font-weight:bold;line-height:1.45;
  padding:10px 14px;text-align:center}
.PermWarning b{color:#8e0000}
.InFormMapBtn{display:flex;align-items:center;justify-content:center;gap:.3em;font-size:16px;
  font-weight:bold;color:#fff;background:var(--blue);border:none;border-radius:12px;cursor:pointer;transition:filter .15s}
.InFormMapBtn:hover{filter:brightness(1.08)}
.ButtonContainer{display:flex;gap:10px}
.SubmitButton,.ResetButton{font-size:18px;font-weight:bold;color:#fff;border:none;border-radius:12px;
  padding:14px;cursor:pointer;transition:filter .15s,transform .05s}
.SubmitButton:active,.ResetButton:active{transform:translateY(1px)}
.SubmitButton{flex:3;background:var(--green)}.SubmitButton:hover{filter:brightness(1.08)}
.ResetButton{flex:1;background:var(--red)}.ResetButton:hover{filter:brightness(1.08)}
.BottomBanner{position:absolute;bottom:0;width:100%;height:5vh;background:var(--ink);color:#fff;
  display:flex;justify-content:center;align-items:center;font-size:14px;letter-spacing:.3px}

/* resume card preview */
.resume-preview{flex:1;width:100%;border-radius:12px;border:1px solid var(--line);
  background:rgba(33,150,243,.08);display:flex;align-items:center;justify-content:center;color:var(--muted);overflow:hidden}
.resume-details{background:rgba(76,175,80,.16);border:1px solid rgba(76,175,80,.28);
  border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:4px;font-size:15px}
.detail-line{font-weight:bold}

/* carousel empty / loading states */
.carousel-msg{color:var(--muted);font-size:18px;padding:40px;text-align:center}
.carousel-pager{position:absolute;bottom:14vh;left:50%;transform:translateX(-50%);
  font-size:13px;color:var(--muted);background:#fff;border-radius:999px;padding:3px 14px;
  border:1px solid var(--line);pointer-events:none}

/* toast notifications */
#nsw-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
  background:#222;color:#fff;border-radius:12px;padding:12px 24px;font-size:15px;
  z-index:9999;opacity:0;transition:opacity .25s;pointer-events:none}
#nsw-toast.show{opacity:1}

/* unified map overlay */
.map-overlay{position:fixed;inset:0;z-index:2000;background:rgba(10,16,28,.9);
  display:none;justify-content:center;align-items:center;flex-direction:column;padding:16px}
#toggleMapMenu:checked ~ .map-overlay{display:flex}
.map-inner{background:#16202e;border:1px solid #2a3a50;border-radius:20px;padding:18px;
  max-width:96vw;max-height:96vh;overflow:auto;display:flex;flex-direction:column;align-items:center;gap:12px;
  box-shadow:0 20px 60px rgba(0,0,0,.6)}
#worldMap{border:2px solid #ffcc00;border-radius:6px;cursor:crosshair;max-width:100%;height:auto;display:block}
.map-controls{display:flex;flex-wrap:wrap;gap:14px 24px;justify-content:center;align-items:center;
  background:#1f2c3e;border-radius:999px;padding:10px 22px;color:#fff;font-size:16px}
.map-controls label{display:flex;align-items:center;gap:6px;cursor:pointer}
.map-controls input[type=range]{width:160px;accent-color:#ffcc00}
.map-controls input[type=checkbox]{accent-color:#ffcc00}
.selected-info{font-size:15px;color:#ffcc00;background:#0008;border-radius:999px;padding:5px 16px}
.selected-info strong{font-size:17px}
.close-map-label{background:var(--red);color:#fff;border:none;border-radius:999px;
  padding:11px 38px;font-size:18px;font-weight:bold;cursor:pointer;transition:filter .15s}
.close-map-label:hover{filter:brightness(1.08)}

/* Host-a-Mirror: centered home-screen button (sits above quadrants, below panels) */
.mirror-fab{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:5;
  background:#16202e;color:#fff;border:2px solid #ffcc00;border-radius:999px;
  padding:10px 22px;font-size:1.4vw;font-weight:bold;cursor:pointer;white-space:nowrap;
  box-shadow:0 6px 20px rgba(0,0,0,.35);transition:transform .06s,box-shadow .15s}
.mirror-fab:hover{box-shadow:0 8px 26px rgba(0,0,0,.5)}
.mirror-fab:active{transform:translate(-50%,-50%) translateY(1px)}
/* Host-a-Mirror overlay (same checkbox-hack as the map) */
.mirror-overlay{position:fixed;inset:0;z-index:2000;background:rgba(10,16,28,.92);
  display:none;justify-content:center;align-items:center;padding:16px}
#toggleMirror:checked ~ .mirror-overlay{display:flex}
.mirror-inner{background:#16202e;border:1px solid #2a3a50;border-radius:20px;padding:24px;
  max-width:460px;width:92vw;display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;
  color:#dfe7f0;box-shadow:0 20px 60px rgba(0,0,0,.6)}
.mirror-glyph{font-size:34px;color:#ffcc00;line-height:1}
.mirror-title{margin:0;font-size:24px;color:#fff}
.mirror-desc{margin:0;font-size:15px;line-height:1.5;color:#aebed0}
.mirror-dl{color:#ffcc00;font-weight:bold;text-decoration:none;font-size:15px;
  border:1px solid #ffcc00;border-radius:999px;padding:8px 18px}
.mirror-dl:hover{background:#ffcc0022}
.mirror-input{width:100%;font-size:15px;padding:12px 14px;border:1px solid #2a3a50;border-radius:12px;
  background:#0e1620;color:#fff;text-align:center}
.mirror-input:focus{outline:none;border-color:#ffcc00}
.mirror-submit{width:100%;font-size:16px;font-weight:bold;color:#16202e;background:#ffcc00;border:none;
  border-radius:12px;padding:12px;cursor:pointer;transition:filter .15s}
.mirror-submit:hover{filter:brightness(1.06)}
.mirror-status{margin:0;font-size:14px;min-height:1.2em;color:#7fe0a0}
.mirror-foot{margin:0;font-size:12px;color:#7286a0;line-height:1.4}
</style>
</head>
<body>

<!-- toast -->
<div id="nsw-toast"></div>

<!-- hidden toggles (must precede the elements they reveal) -->
<input type="checkbox" id="toggleBrowseJobs"  class="button-toggle">
<input type="checkbox" id="togglePostJobs"    class="button-toggle">
<input type="checkbox" id="toggleDropResumes" class="button-toggle">
<input type="checkbox" id="toggleGetResumes"  class="button-toggle">
<input type="checkbox" id="toggleMapMenu"     class="button-toggle">
<input type="checkbox" id="toggleMirror"      class="button-toggle">

<!-- home screen -->
<div class="btn-container">
  <label for="toggleBrowseJobs"  class="btn-label BrowseJobs"><span>Browse Jobs</span></label>
  <label for="togglePostJobs"    class="btn-label PostJobs"><span>Post a Job</span></label>
  <label for="toggleDropResumes" class="btn-label DropResumes"><span>Leave a Resume</span></label>
  <label for="toggleGetResumes"  class="btn-label GetResumes"><span>Browse Resumes (CV's)</span></label>
</div>
<!-- centered entry point (sits over the quadrant cross; hidden behind any open panel) -->
<label for="toggleMirror" class="mirror-fab" title="Host a mirror of this site">＋ Host a Mirror</label>

<!-- PANEL 1 — Browse Jobs -->
<div id="containerBrowseJobs" class="container">
  <label for="toggleBrowseJobs" class="close-button BrowseJobs"><span>Back</span></label>
  <div class="top-menu"><label for="toggleMapMenu" class="top-map-label">🗺️ Map</label></div>
  <div class="LeftArrow" id="jobsPrevBtn">&lt;</div>
  <div class="carousel" id="jobsCarousel">
    <div class="carousel-msg">Loading jobs…</div>
  </div>
  <div class="carousel-pager" id="jobsPager"></div>
  <div class="RightArrow" id="jobsNextBtn">&gt;</div>
  <div class="search-container">
    <textarea class="search-box" id="jobsKeyword" placeholder="Search jobs..."></textarea>
    <button class="filter-button" id="jobsFilterBtn">Filter</button>
  </div>
</div>

<!-- PANEL 2 — Post a Job -->
<div id="containerPostJobs" class="container">
  <label for="togglePostJobs" class="close-button PostJobs"><span>Back</span></label>
  <div class="top-menu"><label for="toggleMapMenu" class="top-map-label">🗺️ Map</label></div>
  <div class="LeftSection"><!-- AD SLOT 1 --></div>
  <div class="CenterSection" id="postJobForm">
    <select id="pj-category">
      <option value="">Job Field / Category</option>
      <option value="business_finance">Business, Finance</option>
      <option value="administration">Administration</option>
      <option value="marketing_sales">Marketing, Advertising, Sales</option>
      <option value="engineering_technology">Engineering and Technology</option>
      <option value="healthcare">Mental and Physical Healthcare</option>
      <option value="education_training">Education and Training</option>
      <option value="construction_trades">Construction and Skilled Trades</option>
      <option value="arts">Art, Music, Photography, Acting</option>
      <option value="logistics_transportation">Logistics and Transportation</option>
      <option value="legal_government">Legal and Government</option>
    </select>
    <div class="row">
      <select id="pj-pay-type">
        <option value="">Pay Type — required</option>
        <option value="remote_fixed">Remote — Fixed Bounty</option>
        <option value="remote_hourly">Remote — Hourly</option>
        <option value="inperson_fixed">In Person — Fixed</option>
        <option value="inperson_hourly">In Person — Hourly</option>
      </select>
      <input type="text" id="pj-pay-amount" placeholder="Pay — required (e.g. $25/hr, $500 flat)">
    </div>
    <input type="text" id="pj-title" placeholder="Job Title">
    <input type="text" id="pj-keywords" placeholder="Keyword, Keyword, Keyword...">
    <div class="row">
      <input type="email" id="pj-email" placeholder="E-mail">
      <input type="text"  id="pj-phone" placeholder="Phone Number">
      <label for="toggleMapMenu" class="InFormMapBtn">📍 Set Location</label>
    </div>
    <textarea class="JobDetailsTextarea" id="pj-details" placeholder="Job Details"></textarea>
    <div class="PermWarning">⚠️ Once posted you <b>can’t edit or remove</b> this job yourself. It stays live and is <b>automatically deleted 90 days</b> after posting.</div>
    <div class="row ButtonContainer">
      <button class="ResetButton" id="pj-reset">Reset</button>
      <button class="SubmitButton" id="pj-submit">Submit</button>
    </div>
  </div>
  <div class="RightSection"><!-- AD SLOT 2 --></div>
  <div class="BottomBanner">No Signup Work © 2024</div>
</div>

<!-- PANEL 3 — Leave a Resume -->
<div id="containerDropResumes" class="container">
  <label for="toggleDropResumes" class="close-button DropResumes"><span>Back</span></label>
  <div class="top-menu"><label for="toggleMapMenu" class="top-map-label">🗺️ Map</label></div>
  <div class="LeftSection"><!-- AD SLOT 3 --></div>
  <div class="CenterSection" id="postResumeForm">
    <input type="text"  id="pr-name"       placeholder="Full Name">
    <input type="email" id="pr-email"      placeholder="Email">
    <input type="text"  id="pr-phone"      placeholder="Phone Number">
    <input type="text"  id="pr-min-salary" placeholder="Minimum Salary Per Hour">
    <input type="text"  id="pr-keywords"   placeholder="Keywords (e.g., Skill1, Skill2, Skill3)">
    <textarea class="ResumeTextarea" id="pr-resume-text" placeholder="Your résumé — bio, experience, skills (plain text)"></textarea>
    <div class="row">
      <label for="toggleMapMenu" class="InFormMapBtn">📍 Set Location</label>
    </div>
    <div class="PermWarning">⚠️ Once submitted you <b>can’t edit or remove</b> your résumé yourself. It stays live and is <b>automatically deleted 90 days</b> after posting.</div>
    <div class="row ButtonContainer">
      <button class="ResetButton" id="pr-reset">Reset</button>
      <button class="SubmitButton" id="pr-submit">Submit</button>
    </div>
  </div>
  <div class="RightSection"><!-- AD SLOT 4 --></div>
  <div class="BottomBanner">No Signup Work © 2024</div>
</div>

<!-- PANEL 4 — Browse Resumes -->
<div id="containerGetResumes" class="container">
  <label for="toggleGetResumes" class="close-button GetResumes"><span>Back</span></label>
  <div class="top-menu"><label for="toggleMapMenu" class="top-map-label">🗺️ Map</label></div>
  <div class="LeftArrow" id="resumesPrevBtn">&lt;</div>
  <div class="carousel" id="resumesCarousel">
    <div class="carousel-msg">Loading resumes…</div>
  </div>
  <div class="carousel-pager" id="resumesPager"></div>
  <div class="RightArrow" id="resumesNextBtn">&gt;</div>
  <div class="search-container">
    <textarea class="search-box" id="resumesKeyword" placeholder="Search resumes..."></textarea>
    <button class="filter-button" id="resumesFilterBtn">Filter</button>
  </div>
</div>

<!-- UNIFIED MAP OVERLAY (checkbox-hack; sits above everything) -->
<div class="map-overlay">
  <div class="map-inner">
    <canvas id="worldMap" width="720" height="360"></canvas>
    <div class="map-controls">
      <span>📍 Radius</span>
      <input type="range" id="radiusSlider" min="1" max="7" value="1" step="1">
      <span id="radiusValue">1</span>
      <label><input type="checkbox" id="remoteToggle"> 🌐 Include remote</label>
    </div>
    <div class="selected-info">Selected tile: <strong id="selectedTileDisplay">—</strong></div>
    <label for="toggleMapMenu" class="close-map-label">✓ Save &amp; Close</label>
  </div>
</div>

<!-- HOST-A-MIRROR OVERLAY (checkbox-hack). Mirror URLs are staged client-side only
     (localStorage, FIFO cap 64) for a future gossip pass — same as nosignup.chat. -->
<div class="mirror-overlay">
  <div class="mirror-inner">
    <span class="mirror-glyph">＋</span>
    <h2 class="mirror-title">Host a Mirror</h2>
    <p class="mirror-desc">Drop this one file into any PHP-enabled hosting, then give us its URL or IP — it joins the network of mirrors. The more mirrors, the harder this is to take down.</p>
    <a class="mirror-dl" href="index.php?src=1">↓ Download index.php</a>
    <input id="mirrorInput" class="mirror-input" type="text" autocomplete="off" placeholder="https://my-mirror.example/index.php  or  1.2.3.4">
    <button id="mirrorSubmit" class="mirror-submit" type="button">Submit mirror</button>
    <p id="mirrorStatus" class="mirror-status"></p>
    <p class="mirror-foot">Saved locally for the next gossip pass. A mirror is only trusted if its file is byte-identical to this one.</p>
    <label for="toggleMirror" class="close-map-label">Close</label>
  </div>
</div>

<script>
(function () {
  'use strict';

  /* ── helpers ── */
  function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function toast(msg, ms) {
    var el = document.getElementById('nsw-toast');
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(function(){ el.classList.remove('show'); }, ms || 3000);
  }
  function getTile()   { return localStorage.getItem('nsw_tile')   || '40_-74'; }
  function getRadius() { return parseInt(localStorage.getItem('nsw_radius') || '1', 10); }
  function getRemote() { return localStorage.getItem('nsw_remote') === 'true'; }

  /* ── map / localStorage ── */
  var TILE = getTile(), RADIUS = getRadius(), REMOTE = getRemote();
  var canvas = document.getElementById('worldMap'), ctx = canvas.getContext('2d');
  var slider = document.getElementById('radiusSlider'),
      rVal   = document.getElementById('radiusValue'),
      remote = document.getElementById('remoteToggle'),
      tileOut= document.getElementById('selectedTileDisplay'),
      toggle = document.getElementById('toggleMapMenu');

  /* Blocky equirectangular world map (Natural Earth 110m land, CC0), rounded to
     whole degrees so it lines up with the 1-degree tile grid. Inline base64 image
     — no external request, keeps the single-file drop-in promise. */
  var mapImg = new Image();
  mapImg.onload = function () { draw(); };
  mapImg.src = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzNjAgMTgwIiBwcmVzZXJ2ZUFzcGVjdFJhdGlvPSJub25lIj48cmVjdCB3aWR0aD0iMzYwIiBoZWlnaHQ9IjE4MCIgZmlsbD0iIzBlMmE0NyIvPjxwYXRoIGQ9Ik0xMjAgMTcxTDExNCAxNzFMMTE0IDE3MFpNMTkgMTcwTDE4IDE2OUwxNiAxNjlMMTcgMTY4TDE5IDE2OEwyMCAxNjlaTTEzNiAxNjhMMTM3IDE2OUwxMzcgMTcwTDEzNSAxNzBMMTMzIDE3MUwxMjYgMTcxTDEyNiAxNzBMMTI5IDE3MEwxMzEgMTY4Wk02MCAxNjRMNjEgMTYzTDYxIDE2NEw1NyAxNjRMNTggMTYzWk01NiAxNjRMNTQgMTY0TDUzIDE2M1pNODMgMTYyTDgzIDE2Mkw4MSAxNjJMNzkgMTYzTDc4IDE2MlpNMTExIDE2MkwxMTAgMTYyTDEwOSAxNjNMMTA4IDE2MkwxMDUgMTYyTDEwNiAxNjFMMTA4IDE2MUwxMDggMTYwTDEwOSAxNTlMMTEwIDE1OUwxMTEgMTYwTDExMSAxNjFaTTExOSAxNTRMMTE5IDE1NUwxMTcgMTU1TDExNyAxNTZMMTE3IDE1NkwxMTYgMTU3TDExNSAxNTdMMTE0IDE1OEwxMTUgMTU4TDExNSAxNTlMMTE3IDE1OUwxMTcgMTYwTDExOCAxNjBMMTE4IDE2MUwxMTkgMTYyTDExOSAxNjRMMTE4IDE2NEwxMTcgMTY1TDExNiAxNjVMMTE0IDE2NkwxMTAgMTY2TDEwOSAxNjdMMTA1IDE2N0wxMDYgMTY4TDEwMiAxNjhMMTAyIDE2OUwxMDMgMTcwTDEwNyAxNzBMMTA5IDE3MUwxMTQgMTcxTDExNyAxNzJMMTIwIDE3MkwxMjEgMTczTDEyNSAxNzNMMTI2IDE3MkwxMzggMTcyTDEzOSAxNzFMMTUwIDE3MUwxNTEgMTcwTDE1MCAxNzBMMTUwIDE2OUwxNDQgMTY5TDE0NCAxNjhMMTQ4IDE2OEwxNDkgMTY3TDE1MSAxNjdMMTUyIDE2NkwxNjAgMTY2TDE2MSAxNjVMMTYzIDE2NUwxNjQgMTY0TDE2NCAxNjRMMTY0IDE2M0wxNjcgMTYzTDE2OCAxNjJMMTY5IDE2MkwxNzAgMTYxTDE3MSAxNjFMMTcxIDE2MkwxNzMgMTYyTDE3MyAxNjFMMTc5IDE2MUwxODAgMTYyTDE4MSAxNjFMMTg1IDE2MUwxODYgMTYwTDE5MCAxNjBMMTkxIDE2MUwxOTIgMTYxTDE5MiAxNjBMMjAyIDE2MEwyMDMgMTYxTDIwNCAxNjFMMjA1IDE2MEwyMTIgMTYwTDIxMyAxNTlMMjE3IDE1OUwyMTggMTYwTDIyMCAxNjBMMjIwIDE1OUwyMjIgMTU5TDIyMyAxNThMMjI3IDE1OEwyMjggMTU3TDIzMSAxNTdMMjMyIDE1NkwyMzcgMTU2TDIzNyAxNTdMMjQwIDE1N0wyNDEgMTU4TDI0MyAxNThMMjQ0IDE1N0wyNDUgMTU4TDI0OSAxNThMMjUwIDE1OUwyNTAgMTYwTDI0OCAxNjBMMjQ4IDE2MUwyNDggMTYxTDI0OCAxNjJMMjUyIDE2MkwyNTIgMTYxTDI1MyAxNjFMMjUzIDE2MEwyNTcgMTYwTDI1OSAxNThMMjYxIDE1OEwyNjIgMTU3TDI2NyAxNTdMMjY4IDE1NkwyNjkgMTU3TDI4MSAxNTdMMjgyIDE1NkwyODUgMTU2TDI4NiAxNTdMMjkwIDE1N0wyOTEgMTU2TDI5NSAxNTZMMjk2IDE1N0wzMDIgMTU3TDMwMyAxNTZMMzA0IDE1N0wzMTAgMTU3TDMxMSAxNTZMMzE1IDE1NkwzMTUgMTU1TDMxNyAxNTdMMzI2IDE1N0wzMjYgMTU4TDMyOSAxNThMMzMwIDE1OUwzMzggMTU5TDMzOSAxNjBMMzQxIDE2MEwzNDIgMTYxTDM1MSAxNjFMMzUxIDE2MkwzNDkgMTY0TDM0NiAxNjRMMzQ2IDE2NUwzNDQgMTY1TDM0NCAxNjZMMzQzIDE2N0wzNDQgMTY3TDM0NCAxNjhMMzQ3IDE2OEwzNDcgMTY5TDM0MiAxNjlMMzQwIDE3MUwzNDEgMTcxTDM0MiAxNzJMMzQ0IDE3MkwzNDUgMTczTDM0OSAxNzNMMzQ5IDE3NEwzNTggMTc0TDM2MCAxNzVMMzYwIDE4MEwwIDE4MEwwIDE3NUwxIDE3NEw0IDE3NEw2IDE3NUw3IDE3NEwxMSAxNzRMMTMgMTc1TDI5IDE3NUwzMSAxNzZMMzQgMTc1TDMzIDE3NUwzMCAxNzRMMjYgMTc0TDI3IDE3M0wyNyAxNzJMMjUgMTcyTDI1IDE3MUwzMyAxNzFMMzQgMTcwTDMyIDE3MEwzMCAxNjlMMjQgMTY5TDIzIDE2OEwyMiAxNjhMMjIgMTY3TDMyIDE2N0wzNCAxNjZMMzQgMTY1TDM1IDE2NUwzNiAxNjZMMzcgMTY1TDQ0IDE2NUw0NSAxNjRMNTMgMTY0TDU1IDE2NUw1NiAxNjRMNTcgMTY0TDU5IDE2NUw2MCAxNjRMNjcgMTY0TDY4IDE2NUw2OSAxNjRMNzAgMTY1TDc5IDE2NUw3OSAxNjRMNzcgMTY0TDc3IDE2M0w4MiAxNjNMODIgMTY0TDg0IDE2NEw4NSAxNjNMOTUgMTYzTDk2IDE2NEw5OSAxNjRMOTkgMTYzTDEwMCAxNjNMMTAxIDE2NEwxMDIgMTYzTDEwMyAxNjRMMTA2IDE2NEwxMDcgMTYzTDExMiAxNjNMMTEzIDE2MkwxMTIgMTYxTDExMiAxNjBMMTExIDE2MEwxMTMgMTU4TDExMiAxNThMMTEyIDE1N0wxMTMgMTU3TDExNCAxNTZMMTE1IDE1NkwxMTYgMTU1TDExOCAxNTVMMTE5IDE1NEwxMjEgMTU0TDEyMSAxNTNMMTIyIDE1M0wxMjMgMTU0Wk0xMTQgMTQ0TDExNSAxNDVMMTEzIDE0NUwxMTIgMTQ2TDExMSAxNDVMMTA5IDE0NUwxMDggMTQ0TDEwNyAxNDRMMTA1IDE0M0wxMDYgMTQzTDEwOCAxNDRMMTA5IDE0NEwxMTAgMTQzTDExMiAxNDNaTTEyMiAxNDJMMTE5IDE0MkwxMjAgMTQxTDEyMSAxNDJaTTMyOCAxMzFMMzI4IDEzM0wzMjcgMTM0TDMyNiAxMzRMMzI1IDEzM1pNMzU0IDEzMUwzNTQgMTMyTDM1MyAxMzNMMzUzIDEzNEwzNTEgMTM0TDM1MSAxMzZMMzUwIDEzNkwzNDkgMTM3TDM0OCAxMzdMMzQ4IDEzNkwzNDcgMTM2TDM0NyAxMzVMMzQ4IDEzNEwzNTAgMTM0TDM1MiAxMzJMMzUyIDEzMUwzNTMgMTMwWk0zNTUgMTI3TDM1NiAxMjdMMzU2IDEyOEwzNTkgMTI4TDM1OCAxMjlMMzU3IDEyOUwzNTcgMTMxTDM1NiAxMzFMMzU1IDEzMkwzNTUgMTMwTDM1NCAxMzBMMzU0IDEyOUwzNTUgMTI5TDM1NSAxMjdMMzU0IDEyN0wzNTQgMTI2TDM1MyAxMjVMMzUzIDEyNFpNMzQ1IDExMkwzNDUgMTExTDM0NCAxMTBMMzQ1IDExMEwzNDUgMTExTDM0NiAxMTFaTTIzMCAxMDdMMjI5IDEwN0wyMjkgMTEwTDIyOCAxMTJMMjI4IDExNEwyMjcgMTE1TDIyNiAxMTVMMjI1IDExNkwyMjUgMTE1TDIyNCAxMTVMMjI0IDExNEwyMjMgMTEzTDIyMyAxMTFMMjI0IDExMUwyMjQgMTA2TDIyNiAxMDZMMjI3IDEwNUwyMjggMTA1TDIyOCAxMDRMMjI5IDEwM0wyMjkgMTAyTDIzMCAxMDJaTTMyNCAxMDVMMzI1IDEwNEwzMjUgMTA2TDMyNiAxMDdMMzI2IDEwOUwzMjcgMTA5TDMyOCAxMTBMMzI5IDExMEwzMjkgMTExTDMzMCAxMTJMMzMwIDExM0wzMzEgMTEyTDMzMSAxMTNMMzMzIDExNUwzMzMgMTE3TDMzNCAxMThMMzM0IDExOUwzMzMgMTE5TDMzMyAxMjJMMzMxIDEyNEwzMzEgMTI1TDMzMCAxMjZMMzMwIDEyN0wzMjkgMTI4TDMyNyAxMjhMMzI3IDEyOUwzMjUgMTI5TDMyNSAxMjhMMzI0IDEyOEwzMjQgMTI5TDMyMyAxMjlMMzIyIDEyOEwzMjEgMTI4TDMyMCAxMjdMMzIwIDEyNkwzMTggMTI2TDMxOCAxMjVMMzE3IDEyNUwzMTggMTI0TDMxOCAxMjNMMzE3IDEyNEwzMTYgMTI0TDMxNiAxMjVMMzE1IDEyNEwzMTUgMTIzTDMxNCAxMjNMMzEzIDEyMkwzMTIgMTIyTDMxMSAxMjFMMzEwIDEyMkwzMDYgMTIyTDMwNSAxMjNMMzA0IDEyM0wzMDQgMTI0TDMwMCAxMjRMMjk5IDEyNUwyOTkgMTI1TDI5NyAxMjVMMjk2IDEyNEwyOTUgMTI0TDI5NiAxMjNMMjk2IDEyMkwyOTUgMTIxTDI5NSAxMTlMMjk0IDExOEwyOTQgMTE3TDI5MyAxMTdMMjkzIDExNkwyOTMgMTE2TDI5NCAxMTZMMjk0IDExNUwyOTMgMTE0TDI5NCAxMTRMMjk0IDExM0wyOTUgMTEyTDI5NSAxMTFMMjk3IDExMUwyOTggMTEwTDMwMSAxMTBMMzAxIDEwOUwzMDIgMTA5TDMwMiAxMDdMMzAzIDEwNkwzMDMgMTA3TDMwNCAxMDdMMzA0IDEwNkwzMDUgMTA1TDMwNiAxMDVMMzA2IDEwNEwzMDggMTA0TDMwOCAxMDVMMzEwIDEwNUwzMDkgMTA0TDMxMCAxMDRMMzEwIDEwM0wzMTEgMTAzTDMxMSAxMDJMMzEzIDEwMkwzMTIgMTAxTDMxMyAxMDFMMzE0IDEwMkwzMTcgMTAyTDMxNyAxMDNMMzE2IDEwM0wzMTYgMTA0TDMxNSAxMDVMMzE2IDEwNUwzMTYgMTA2TDMxOCAxMDZMMzE4IDEwN0wzMTkgMTA3TDMyMCAxMDhMMzIxIDEwN0wzMjEgMTA2TDMyMiAxMDVMMzIyIDEwMUwzMjMgMTAxTDMyMyAxMDJMMzI0IDEwM1pNMzA0IDEwMEwzMDQgOTlMMzA1IDk5TDMwNiA5OEwzMDcgOThMMzA3IDk5TDMwNSA5OVpNMjkxIDk3TDI5MSA5NkwyOTMgOTdMMjkzIDk4TDI5NiA5OEwyOTUgOTlMMjkzIDk4TDI4OCA5OEwyODYgOTdMMjg1IDk3TDI4NiA5NkwyODggOTZaTTMzMSA5NkwzMjggOTZMMzI4IDk1TDMyOSA5NkwzMzAgOTZMMzMwIDk2TDMzMSA5NUwzMzIgOTVaTTMxNCA5M0wzMTUgOTNMMzE2IDkyTDMyMCA5MkwzMjEgOTNMMzIzIDkzTDMyNSA5NEwzMjYgOTVMMzI4IDk2TDMyOCA5N0wzMjcgOTdMMzI5IDk5TDMyOSAxMDBMMzMxIDEwMEwzMzEgMTAxTDMzMCAxMDFMMzMwIDEwMEwzMjggMTAwTDMyNiA5OEwzMjMgOThMMzIzIDk5TDMyMSA5OUwzMjAgOThMMzE4IDk4TDMxOSA5N0wzMTggOTZMMzE4IDk1TDMxNiA5NUwzMTUgOTRMMzEzIDk0TDMxMyA5M0wzMTIgOTNMMzEzIDkyTDMxMiA5MkwzMTEgOTFMMzEyIDkxTDMxMiA5MFpNMzA0IDkwTDMwMCA5MEwzMDAgOTFMMzAzIDkxTDMwMiA5MkwzMDIgOTRMMzAzIDk1TDMwMyA5NkwzMDIgOTVMMzAyIDk1TDMwMSA5NUwzMDIgOTRMMzAxIDk0TDMwMSA5M0wzMDAgOTNMMzAwIDk2TDI5OSA5NUwzMDAgOTRMMjk5IDkzTDI5OSA5MUwzMDAgOTBMMzAwIDg5TDMwNCA4OUwzMDUgODhaTTMwOSA5MEwzMDggOTBMMzA4IDkwTDMwNyA4OUwzMDggODhMMzA5IDg4Wk0yODUgOTZMMjgzIDk0TDI4MiA5NEwyODEgOTNMMjgxIDkyTDI3OSA5MEwyNzkgODhMMjc4IDg4TDI3NSA4NUwyNzcgODVMMjc4IDg2TDI3OSA4NkwyODEgODhMMjgyIDg4TDI4MiA4OUwyODMgODlMMjg0IDkwTDI4MyA5MUwyODQgOTFMMjg1IDkyTDI4NiA5MlpNMjk5IDg5TDI5OCA4OUwyOTcgOTBMMjk4IDkxTDI5NyA5MUwyOTcgOTJMMjk2IDk0TDI5NSA5NEwyOTQgOTNMMjkwIDkzTDI5MCA5MUwyODkgOTBMMjg5IDg5TDI5MCA4OEwyOTEgODhMMjkxIDg3TDI5MyA4N0wyOTQgODZMMjk0IDg1TDI5NSA4NUwyOTcgODNMMjk5IDg1TDI5OCA4NUwyOTkgODZMMjk4IDg2TDI5NyA4N1pNMzA3IDgzTDMwNiA4NEwzMDYgODNMMzA1IDgzTDMwNiA4NEwzMDQgODRMMzA0IDgyTDMwMyA4M0wzMDIgODNMMzAyIDgyTDMwMyA4MkwzMDMgODFMMzA0IDgyTDMwNSA4MUwzMDUgODBMMzA2IDgxWk0yNjAgODRMMjYwIDgwTDI2MiA4MkwyNjIgODRaTTI5NyA4MkwyOTggODFMMjk4IDgwTDI5OSA4MEwyOTkgODBaTTMwMyA3M0wzMDIgNzRMMzAyIDc2TDMwNCA3NkwzMDQgNzdMMzAzIDc3TDMwMyA3N0wzMDIgNzZMMzAxIDc2TDMwMSA3NkwzMDAgNzVMMzAwIDcyWk0xMTAgNzBMMTEwIDcxTDExMiA3MUwxMTEgNzJMMTA2IDcyTDEwNiA3MUwxMDcgNzFMMTA3IDcyTDEwOCA3MUwxMDcgNzFaTTEwMSA2OEwxMDIgNjdMMTAyIDY4TDEwMyA2OEwxMDMgNjlMMTA1IDY5TDEwNiA3MEwxMDMgNzBMMTAzIDY5TDEwMiA2OUwxMDEgNjhMOTggNjhMOTggNjdMOTcgNjdMOTcgNjhMOTYgNjhMOTYgNjdaTTMwMSA2OEwzMDAgNjdMMzAwIDY2TDMwMSA2NUwzMDIgNjVMMzAyIDY2Wk0xOTUgNTNMMTk0IDUzTDE5MiA1MlpNMzIxIDU0TDMyMCA1NUwzMTcgNTVMMzE2IDU3TDMxNSA1NkwzMTUgNTVMMzEzIDU2TDMxMSA1NkwzMTIgNTdMMzExIDU5TDMxMCA1OUwzMTAgNTdMMzA5IDU3TDMxMCA1NkwzMTEgNTZMMzEyIDU1TDMxMyA1NUwzMTUgNTRMMzE2IDU0TDMxNyA1M0wzMTkgNTJMMzIwIDUxTDMyMCA0OUwzMjEgNDlMMzIyIDUwTDMyMiA1MUwzMjEgNTJaTTMyNSA0NkwzMjYgNDdMMzI0IDQ3TDMyMyA0OEwzMjIgNDdMMzIxIDQ4TDMyMCA0OEwzMjAgNDdMMzIxIDQ3TDMyMiA0NUwzMjIgNDRaTTU2IDQyTDU0IDQxTDUzIDQwTDUyIDQwTDUyIDM5TDUzIDM5TDUzIDQwTDU1IDQwTDU1IDQxWk0xMjMgNDBMMTI0IDQwTDEyNSA0MUwxMjYgNDBMMTI3IDQxTDEyNyA0MUwxMjcgNDNMMTI2IDQzTDEyNiA0MkwxMjUgNDNMMTI1IDQzTDEyNCA0MkwxMjEgNDJMMTIxIDQxTDEyMiA0MUwxMjMgMzlMMTI0IDM4TDEyNSAzOFpNMzI1IDQxTDMyMyA0MUwzMjMgNDJMMzI0IDQzTDMyNCA0NEwzMjMgNDNMMzIyIDQ0TDMyMiAzN0wzMjMgMzZMMzIzIDM2TDMyMyAzOFpNMTcwIDM4TDE3MSAzN0wxNzAgMzZMMTcyIDM1TDE3NCAzNUwxNzQgMzdaTTE3NiAzMkwxNzggMzJMMTc4IDMzTDE3NyAzNEwxNzggMzRMMTgwIDM2TDE4MCAzN0wxODIgMzdMMTgyIDM4TDE4MSAzOEwxODEgMzlMMTc3IDM5TDE3NiA0MEwxNzQgNDBMMTc2IDM5TDE3NyAzOUwxNzUgMzhMMTc2IDM4TDE3NSAzN0wxNzcgMzdMMTc3IDM2TDE3NiAzNUwxNzUgMzVMMTc1IDM0TDE3NCAzNUwxNzQgMzJMMTc1IDMxWk0xMCAyNkwxMCAyN0w4IDI3Wk05NSAyNUw5NyAyNUw5OCAyNkwxMDAgMjZMOTkgMjdMOTcgMjZMOTYgMjZMOTQgMjdMOTQgMjZMOTQgMjZMOTQgMjRaTTE2NiAyNUwxNjUgMjZMMTYyIDI2TDE2MSAyN0wxNjAgMjZMMTU4IDI2TDE1NiAyNUwxNTggMjVMMTU2IDI0TDE2MiAyNEwxNjQgMjNaTTUgMjNMOCAyM0wxMCAyNEw5IDI0TDcgMjVMNyAyNkw2IDI2TDUgMjVMNCAyNUwzIDI0TDIgMjVMMSAyNEwwIDI0TDEgMjVMMCAyNUwwIDIxTDIgMjJaTTgwIDIxTDgxIDIwTDgzIDIwWk04OSAyMkw5MSAyMUw5MiAyMUw5MiAyMkw5MyAyM0w5NCAyMkw5NCAyMEw5NyAyMEw5OSAyMUw5OCAyMkw5OSAyMkw5OSAyM0w5NyAyNEw5NSAyNEw5NCAyM0w5NCAyNEw5MiAyNkw4OSAyNkw4OSAyN0w4OCAyN0w4NSAzMEw4NSAzMUw4NyAzMUw4NyAzMkw4OCAzM0w5MSAzM0w5MiAzNEw5NCAzNEw5NSAzNUw5OCAzNUw5OCAzN0wxMDAgMzlMMTAxIDM4TDEwMSAzNkwxMDAgMzVMMTAyIDM1TDEwMyAzNEwxMDMgMzJMMTAxIDMxTDEwMyAzMEwxMDIgMjlMMTAyIDI4TDEwMyAyN0wxMDQgMjhMMTA4IDI4TDEwOSAyOUwxMTAgMjlMMTEwIDMwTDExMSAzMUwxMTIgMzFMMTEyIDMyTDExNCAzMUwxMTUgMzBMMTE3IDMyTDExOSAzM0wxMTggMzRMMTIwIDM0TDEyMCAzNUwxMjMgMzVMMTIzIDM2TDEyNCAzNkwxMjQgMzhMMTIzIDM5TDEyMSAzOUwxMjAgNDBMMTEzIDQwTDExMSA0MUwxMDkgNDNMMTEwIDQzTDExMSA0MkwxMTMgNDFMMTE2IDQxTDExNSA0MkwxMTUgNDNMMTE2IDQ0TDExOCA0NEwxMTkgNDNMMTIwIDQ0TDExOSA0NUwxMTcgNDVMMTE2IDQ2TDExNCA0NkwxMTYgNDVMMTEzIDQ1TDExMiA0NkwxMTAgNDZMMTA5IDQ3TDEwOSA0OEwxMTAgNDhMMTA5IDQ5TDEwNiA0OUwxMDYgNTBMMTA1IDUxTDEwNSA1MUwxMDUgNTJMMTA0IDUzTDEwNCA1MUwxMDMgNTFMMTA0IDUyTDEwNCA1MkwxMDQgNTVMMTAzIDU1TDEwMiA1NkwxMDEgNTZMMTAxIDU3TDEwMCA1N0w5OSA1OEw5OSA2MkwxMDAgNjNMMTAwIDY1TDk5IDY1TDk5IDY0TDk4IDY0TDk4IDYzTDk3IDYzTDk3IDYxTDk2IDYwTDkxIDYwTDkxIDYxTDg5IDYxTDg4IDYwTDg2IDYwTDg1IDYxTDg0IDYxTDgzIDYyTDgzIDY0TDgyIDY1TDgyIDY4TDgzIDY5TDgzIDcwTDg0IDcxTDg1IDcxTDg2IDcyTDg3IDcxTDg5IDcxTDg5IDcwTDkwIDY5TDkyIDY5TDkzIDY4TDkzIDcwTDkyIDcwTDkzIDcxTDkyIDcxTDkyIDczTDkxIDc0TDk2IDc0TDk2IDc1TDk3IDc1TDk3IDc2TDk2IDc2TDk2IDc3TDk3IDc3TDk3IDc4TDk2IDc4TDk2IDc5TDk4IDgxTDEwMCA4MUwxMDAgODBMMTAxIDgwTDEwMSA4MUwxMDQgODFMMTA0IDgwTDEwNSA3OUwxMDcgNzlMMTA3IDc4TDEwOSA3OEwxMDggNzlMMTA4IDgxTDEwOSA4MUwxMDkgNzlMMTEwIDc5TDExMCA3OUwxMTQgNzlMMTE0IDgwTDExNiA4MEwxMTYgNzlMMTE4IDc5TDExNyA4MEwxMTggODBMMTE5IDgxTDEyMCA4MUwxMjAgODJMMTIxIDgyTDEyMiA4M0wxMjIgODRMMTI2IDg0TDEyNyA4NUwxMjggODVMMTI4IDg2TDEyOSA4NkwxMjkgODhMMTMwIDg4TDEzMCA4OUwxMjkgOTBMMTMxIDkwTDEzMSA5MUwxMzMgOTFMMTM1IDkyTDEzNiA5MkwxMzUgOTNMMTM3IDkyTDEzOSA5M0wxNDAgOTNMMTQxIDk0TDE0MyA5NUwxNDUgOTVMMTQ1IDk5TDE0MiAxMDJMMTQyIDEwM0wxNDEgMTAzTDE0MSAxMDhMMTQwIDEwOEwxNDAgMTEwTDEzOSAxMTFMMTM5IDExMkwxMzggMTEyTDEzOCAxMTNMMTM1IDExM0wxMzUgMTE0TDEzNCAxMTRMMTMyIDExNUwxMzIgMTE2TDEzMSAxMTdMMTMyIDExN0wxMzEgMTE4TDEzMSAxMTlMMTMwIDExOUwxMjkgMTIxTDEyNyAxMjNMMTI3IDEyNEwxMjYgMTI0TDEyNSAxMjVMMTI0IDEyNUwxMjMgMTI0TDEyMiAxMjRMMTIzIDEyNUwxMjMgMTI3TDEyMSAxMjlMMTE4IDEyOUwxMTggMTMxTDExNSAxMzFMMTE1IDEzMkwxMTYgMTMyTDExNyAxMzNMMTE1IDEzM0wxMTUgMTM1TDExMyAxMzVMMTEzIDEzNkwxMTIgMTM2TDExMyAxMzdMMTE0IDEzN0wxMTQgMTM4TDExMiAxNDBMMTExIDE0MEwxMTEgMTQyTDExMSAxNDJMMTEwIDE0M0wxMDkgMTQzTDEwOSAxNDRMMTA3IDE0NEwxMDUgMTQyTDEwNSAxNDBMMTA0IDEzOUwxMDYgMTM3TDEwNCAxMzdMMTA1IDEzNkwxMDYgMTM0TDEwNyAxMzRMMTA3IDEzMkwxMDYgMTMzTDEwNiAxMzBMMTA3IDEyOUwxMDYgMTI4TDEwNiAxMjdMMTA3IDEyN0wxMDcgMTI2TDEwOSAxMjJMMTA4IDEyMUwxMDkgMTIwTDEwOSAxMTZMMTEwIDExNEwxMTAgMTA4TDEwOSAxMDhMMTA5IDEwN0wxMDUgMTA1TDEwNCAxMDVMMTA0IDEwNEwxMDEgOThMMTAwIDk3TDk5IDk3TDk5IDk0TDEwMCA5M0wxMDAgOTNMOTkgOTJMOTkgOTFMMTAwIDkwTDEwMCA4OUwxMDEgODlMMTAxIDg4TDEwMyA4NkwxMDMgODVMMTAyIDg0TDEwMyA4NEwxMDMgODNMMTAyIDgzTDEwMiA4MkwxMDEgODFMMTAwIDgxTDEwMCA4M0w5OSA4M0w5OSA4Mkw5NiA4Mkw5NiA4MUw5NSA4MEw5NCA4MEw5NCA3OUw5MiA3N0w5MSA3N0w5MCA3Nkw4OCA3Nkw4OCA3NUw4NyA3NEw4MiA3NEw4MSA3M0w3OSA3M0w3OCA3Mkw3NiA3Mkw3NiA3MUw3NSA3MUw3NSA3MEw3NCA3MEw3NSA2OUw3NSA2OUw3NCA2OEw3NCA2N0w2OSA2Mkw2OCA2Mkw2OCA2MUw2NyA2MEw2NyA1OUw2NiA1OEw2NSA1OEw2NSA2MEw2NiA2MEw2NiA2MUw2NyA2MUw2NyA2Mkw2OCA2Mkw2OCA2M0w2OSA2NEw2OSA2Nkw3MCA2Nkw3MSA2N0w3MCA2N0w2OSA2Nkw2OCA2Nkw2OCA2NEw2NyA2NEw2NyA2M0w2NiA2M0w2NSA2Mkw2NiA2Mkw2NiA2MUw2NSA2MUw2NCA2MEw2NCA1OUw2MyA1OEw2MyA1N0w2MiA1Nkw2MCA1Nkw1OCA1NEw1NyA1Mkw1NiA1MUw1NiA0OEw1NSA0N0w1NiA0Nkw1NiA0Mkw1NyA0Mkw1NyA0M0w1OCA0M0w1OCA0Mkw1NyA0MUw1NSA0MEw1NCA0MEw1MSAzN0w1MSAzNkw0OSAzNkw0OSAzNUw0OCAzNUw0OCAzNEw0NiAzM0w0NiAzMkw0MyAzMkw0MiAzMUw0MCAzMEwzNCAzMEwzMyAyOUwzMiAyOUwzMiAzMEwzMCAzMEwyOSAzMUwyOCAzMUwyOCAzMEwyOSAyOUwyOCAyOUwyNiAzMUwyNyAzMUwyNiAzMkwyNSAzMkwyNCAzM0wyMyAzM0wyMiAzNEwyMCAzNEwxOSAzNUwxNyAzNUwxNSAzNkwxNSAzNUwxNyAzNUwxOCAzNEwyMCAzNEwyMSAzM0wyMiAzM0wyMiAzMkwyMyAzMUwyMSAzMUwyMSAzMkwyMCAzMUwxOCAzMUwxOCAzMEwxNSAzMEwxNSAyOUwxNCAyOEwxNSAyN0wxNyAyN0wxOCAyNkwxOCAyN0wxOSAyNkwxOCAyNkwxOSAyNUwxOCAyNUwxNyAyNkwxNiAyNUwxNSAyNkwxNCAyNUwxMyAyNUwxMiAyNEwxMyAyNEwxNiAyM0wxNiAyNEwxOCAyNEwxOCAyM0wxNiAyM0wxNiAyMkwxMyAyMkwxNCAyMUwxNyAyMUwxNyAyMEwxOSAyMEwyMSAxOUwyOCAxOUwyOSAyMEwzMCAxOUwzMiAyMEwzOSAyMEw0MSAyMUw0NCAyMUw0NiAyMEw1MyAyMEw1NCAyMUw1NiAyMEw1NiAyMUw1NyAyMEw1OSAyMEw2MCAyMUw2NSAyMUw2NiAyMkw3MCAyMkw3MSAyM0w3MiAyMkw3MSAyMkw3MiAyMUw3NSAyMUw3NiAyMkw4MSAyMkw4MiAyMUw4NCAyMkw4NCAyM0w4NiAyMUw4NSAyMEw4NCAyMEw4NCAxOUw4NSAxOEw4NiAxOFpNNjggMTdMNjkgMThMNzAgMTdMNzEgMTdMNzIgMThMNzIgMTdMNzUgMTdMNzUgMThMNzcgMjBMNzcgMjBMNzggMjFMNjQgMjFMNjMgMjBMNjggMjBMNjYgMTlMNjIgMTlMNjEgMThMNjIgMTdaTTk5IDE3TDk5IDE2TDEwMiAxNlpNOTUgMTdMOTggMTZMOTkgMTdMOTkgMThMMTAxIDE4TDEwMiAxN0wxMDQgMThMMTA2IDE4TDEwNiAxOUwxMDggMThMMTA5IDE5TDExMSAxOUwxMTMgMjFMMTExIDIxTDExNCAyMkwxMTUgMjJMMTE3IDIzTDExOCAyM0wxMTggMjRMMTE2IDI1TDExNSAyNUwxMTMgMjRMMTEyIDI0TDExMyAyNUwxMTQgMjVMMTE1IDI2TDExNSAyN0wxMTQgMjdMMTExIDI2TDExMyAyN0wxMTQgMjhMMTExIDI4TDEwOSAyN0wxMDggMjdMMTA4IDI2TDEwNyAyNkwxMDUgMjVMMTA1IDI2TDEwMiAyNkwxMDEgMjVMMTA2IDI1TDEwNiAyNEwxMDcgMjNMMTA3IDIyTDEwNSAyMUwxMDQgMjFMMTAzIDIwTDkxIDIwTDkwIDE5TDkwIDE5TDkwIDE4TDkyIDE2TDk0IDE2Wk04MyAxNkw4MyAxN0w4MyAxN0w4MyAxOEw4MiAxOUw4MSAxOUw4MCAxOEw3OCAxN0w3OCAxN1pNMzIwIDE3TDMyMSAxNkwzMjIgMTZMMzIzIDE3Wk04NiAxOEw4NSAxOEw4NCAxN0w4NSAxNkw4OSAxNkw4OCAxN1pNNTYgMTlMNTQgMThMNTYgMTZMNjMgMTZMNjQgMTdMNjEgMTdMNjAgMThaTTMyNCAxNUwzMTcgMTVMMzE4IDE0Wk04MiAxNUw3OSAxNUw3OSAxNEw3OSAxNEw4MCAxM1pNNzQgMTRMNzQgMTVMNzAgMTVMNjggMTZMNjYgMTZMNjYgMTVMNjIgMTVMNjQgMTRMNjkgMTRMNzEgMTVMNzAgMTRMNzAgMTNMNzEgMTNaTTIzMiAxOUwyMzEgMThMMjMyIDE4TDIzMiAxN0wyMzggMTRMMjQ0IDE0TDI0NiAxM0wyNDkgMTNMMjQ4IDE0TDI0NSAxNEwyNDIgMTVMMjM4IDE2TDIzNyAxN0wyMzUgMThMMjM2IDE4Wk04OCAxM0w4OSAxNEw5MiAxNEw5NCAxNUw5NSAxNEw5OSAxNEwxMDAgMTVMOTggMTZMOTcgMTVMOTQgMTZMOTIgMTZMOTAgMTVMODcgMTVMODcgMTRMODQgMTRMODMgMTNaTTY0IDEzTDYzIDEzTDYyIDE0TDU3IDE0TDYxIDEyTDYyIDEzWk0yODcgMTRMMjg4IDEzTDI5MSAxM0wyOTMgMTRMMjk0IDE0TDI5NCAxNUwyOTMgMTVMMjkwIDE2TDI5MyAxNkwyOTQgMTdMMjk0IDE2TDI5OSAxNkwyOTkgMTdMMzAzIDE3TDMwMyAxNkwzMDcgMTZMMzA5IDE3TDMwOSAxOEwzMDggMThMMzEwIDE5TDMxMSAxOUwzMTIgMThMMzE0IDE5TDMxNiAxOEwzMTcgMTlMMzE4IDE4TDMyMCAxOUwzMTkgMThMMzIwIDE3TDMzMCAxOEwzMzMgMTlMMzM5IDE5TDM0MSAyMUwzNDIgMjBMMzQ0IDIwTDM0NiAyMUwzNDggMjBMMzUwIDIxTDM1MSAyMUwzNTAgMjBMMzU2IDIwTDM1OSAyMUwzNjAgMjFMMzYwIDI1TDM1NyAyNUwzNTkgMjdMMzU5IDI4TDM1NyAyN0wzNTUgMjhMMzU0IDI4TDM1MiAyOUwzNTEgMzBMMzUwIDMwTDM0OSAyOUwzNDYgMzBMMzQ0IDMwTDM0MiAzMkwzNDMgMzJMMzQzIDM0TDM0MiAzNEwzNDIgMzVMMzQwIDM2TDM0MCAzN0wzMzkgMzdMMzM3IDM5TDMzNiAzOEwzMzYgMzdMMzM1IDM1TDMzNiAzM0wzMzcgMzNMMzM3IDMyTDMzOCAzMkwzNDQgMjlMMzQ0IDI3TDM0MyAyOEwzNDAgMjlMMzM5IDI4TDMzNyAyOUwzMzQgMzBMMzM1IDMxTDMzMSAzMUwzMzEgMzBMMzMwIDMwTDMyOSAzMUwzMjIgMzFMMzE5IDMzTDMxNSAzNUwzMTcgMzVMMzE3IDM2TDMyMCAzNkwzMjEgMzdMMzIxIDQwTDMyMCA0MkwzMTUgNDdMMzExIDQ3TDMxMSA0OEwzMTAgNDhMMzEwIDQ5TDMwOSA0OUwzMDkgNTBMMzA4IDUwTDMwOCA1MUwzMDggNTFMMzA5IDUzTDMwOSA1NUwzMDggNTVMMzA3IDU2TDMwNiA1NkwzMDYgNTVMMzA3IDU0TDMwNiA1M0wzMDcgNTNMMzA2IDUyTDMwNSA1MkwzMDUgNTBMMzAzIDUwTDMwMiA1MUwzMDIgNTFMMzAxIDUwTDMwMiA1MEwzMDIgNDlMMzAxIDQ5TDI5OSA1MUwyOTggNTFMMjk4IDUyTDI5OSA1MkwyOTkgNTNMMzAwIDUzTDMwMSA1MkwzMDIgNTNMMzAxIDUzTDMwMSA1NEwzMDAgNTRMMjk5IDU1TDMwMSA1N0wzMDEgNThMMzAyIDU4TDMwMiA1OUwzMDEgNTlMMzAyIDYwTDMwMiA2MkwzMDEgNjJMMzAwIDYzTDMwMCA2NEwyOTkgNjVMMjk3IDY2TDI5NiA2N0wyOTUgNjdMMjk0IDY4TDI5NCA2N0wyOTMgNjhMMjkyIDY4TDI5MCA3MEwyOTAgNjlMMjg5IDY4TDI4OCA2OEwyODYgNzBMMjg2IDcyTDI4OSA3NUwyODkgNzhMMjg3IDgwTDI4NiA4MEwyODUgODFMMjg1IDgwTDI4NCA4MEwyODMgNzlMMjgzIDc4TDI4MiA3N0wyODAgNzdMMjgwIDc4TDI3OSA3OUwyNzkgODFMMjgwIDgxTDI4MCA4M0wyODIgODNMMjgyIDg0TDI4MyA4NEwyODMgODdMMjg0IDg3TDI4NCA4OUwyODMgODhMMjgxIDg3TDI4MSA4NUwyODAgODVMMjgwIDgzTDI3OSA4MkwyNzggODJMMjc4IDgxTDI3OSA4MEwyNzggNzlMMjc5IDc5TDI3OCA3OEwyNzkgNzdMMjc4IDc2TDI3OCA3NEwyNzcgNzNMMjc3IDc0TDI3NCA3NEwyNzUgNzNMMjc0IDcyTDI3NCA3MEwyNzMgNzBMMjcyIDY5TDI3MiA2OEwyNzEgNjdMMjcwIDY3TDI3MSA2OEwyNjggNjhMMjY0IDcyTDI2MyA3MkwyNjIgNzNMMjYyIDc0TDI2MCA3NEwyNjAgODBMMjU5IDgwTDI1OSA4MUwyNTggODFMMjU4IDgyTDI1NiA4MEwyNTYgNzlMMjU1IDc4TDI1NSA3NkwyNTQgNzVMMjU0IDc0TDI1MyA3MkwyNTMgNjlMMjUwIDY5TDI0OSA2OEwyNTAgNjhMMjQ4IDY2TDI0NyA2NkwyNDcgNjVMMjQwIDY1TDIzOSA2NEwyMzcgNjRMMjM3IDYzTDIzNiA2M0wyMzUgNjRMMjMzIDYzTDIzMCA2MEwyMjggNjBMMjI4IDYxTDIyOSA2MkwyMjkgNjNMMjMwIDYzTDIzMCA2NEwyMzEgNjVMMjMxIDY0TDIzMiA2NEwyMzIgNjVMMjMxIDY1TDIzMiA2NkwyMzQgNjZMMjM2IDY0TDIzNiA2NUwyMzcgNjZMMjM5IDY2TDIzOSA2N0wyNDAgNjdMMjQwIDY4TDIzOSA2OEwyMzkgNjlMMjM4IDcwTDIzOCA3MUwyMzcgNzFMMjM3IDcyTDIzNSA3MkwyMzUgNzNMMjMzIDczTDIzMSA3NUwyMzAgNzVMMjI5IDc2TDIyNyA3NkwyMjcgNzdMMjIzIDc3TDIyMyA3M0wyMjIgNzNMMjIyIDcyTDIxOSA2OUwyMTkgNjdMMjE4IDY2TDIxNyA2NkwyMTcgNjRMMjE1IDYyTDIxNSA2MUwyMTQgNjJMMjEzIDYyTDIxMiA2MEwyMTMgNjFMMjEzIDYyTDIxNCA2NEwyMTYgNjZMMjE1IDY2TDIxNyA2OEwyMTcgNzFMMjE5IDczTDIxOSA3NEwyMjEgNzZMMjIyIDc2TDIyMiA3N0wyMjMgNzdMMjIzIDc5TDIyNCA3OUwyMjQgODBMMjI1IDgwTDIyNiA3OUwyMjkgNzlMMjMwIDc4TDIzMSA3OEwyMzEgODFMMjI5IDgzTDIyOSA4NUwyMjYgODhMMjI0IDg5TDIyMiA5MUwyMjIgOTJMMjIxIDkyTDIyMCA5M0wyMjAgOTRMMjE5IDk1TDIxOSA5OEwyMjAgOTlMMjIwIDEwMkwyMjEgMTAzTDIyMSAxMDVMMjIwIDEwNUwyMjAgMTA2TDIxOSAxMDdMMjE3IDEwOEwyMTUgMTEwTDIxNSAxMTJMMjE2IDExMkwyMTYgMTEzTDIxNSAxMTRMMjE1IDExNEwyMTQgMTE1TDIxMyAxMTVMMjEzIDExN0wyMTIgMTE4TDIxMiAxMTlMMjExIDExOUwyMTEgMTIwTDIwOCAxMjNMMjA3IDEyM0wyMDYgMTI0TDIwMSAxMjRMMjAwIDEyNUwxOTkgMTI0TDE5OCAxMjRMMTk4IDEyMUwxOTYgMTE5TDE5NiAxMThMMTk1IDExN0wxOTUgMTE1TDE5NCAxMTRMMTk0IDExMkwxOTMgMTExTDE5MyAxMDlMMTkyIDEwOEwxOTIgMTA0TDE5MyAxMDRMMTkzIDEwMkwxOTQgMTAyTDE5NCAxMDFMMTkzIDEwMEwxOTMgOTdMMTkyIDk2TDE5MiA5NUwxODkgOTJMMTg5IDg5TDE5MCA4OEwxOTAgODdMMTg5IDg2TDE4OCA4NkwxODkgODVMMTg3IDg2TDE4NiA4NkwxODUgODVMMTg1IDg0TDE4MSA4NEwxNzkgODVMMTczIDg1TDE3MiA4NkwxNzAgODRMMTY5IDg0TDE2OSA4M0wxNjggODNMMTY3IDgyTDE2NyA4MUwxNjYgODFMMTY2IDgwTDE2NSA4MEwxNjUgNzlMMTY0IDc5TDE2NCA3OEwxNjMgNzhMMTYzIDc2TDE2MiA3NUwxNjMgNzVMMTYzIDc0TDE2NCA3NEwxNjMgNzNMMTY0IDczTDE2NCA3MEwxNjMgNjlMMTYzIDY4TDE2NCA2N0wxNjQgNjZMMTY1IDY2TDE2NSA2NEwxNjYgNjRMMTY2IDYzTDE2NyA2MkwxNjggNjJMMTY5IDYxTDE3MCA2MUwxNzAgNTlMMTcxIDU4TDE3MSA1N0wxNzIgNTZMMTczIDU2TDE3NCA1NUwxNzQgNTRMMTc1IDU0TDE3NSA1NUwxNzggNTVMMTc5IDU0TDE4MSA1NEwxODEgNTNMMTkxIDUzTDE5MSA1NUwxOTAgNTZMMTkxIDU2TDE5MSA1N0wxOTQgNTdMMTk2IDU5TDE5OCA1OUwxOTkgNjBMMjAwIDU5TDIwMCA1OEwyMDEgNTdMMjAzIDU3TDIwMyA1OEwyMDYgNThMMjA3IDU5TDIxMCA1OUwyMTEgNThMMjEyIDU5TDIxNCA1OUwyMTUgNThMMjE1IDU4TDIxNSA1NkwyMTYgNTVMMjE2IDUzTDIxNSA1M0wyMTQgNTRMMjEzIDU0TDIxMiA1M0wyMTEgNTNMMjEwIDU0TDIwOSA1M0wyMDggNTNMMjA3IDUyTDIwNiA1MkwyMDcgNTFMMjA2IDUxTDIwNyA1MEwyMDkgNTBMMjA5IDQ5TDIxMSA0OUwyMTIgNDhMMjE1IDQ4TDIxNyA0OUwyMjAgNDlMMjIyIDQ4TDIyMSA0N0wyMjAgNDdMMjE4IDQ1TDIxNyA0NUwyMTggNDRMMjE4IDQzTDIxNiA0M0wyMTUgNDRMMjE2IDQ1TDIxNSA0NUwyMTQgNDZMMjEzIDQ1TDIxMiA0NUwyMTMgNDRMMjEyIDQ0TDIxMiA0M0wyMTEgNDNMMjEwIDQ0TDIxMCA0NUwyMDkgNDVMMjA5IDQ2TDIwOCA0N0wyMDggNDhMMjA5IDQ5TDIwNyA0OUwyMDYgNTBMMjA2IDQ5TDIwNCA0OUwyMDQgNTBMMjAzIDUwTDIwMyA1MUwyMDQgNTFMMjA0IDUyTDIwMyA1MkwyMDMgNTRMMjAyIDU0TDIwMiA1M0wyMDEgNTJMMjAxIDUxTDIwMCA1MUwyMDAgNTBMMTk5IDUwTDE5OSA0OUwyMDAgNDhMMTk4IDQ4TDE5OCA0N0wxOTcgNDdMMTk2IDQ2TDE5NSA0NkwxOTUgNDVMMTk0IDQ1TDE5NCA0NEwxOTMgNDRMMTkyIDQ1TDE5MyA0NkwxOTQgNDZMMTk0IDQ3TDE5NSA0OEwxOTYgNDhMMTk3IDQ5TDE5OCA0OUwxOTggNTBMMTk2IDUwTDE5NyA1MUwxOTYgNTJMMTk2IDUwTDE5NSA1MEwxOTUgNDlMMTkzIDQ5TDE5MiA0OEwxOTEgNDhMMTkxIDQ3TDE5MCA0NkwxODcgNDZMMTg3IDQ3TDE4MyA0N0wxODMgNDhMMTgyIDQ5TDE4MSA0OUwxODAgNTBMMTgwIDUyTDE3OSA1MkwxNzkgNTNMMTc2IDUzTDE3NSA1NEwxNzQgNTRMMTczIDUzTDE3MSA1M0wxNzEgNTJMMTcwIDUxTDE3MSA1MUwxNzEgNDdMMTcyIDQ2TDE3NSA0NkwxNzYgNDdMMTc4IDQ3TDE3OSA0NkwxNzkgNDRMMTc3IDQyTDE3NiA0MkwxNzUgNDFMMTc4IDQxTDE3OCA0MEwxNzkgNDFMMTgxIDQwTDE4MiAzOUwxODMgMzlMMTg2IDM2TDE4NyAzN0wxODcgMzZMMTg5IDM2TDE4OSAzNUwxODggMzRMMTg4IDMzTDE5MCAzM0wxOTEgMzJMMTkxIDMzTDE5MCAzM0wxOTEgMzRMMTkwIDM0TDE5MCAzNUwxOTEgMzZMMTk1IDM2TDE5NiAzNUwxOTkgMzVMMTk5IDM2TDIwMCAzNkwyMDAgMzVMMjAxIDM1TDIwMSAzM0wyMDIgMzNMMjAzIDMyTDIwMyAzM0wyMDQgMzNMMjA0IDMyTDIwMyAzMUwyMDUgMzFMMjA2IDMwTDIwNyAzMUwyMDggMzFMMjA5IDMwTDIwOCAyOUwyMDYgMzBMMjAyIDMwTDIwMSAyOUwyMDIgMjhMMjAxIDI3TDIwMiAyN0wyMDIgMjZMMjA1IDI1TDIwNSAyNEwyMDIgMjRMMjAxIDI1TDIwMSAyNkwyMDAgMjZMMTk4IDI3TDE5NyAyOUwxOTggMjlMMTk5IDMwTDE5OCAzMUwxOTcgMzFMMTk2IDMzTDE5NiAzNEwxOTUgMzRMMTk0IDM1TDE5MyAzNUwxOTMgMzRMMTkyIDMzTDE5MSAzMUwxOTAgMzFMMTg4IDMyTDE4NyAzMkwxODUgMzBMMTg1IDI4TDE4NiAyN0wxODkgMjdMMTkxIDI2TDE5MiAyNEwxOTUgMjJMMTk2IDIxTDE5OSAyMEwyMDMgMjBMMjA1IDE5TDIwOCAxOUwyMTEgMjBMMjEyIDIwTDIxNCAyMUwyMTcgMjFMMjIwIDIyTDIyMSAyM0wyMjAgMjRMMjE4IDI0TDIxNCAyM0wyMTMgMjNMMjE1IDI0TDIxNSAyNkwyMTcgMjZMMjE3IDI1TDIyMCAyNUwyMjIgMjRMMjI0IDI0TDIyNSAyM0wyMjQgMjNMMjI0IDIyTDIyMyAyMUwyMjYgMjJMMjI2IDIyTDIyNiAyM0wyMjggMjNMMjI4IDIyTDIzMCAyMkwyMzQgMjFMMjMzIDIyTDIzNyAyMkwyMzkgMjFMMjQwIDIyTDI0MSAyMUwyNDAgMjBMMjQ0IDIwTDI0NSAyMUwyNDkgMjJMMjQ5IDIxTDI0NyAyMUwyNDcgMTlMMjQ5IDE4TDI0OSAxN0wyNTMgMTdMMjUzIDE4TDI1MiAxOUwyNTMgMjBMMjUzIDIxTDI1NCAyMkwyNTMgMjJMMjUxIDI0TDI1MiAyNEwyNTMgMjNMMjU0IDIzTDI1NSAyMkwyNTQgMjJMMjU1IDIxTDI1NCAyMUwyNTQgMTlMMjUzIDE5TDI1NSAxOEwyNTUgMTdMMjU2IDE4TDI1NSAxOUwyNTYgMTlMMjU2IDE4TDI2MiAxOEwyNjEgMTdMMjYxIDE2TDI2NiAxNkwyNjcgMTVMMjY4IDE1TDI3MCAxNEwyODEgMTRMMjgxIDEzTDI4MiAxM0wyODQgMTJMMjg2IDEzWk04MSAxMkw4MSAxMUw4MyAxMVpNNzUgMTJMNzYgMTFMNzkgMTFaTTI3OSAxMkwyODEgMTFMMjg1IDExWk0yMDIgMTFMMTk5IDExTDE5NyAxM0wxOTQgMTNMMTk1IDEyTDE5MyAxMkwxOTEgMTFMMTkwIDEwWk0yMDYgMTBMMjAzIDExTDIwMCAxMEwxOTcgMTBMMjAwIDlMMjAyIDEwTDIwMyA5Wk0yMzAgMTBMMjI3IDEwTDIyNyA5Wk0yNzMgMTFMMjczIDEwTDI3MSAxMEwyNzQgOUwyNzggOUwyODAgMTBaTTk0IDExTDkzIDExTDkxIDEyTDg3IDEyTDg2IDExTDg1IDExTDg0IDEwTDgzIDEwTDg0IDlMOTEgOUw5MiAxMFpNMTE4IDdMMTE4IDhMMTE1IDhMMTEyIDlMMTExIDlMMTA5IDEwTDEwNyAxMEwxMDYgMTFMMTA1IDExTDEwNCAxMkwxMDIgMTJMMTAwIDEzTDEwMiAxM0w5OSAxNEw5MSAxNEw5MCAxM0w5MiAxM0w5MiAxMkw5MiAxMkw5MyAxMUw5NSAxMUw5MyAxMEw5OCAxMEw5NiA5TDkwIDlMODkgOEw5MyA4TDk1IDdMOTYgN0w5NyA4TDk4IDdaTTE1OSA3TDE1NyA4TDE1OCA4TDE1NyA5TDE1OSA4TDE2NyA4TDE2OCA5TDE2NCA5TDE2MyAxMEwxNjIgMTBMMTYxIDExTDE2MCAxMUwxNjAgMTJMMTYyIDEzTDE1OCAxM0wxNjAgMTRMMTYwIDE1TDE1OSAxNUwxNjEgMTZMMTYwIDE2TDE1OSAxN0wxNTggMTdMMTU4IDE4TDE1NiAxN0wxNTUgMThMMTU3IDE4TDE1OCAxOUwxNTYgMjBMMTU2IDE5TDE1NSAxOUwxNTQgMjBMMTU4IDIwTDE1MiAyMkwxNDcgMjJMMTQ2IDIzTDE0NCAyNEwxNDIgMjRMMTQwIDI1TDEzOSAyNUwxMzkgMjdMMTM3IDI3TDEzOCAyOEwxMzcgMjlMMTM3IDMwTDEzNSAzMEwxMzQgMjlMMTMxIDI5TDEyOCAyNkwxMjggMjVMMTI2IDI0TDEyNyAyM0wxMjYgMjNMMTI3IDIyTDEyOSAyMUwxMjkgMjBMMTI4IDIwTDEyNyAyMUwxMjUgMjBMMTI2IDE5TDEyNyAxOUwxMjYgMThMMTI1IDE5TDEyNCAxOEwxMjUgMTdMMTIzIDE1TDEyMSAxNUwxMjEgMTRMMTEwIDE0TDEwOSAxM0wxMTMgMTNMMTA5IDEyTDEwNyAxMkwxMTEgMTFMMTE0IDExTDExNSAxMEwxMTIgMTBMMTEzIDlMMTE4IDlMMTE3IDhMMTMzIDhMMTMzIDdMMTQwIDdMMTQxIDZaIiBmaWxsPSIjM2Y3ZDRlIiBzdHJva2U9IiM1ZmE4NmEiIHN0cm9rZS13aWR0aD0iLjQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiLz48L3N2Zz4=';

  function load() {
    var t = localStorage.getItem('nsw_tile'),
        r = localStorage.getItem('nsw_radius'),
        m = localStorage.getItem('nsw_remote');
    if (t && /^-?\d+_-?\d+$/.test(t)) TILE = t;
    if (r) RADIUS = Math.min(7, Math.max(1, parseInt(r, 10)));
    if (m !== null) REMOTE = (m === 'true');
    sync();
  }
  function save() {
    localStorage.setItem('nsw_tile', TILE);
    localStorage.setItem('nsw_radius', RADIUS);
    localStorage.setItem('nsw_remote', REMOTE);
    sync();
  }
  function sync() { slider.value = RADIUS; rVal.textContent = RADIUS; remote.checked = REMOTE; tileOut.textContent = TILE; }

  function draw() {
    // base layer: the world map (fallback to ocean fill until the image decodes)
    if (mapImg.complete && mapImg.naturalWidth) ctx.drawImage(mapImg, 0, 0, 720, 360);
    else { ctx.fillStyle = '#0e2a47'; ctx.fillRect(0, 0, 720, 360); }
    // faint graticule every 30 degrees, equator + prime meridian a touch brighter
    ctx.strokeStyle = 'rgba(255,255,255,.10)'; ctx.lineWidth = 1;
    for (var lat = -60; lat <= 60; lat += 30) { var y = (90 - lat) * 2; ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(720, y); ctx.stroke(); }
    for (var lon = -150; lon <= 150; lon += 30) { var x = (lon + 180) * 2; ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, 360); ctx.stroke(); }
    ctx.strokeStyle = 'rgba(255,255,255,.18)';
    ctx.beginPath(); ctx.moveTo(0, 180); ctx.lineTo(720, 180); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(360, 0); ctx.lineTo(360, 360); ctx.stroke();
    // selected tile + radius ring
    var p = TILE.split('_'), sLat = parseInt(p[0], 10), sLon = parseInt(p[1], 10);
    var x0 = (sLon + 180) * 2, y0 = (90 - sLat - 1) * 2;
    ctx.fillStyle = 'rgba(255,215,0,.85)'; ctx.fillRect(x0, y0, 2, 2);
    ctx.strokeStyle = 'gold'; ctx.lineWidth = 1.5; ctx.strokeRect(x0 - 1, y0 - 1, 4, 4);
    if (RADIUS > 1) { ctx.strokeStyle = 'rgba(255,215,0,.5)'; ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(x0 + 1, y0 + 1, RADIUS * 2, 0, Math.PI * 2); ctx.stroke(); }
  }
  function tileAt(e) {
    var rect = canvas.getBoundingClientRect();
    var mx = (e.clientX - rect.left) * (canvas.width / rect.width);
    var my = (e.clientY - rect.top) * (canvas.height / rect.height);
    var lon = Math.max(-180, Math.min(179, Math.floor(mx / 2) - 180));
    var lat = Math.max(-90, Math.min(89, 89 - Math.floor(my / 2)));
    return lat + '_' + lon;
  }

  canvas.addEventListener('click', function (e) { TILE = tileAt(e); save(); draw(); });
  slider.addEventListener('input', function () { RADIUS = parseInt(this.value, 10); rVal.textContent = RADIUS; draw(); save(); });
  remote.addEventListener('change', function () { REMOTE = this.checked; save(); });
  toggle.addEventListener('change', function () { if (this.checked) draw(); });

  load(); draw();

  /* ── carousel state ── */
  var jobsListings = [], jobsIdx = 0, jobsTotal = 0;
  var resumesListings = [], resumesIdx = 0, resumesTotal = 0;

  function apiUrl(type, page, keyword) {
    return 'index.php?api=' + type
      + '&tile='    + encodeURIComponent(getTile())
      + '&radius='  + getRadius()
      + '&remote='  + (getRemote() ? '1' : '0')
      + '&keyword=' + encodeURIComponent(keyword || '')
      + '&page='    + (page || 0);
  }

  function renderJob(l) {
    var isRemote = l.remote ? ' 🌐 Remote' : '';
    return '<div class="job-card">'
      + '<div class="JobSectionTop"><h3>' + esc(l.title) + '</h3>'
      + '<small>' + esc(l.category.replace(/_/g,' ')) + isRemote + '</small></div>'
      + '<div class="JobSectionMedium">' + esc(l.description) + '</div>'
      + '<div class="JobSectionBottom">'
      + '<span>📍 ' + esc(l.tile.replace('_','°N ') + '°') + '</span>'
      + '<span>💰 ' + esc(l.pay_amount || l.pay_type.replace(/_/g,' ')) + '</span>'
      + (l.contact_email ? '<span>✉️ ' + esc(l.contact_email) + '</span>' : '')
      + (l.contact_phone ? '<span>📞 ' + esc(l.contact_phone) + '</span>' : '')
      + '</div></div>';
  }

  function renderResume(l) {
    return '<div class="resume-card">'
      + '<div class="resume-preview" style="padding:14px;white-space:pre-wrap;color:#334;align-items:flex-start;text-align:left">'
      + esc(l.resume_text || 'No résumé text provided') + '</div>'
      + '<div class="resume-details">'
      + '<div class="detail-line">' + esc(l.name) + '</div>'
      + (l.phone ? '<div class="detail-line">📞 ' + esc(l.phone) + '</div>' : '')
      + '<div class="detail-line">✉️ ' + esc(l.email) + '</div>'
      + '<div class="detail-line">🔑 ' + esc(l.keywords) + '</div>'
      + (l.min_salary ? '<div>💰 ' + esc(l.min_salary) + '/hr min</div>' : '')
      + (l.remote ? '<div>🌐 Open to remote</div>' : '')
      + '</div></div>';
  }

  function showJobsPage() {
    var car = document.getElementById('jobsCarousel');
    var pgr = document.getElementById('jobsPager');
    if (!jobsListings.length) {
      car.innerHTML = '<div class="carousel-msg">No jobs found in this area yet.<br>Be the first to post one!</div>';
      pgr.textContent = '';
      return;
    }
    car.innerHTML = renderJob(jobsListings[jobsIdx]);
    pgr.textContent = (jobsIdx + 1) + ' / ' + jobsListings.length + (jobsTotal > jobsListings.length ? '+' : '');
  }

  function showResumesPage() {
    var car = document.getElementById('resumesCarousel');
    var pgr = document.getElementById('resumesPager');
    if (!resumesListings.length) {
      car.innerHTML = '<div class="carousel-msg">No resumes found in this area yet.</div>';
      pgr.textContent = '';
      return;
    }
    car.innerHTML = renderResume(resumesListings[resumesIdx]);
    pgr.textContent = (resumesIdx + 1) + ' / ' + resumesListings.length + (resumesTotal > resumesListings.length ? '+' : '');
  }

  function fetchJobs(page, keyword) {
    document.getElementById('jobsCarousel').innerHTML = '<div class="carousel-msg">Loading…</div>';
    fetch(apiUrl('jobs', page || 0, keyword !== undefined ? keyword : document.getElementById('jobsKeyword').value))
      .then(function(r){ return r.json(); })
      .then(function(data){
        jobsListings = data.listings || [];
        jobsTotal    = data.total   || 0;
        jobsIdx      = 0;
        showJobsPage();
      })
      .catch(function(){
        document.getElementById('jobsCarousel').innerHTML = '<div class="carousel-msg" style="color:var(--red)">Could not load listings.</div>';
      });
  }

  function fetchResumes(page, keyword) {
    document.getElementById('resumesCarousel').innerHTML = '<div class="carousel-msg">Loading…</div>';
    fetch(apiUrl('resumes', page || 0, keyword !== undefined ? keyword : document.getElementById('resumesKeyword').value))
      .then(function(r){ return r.json(); })
      .then(function(data){
        resumesListings = data.listings || [];
        resumesTotal    = data.total   || 0;
        resumesIdx      = 0;
        showResumesPage();
      })
      .catch(function(){
        document.getElementById('resumesCarousel').innerHTML = '<div class="carousel-msg" style="color:var(--red)">Could not load listings.</div>';
      });
  }

  /* arrow navigation */
  document.getElementById('jobsPrevBtn').addEventListener('click', function(){
    if (jobsIdx > 0) { jobsIdx--; showJobsPage(); }
  });
  document.getElementById('jobsNextBtn').addEventListener('click', function(){
    if (jobsIdx < jobsListings.length - 1) { jobsIdx++; showJobsPage(); }
    else if (jobsListings.length === 10) { fetchJobs(Math.floor(jobsIdx / 10) + 1); }
  });
  document.getElementById('resumesPrevBtn').addEventListener('click', function(){
    if (resumesIdx > 0) { resumesIdx--; showResumesPage(); }
  });
  document.getElementById('resumesNextBtn').addEventListener('click', function(){
    if (resumesIdx < resumesListings.length - 1) { resumesIdx++; showResumesPage(); }
    else if (resumesListings.length === 10) { fetchResumes(Math.floor(resumesIdx / 10) + 1); }
  });

  /* search / filter */
  document.getElementById('jobsFilterBtn').addEventListener('click', function(){ fetchJobs(0); });
  document.getElementById('resumesFilterBtn').addEventListener('click', function(){ fetchResumes(0); });

  /* auto-load when panels open */
  document.getElementById('toggleBrowseJobs').addEventListener('change', function(){
    if (this.checked) fetchJobs(0, '');
  });
  document.getElementById('toggleGetResumes').addEventListener('change', function(){
    if (this.checked) fetchResumes(0, '');
  });

  /* loud submit-warning gate — must be acknowledged before ANY submission.
     EXPIRY_DAYS mirrors NSW_EXPIRY_DAYS in the PHP block above. */
  var EXPIRY_DAYS = 90;
  function confirmPost(kind) {
    return window.confirm(
      '⚠️  HEADS UP — you can’t take this back\n\n' +
      'Once submitted, you can’t edit or remove this ' + kind + ' yourself.\n' +
      'It stays live and is automatically deleted ' + EXPIRY_DAYS + ' days after posting.\n\n' +
      'Submit it now?'
    );
  }
  function postForm(url, body, btn, busyLabel, okMsg, toggleId, resetId) {
    btn.disabled = true; btn.textContent = busyLabel;
    fetch(url, { method: 'POST', body: body })   // URLSearchParams => x-www-form-urlencoded
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) {
          toast(okMsg);
          document.getElementById(resetId).click();          // clear only on success
          document.getElementById(toggleId).checked = false;  // close the panel
        } else {
          toast('Error: ' + (data.error || 'unknown'));
        }
      })
      .catch(function(){ toast('Network error — try again.'); })
      .finally(function(){ btn.disabled = false; btn.textContent = 'Submit'; });
  }

  /* ── form: Post a Job ── */
  document.getElementById('pj-reset').addEventListener('click', function(){
    ['pj-category','pj-pay-type','pj-pay-amount','pj-title','pj-keywords','pj-email','pj-phone','pj-details']
      .forEach(function(id){ document.getElementById(id).value = ''; });
  });

  document.getElementById('pj-submit').addEventListener('click', function(){
    var category  = document.getElementById('pj-category').value;
    var payType   = document.getElementById('pj-pay-type').value;
    var payAmount = document.getElementById('pj-pay-amount').value.trim();
    var title     = document.getElementById('pj-title').value.trim();
    var details   = document.getElementById('pj-details').value.trim();
    if (!category || !payType || !payAmount || !title || !details) {
      toast('Jobs must state the pay — fill in category, pay type, pay amount, title and details.'); return;
    }
    if (!confirmPost('job post')) return;
    var isRemote = payType.indexOf('remote') === 0 ? '1' : (getRemote() ? '1' : '0');
    var body = new URLSearchParams({
      tile:       getTile(),
      category:   category,
      pay_type:   payType,
      pay_amount: payAmount,
      title:      title,
      keywords:   document.getElementById('pj-keywords').value,
      email:      document.getElementById('pj-email').value,
      phone:      document.getElementById('pj-phone').value,
      details:    details,
      remote:     isRemote
    });
    postForm('index.php?api=post_job', body, this, 'Posting…', 'Job posted ✓ (live for ' + EXPIRY_DAYS + ' days)', 'togglePostJobs', 'pj-reset');
  });

  /* ── form: Leave a Resume (text only) ── */
  document.getElementById('pr-reset').addEventListener('click', function(){
    ['pr-name','pr-email','pr-phone','pr-min-salary','pr-keywords','pr-resume-text']
      .forEach(function(id){ document.getElementById(id).value = ''; });
  });

  document.getElementById('pr-submit').addEventListener('click', function(){
    var name       = document.getElementById('pr-name').value.trim();
    var email      = document.getElementById('pr-email').value.trim();
    var resumeText = document.getElementById('pr-resume-text').value.trim();
    if (!name || !email || !resumeText) {
      toast('Please add your name, email and résumé text.'); return;
    }
    if (!confirmPost('résumé')) return;
    var body = new URLSearchParams({
      tile:        getTile(),
      name:        name,
      email:       email,
      phone:       document.getElementById('pr-phone').value,
      min_salary:  document.getElementById('pr-min-salary').value,
      keywords:    document.getElementById('pr-keywords').value,
      resume_text: resumeText,
      remote:      getRemote() ? '1' : '0'
    });
    postForm('index.php?api=post_resume', body, this, 'Submitting…', 'Résumé posted ✓ (live for ' + EXPIRY_DAYS + ' days)', 'toggleDropResumes', 'pr-reset');
  });

  /* ── Host a Mirror: stage URLs/IPs CLIENT-SIDE only (localStorage, FIFO cap 64)
     for a future gossip pass. Same mechanism + key as nosignup.chat. No server
     endpoint, no new file — server-side gossip is deliberately deferred (README §6). */
  var MIRROR_KEY = 'nosignup_pending_mirrors', MIRROR_CAP = 64;
  function stageMirror(){
    var inp = document.getElementById('mirrorInput'), st = document.getElementById('mirrorStatus');
    var raw = (inp.value || '').trim();
    var bare = raw.replace(/^https?:\/\//i, '');
    if (raw.length < 4 || raw.length > 200 || !/^[a-z0-9][a-z0-9.\-:\/_~%\[\]]*$/i.test(bare)) {
      st.style.color = '#ff9c8a'; st.textContent = '✗ enter a valid URL or IP'; return;
    }
    var list = []; try { list = JSON.parse(localStorage.getItem(MIRROR_KEY) || '[]'); } catch (_) { list = []; }
    if (!Array.isArray(list)) list = [];
    if (list.indexOf(raw) === -1) {
      list.push(raw);
      while (list.length > MIRROR_CAP) list.shift();
      try { localStorage.setItem(MIRROR_KEY, JSON.stringify(list)); } catch (_) {}
    }
    st.style.color = '#7fe0a0'; st.textContent = '✓ staged locally (' + list.length + ' pending)';
    inp.value = '';
  }
  document.getElementById('mirrorSubmit').addEventListener('click', stageMirror);
  document.getElementById('mirrorInput').addEventListener('keypress', function(e){ if (e.key === 'Enter') stageMirror(); });

}());
</script>
</body>
</html>
