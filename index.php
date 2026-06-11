<!DOCTYPE html>
<!--
================================================================================
DO NOT DELETE/REMOVE THIS BLOCK — NOSIGNUP.WORK — DO NOT DELETE/REMOVE THIS BLOCK
================================================================================
NOSIGNUP.WORK — stateless job/resume exchange. No signup, no tracking.
localStorage allowed. PHASE: HTML/CSS front-end; PHP file-shard backend NOT wired yet.

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
.JobSectionMedium{flex:1;background:rgba(33,150,243,.12);border:1px solid rgba(33,150,243,.22);
  font-size:clamp(15px,1.4vw,18px);line-height:1.5;color:#334}
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
.UploadContainer{display:flex;align-items:center;justify-content:center;padding:8px;
  border:1px dashed var(--line);border-radius:12px}
.ResumeInput{width:100%;font-size:14px}
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
  <div class="carousel">
    <div class="job-card">
      <div class="JobSectionTop"><h3>Sample Job Title</h3></div>
      <div class="JobSectionMedium">This is a preview of the job-card layout. Live listings appear here once the board is connected to its backend.</div>
      <div class="JobSectionBottom"><span>📍 Location</span><span>💰 Pay / rate</span></div>
    </div>
  </div>
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
  <div class="CenterSection">
    <select>
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
      <select>
        <option value="">Pay Type</option>
        <option value="remote_fixed">Remote — Fixed Bounty</option>
        <option value="remote_hourly">Remote — Hourly</option>
        <option value="inperson_fixed">In Person — Fixed</option>
        <option value="inperson_hourly">In Person — Hourly</option>
      </select>
      <input type="text" placeholder="Pay Amount">
    </div>
    <input type="text" placeholder="Job Title">
    <input type="text" placeholder="Keyword, Keyword, Keyword...">
    <div class="row">
      <input type="email" placeholder="E-mail">
      <input type="text" placeholder="Phone Number">
      <label for="toggleMapMenu" class="InFormMapBtn">📍 Set Location</label>
    </div>
    <textarea class="JobDetailsTextarea" placeholder="Job Details"></textarea>
    <div class="row ButtonContainer">
      <button class="ResetButton">Reset</button>
      <button class="SubmitButton">Submit</button>
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
  <div class="CenterSection">
    <input type="text" placeholder="Full Name">
    <input type="email" placeholder="Email">
    <input type="text" placeholder="Phone Number">
    <input type="text" placeholder="Minimum Salary Per Hour">
    <input type="text" placeholder="Keywords (e.g., Skill1, Skill2, Skill3)">
    <div class="row">
      <div class="UploadContainer"><input type="file" class="ResumeInput" accept="application/pdf"></div>
      <label for="toggleMapMenu" class="InFormMapBtn">📍 Set Location</label>
    </div>
    <div class="row ButtonContainer">
      <button class="ResetButton">Reset</button>
      <button class="SubmitButton">Submit</button>
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
  <div class="carousel">
    <div class="resume-card">
      <div class="resume-preview">Résumé preview (PDF) appears here</div>
      <div class="resume-details">
        <div class="detail-line">Applicant Name</div>
        <div class="detail-line">Phone</div>
        <div class="detail-line">Email</div>
        <div class="detail-line">Skills, keywords</div>
      </div>
    </div>
  </div>
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
/* The ONLY JavaScript in the app — drives the map (canvas, click->tile, radius,
   remote, localStorage). Panels and the overlay are pure CSS. Carousel pagination
   and live listings get wired in here once the backend exists (see header block). */
(function () {
  'use strict';
  var TILE = '40_-74', RADIUS = 1, REMOTE = false;
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
}());
</script>
</body>
</html>
