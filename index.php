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

<!-- home screen -->
<div class="btn-container">
  <label for="toggleBrowseJobs"  class="btn-label BrowseJobs"><span>Browse Jobs</span></label>
  <label for="togglePostJobs"    class="btn-label PostJobs"><span>Post a Job</span></label>
  <label for="toggleDropResumes" class="btn-label DropResumes"><span>Leave a Resume</span></label>
  <label for="toggleGetResumes"  class="btn-label GetResumes"><span>Browse Resumes (CV's)</span></label>
</div>

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
    ctx.fillStyle = '#1a4d8c'; ctx.fillRect(0, 0, 720, 360);
    ctx.strokeStyle = 'rgba(255,204,136,.35)'; ctx.lineWidth = .5;
    for (var lat = -90; lat <= 90; lat++) { var y = (90 - lat) * 2; ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(720, y); ctx.stroke(); }
    for (var lon = -180; lon <= 180; lon++) { var x = (lon + 180) * 2; ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, 360); ctx.stroke(); }
    var p = TILE.split('_'), sLat = parseInt(p[0], 10), sLon = parseInt(p[1], 10);
    var x0 = (sLon + 180) * 2, y0 = (90 - sLat - 1) * 2;
    ctx.fillStyle = 'rgba(255,215,0,.6)'; ctx.fillRect(x0, y0, 2, 2);
    ctx.strokeStyle = 'gold'; ctx.lineWidth = 1.5; ctx.strokeRect(x0 - .5, y0 - .5, 3, 3);
    if (RADIUS > 1) { ctx.strokeStyle = 'rgba(255,215,0,.4)'; ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(x0 + 1, y0 + 1, RADIUS * 2, 0, Math.PI * 2); ctx.stroke(); }
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

}());
</script>
</body>
</html>
