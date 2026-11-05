<?php
/**
 * 404.php — ONE FILE for the "page not found" screen.
 * Contains: device detection + the mobile layout + the desktop layout.
 *
 * Device priority:
 *   1. ?view=mobile | ?view=desktop   (manual override, remembered in a cookie)
 *   2. Cookie from a previous override
 *   3. X-HighSpinn-App header          (native app webview => mobile)
 *   4. User-Agent sniffing             (phones => mobile, everything else => desktop)
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('hs_is_mobile_request')) {
    function hs_is_mobile_request(): bool {
        if (isset($_GET['view']) && in_array($_GET['view'], ['mobile', 'desktop'], true)) {
            setcookie('hs_view', $_GET['view'], time() + 30 * 86400, '/');
            return $_GET['view'] === 'mobile';
        }
        if (isset($_COOKIE['hs_view']) && in_array($_COOKIE['hs_view'], ['mobile', 'desktop'], true)) {
            return $_COOKIE['hs_view'] === 'mobile';
        }
        if (($_SERVER['HTTP_X_HIGHSPINN_APP'] ?? '') === 'true') {
            return true;
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua)) return false;
        if (preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini|IEMobile|Mobi/i', $ua)) return true;
        return false;
    }
}

// Same URL returns different HTML, so tell caches/CDNs
header('Vary: User-Agent, Cookie, X-HighSpinn-App');
$isMobile = hs_is_mobile_request();

include('include/config.php');

header("HTTP/1.1 404 Not Found");

include 'include/header.php';
?>

<?php if ($isMobile): ?>
<?php /* =====================================================================
   MOBILE LAYOUT
   ===================================================================== */ ?>

<style>
body.hs-app-view .navbar-hs,
body.hs-app-view footer { display:none !important; }

.hs-app{ max-width:480px; margin:0 auto; min-height:100vh; display:flex; flex-direction:column; }

/* ---- sticky header ---- */
.er-header{
  position:sticky; top:0; z-index:40; padding:14px 16px;
  display:flex; align-items:center; justify-content:space-between;
  background:rgba(10,10,13,.95); backdrop-filter:blur(14px); border-bottom:1px solid var(--line);
}
.er-logo{ font-weight:800; font-size:20px; text-decoration:none; }
.er-logo .hi{ color:var(--text); } .er-logo .spinn{ color:var(--pink); }
.er-home{
  width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center;
  background:var(--panel-2); border:1px solid var(--line); color:var(--text); font-size:14px;
}

.er-body{ flex:1; padding:28px 16px 32px; display:flex; flex-direction:column; justify-content:center; }

/* ---- vertical torn-ticket card ---- */
.er-ticket{
  background:var(--panel); border:1px solid var(--line); border-radius:22px; overflow:hidden;
  box-shadow:0 30px 60px -30px rgba(0,0,0,.6);
  animation:er-drop .55s cubic-bezier(.34,1.56,.64,1);
}
@keyframes er-drop{
  0%{ transform:translateY(-14px) rotate(-1.5deg); opacity:0; }
  100%{ transform:translateY(0) rotate(0); opacity:1; }
}

.er-stub{
  padding:30px 20px 24px; text-align:center;
  background:linear-gradient(160deg, rgba(255,61,104,.16), rgba(255,122,69,.05));
}
.er-code{ font-size:68px; font-weight:800; line-height:1; letter-spacing:-.02em; color:var(--text); margin-bottom:6px; }
.er-code span{ color:var(--pink); }
.er-stub-label{ font-size:11px; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--muted-2); margin-bottom:18px; }
.er-barcode{ display:flex; align-items:flex-end; justify-content:center; gap:3px; height:30px; opacity:.55; }
.er-barcode span{ display:block; width:2px; background:var(--muted); border-radius:1px; }
.er-barcode span:nth-child(1){ height:60%; }  .er-barcode span:nth-child(2){ height:100%; }
.er-barcode span:nth-child(3){ height:40%; }  .er-barcode span:nth-child(4){ height:80%; }
.er-barcode span:nth-child(5){ height:55%; }  .er-barcode span:nth-child(6){ height:100%; }
.er-barcode span:nth-child(7){ height:30%; }  .er-barcode span:nth-child(8){ height:70%; }
.er-barcode span:nth-child(9){ height:50%; }  .er-barcode span:nth-child(10){ height:90%; }
.er-barcode span:nth-child(11){ height:35%; } .er-barcode span:nth-child(12){ height:65%; }

/* perforated divider with side notches */
.er-tear{ position:relative; height:0; border-top:2px dashed var(--line); margin:0 16px; }
.er-tear::before, .er-tear::after{
  content:''; position:absolute; top:-14px; width:28px; height:28px; border-radius:50%;
  background:var(--void, #0a0a0d); border:1px solid var(--line);
}
.er-tear::before{ left:-31px; }
.er-tear::after{ right:-31px; }

.er-main{ padding:24px 20px 24px; text-align:center; }
.er-eyebrow{
  display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700;
  letter-spacing:.1em; text-transform:uppercase; color:var(--yellow); margin-bottom:10px;
}
.er-main h1{ font-size:22px; font-weight:800; line-height:1.25; margin:0 0 10px; }
.er-main p{ color:var(--muted); font-size:13.5px; line-height:1.6; margin:0 0 22px; }

.er-actions{ display:flex; flex-direction:column; gap:10px; }
.er-actions a{ display:block; text-align:center; padding:14px; font-size:14px; }

/* search — same pill style as the app's home search */
.er-search{
  margin-top:18px; display:flex; align-items:center; gap:9px; background:var(--panel-2);
  border:1px solid var(--line); border-radius:100px; padding:4px 6px 4px 16px;
}
.er-search i.lead{ color:var(--muted-2); font-size:13px; }
.er-search input{
  flex:1; min-width:0; background:none; border:none; outline:none; color:var(--text);
  font-size:16px; padding:10px 0;
}
.er-search input::placeholder{ color:var(--muted-2); }
.er-search button{
  flex:none; width:38px; height:38px; border-radius:50%; border:none; background:var(--pink); color:#fff; font-size:13px;
}

.er-footnote{ text-align:center; margin-top:22px; font-size:12px; color:var(--muted-2); }
.er-footnote a{ color:var(--muted); text-decoration:underline; }
</style>

<div class="hs-app">

  <div class="er-header">
    <a class="er-logo" href="/"><span class="hi">HIGH</span><span class="spinn">SPINN</span></a>
    <a class="er-home" href="/" aria-label="Home"><i class="fa-solid fa-house"></i></a>
  </div>

  <div class="er-body">

    <div class="er-ticket">
      <div class="er-stub">
        <div class="er-code">4<span>0</span>4</div>
        <div class="er-stub-label">Not found</div>
        <div class="er-barcode">
          <span></span><span></span><span></span><span></span><span></span><span></span>
          <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
      </div>

      <div class="er-tear"></div>

      <div class="er-main">
        <div class="er-eyebrow"><i class="fa-solid fa-ticket"></i> Sold out of pages</div>
        <h1>This one didn't make the guest list.</h1>
        <p>The page or event you're looking for doesn't exist, moved, or the link's just plain wrong. Let's get you back to something real.</p>

        <div class="er-actions">
          <a href="/" class="btn-pink">Back to home</a>
          <a href="/events" class="btn-dark-outline">Browse events</a>
        </div>

        <form class="er-search" action="/events" method="get">
          <i class="fa-solid fa-magnifying-glass lead"></i>
          <input type="text" name="q" placeholder="Search events instead..." autocomplete="off">
          <button type="submit" aria-label="Search"><i class="fa-solid fa-arrow-right"></i></button>
        </form>
      </div>
    </div>

    <div class="er-footnote">
      Think this is a mistake? <a href="/contact">Let us know</a>.
    </div>

  </div>
</div>

<script>document.body.classList.add('hs-app-view');</script>

<?php else: ?>
<?php /* =====================================================================
   DESKTOP LAYOUT
   ===================================================================== */ ?>

<style>

.err-wrap{ padding:8rem 0 5rem; }

/* ---------- torn ticket stub ---------- */
.err-ticket{
  max-width:760px; margin:0 auto;
  display:flex;
  background:var(--panel);
  border:1px solid var(--line);
  border-radius:22px;
  overflow:hidden;
  position:relative;
  box-shadow:0 30px 60px -30px rgba(0,0,0,.5);
}

.err-stub{
  flex:none;
  width:220px;
  padding:2.2rem 1.6rem;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  text-align:center;
  background:linear-gradient(160deg, rgba(255,61,104,.14), rgba(255,122,69,.05));
  position:relative;
}

.err-code{
  font-size:3.6rem;
  font-weight:800;
  line-height:1;
  letter-spacing:-.02em;
  color:var(--text);
  margin-bottom:.4rem;
}
.err-code span{ color:var(--pink); }

.err-stub-label{
  font-size:.68rem;
  font-weight:700;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:var(--muted-2);
  margin-bottom:1.4rem;
}

.err-barcode{
  display:flex;
  align-items:flex-end;
  gap:3px;
  height:34px;
  opacity:.55;
}
.err-barcode span{
  display:block;
  width:2px;
  background:var(--muted);
  border-radius:1px;
}
.err-barcode span:nth-child(1){ height:60%; }
.err-barcode span:nth-child(2){ height:100%; }
.err-barcode span:nth-child(3){ height:40%; }
.err-barcode span:nth-child(4){ height:80%; }
.err-barcode span:nth-child(5){ height:55%; }
.err-barcode span:nth-child(6){ height:100%; }
.err-barcode span:nth-child(7){ height:30%; }
.err-barcode span:nth-child(8){ height:70%; }
.err-barcode span:nth-child(9){ height:50%; }
.err-barcode span:nth-child(10){ height:90%; }
.err-barcode span:nth-child(11){ height:35%; }
.err-barcode span:nth-child(12){ height:65%; }

/* perforated divider */
.err-divider{
  flex:none;
  width:0;
  border-left:2px dashed var(--line);
  position:relative;
}
.err-divider::before,
.err-divider::after{
  content:'';
  position:absolute;
  left:50%;
  width:28px; height:28px;
  background:var(--bg, #0a0a0d);
  border-radius:50%;
  transform:translateX(-50%);
}
.err-divider::before{ top:-14px; }
.err-divider::after{ bottom:-14px; }

.err-main{
  flex:1;
  padding:2.4rem 2.4rem;
  display:flex;
  flex-direction:column;
  justify-content:center;
}

.err-eyebrow{
  display:inline-flex;
  align-items:center;
  gap:.4rem;
  font-size:.72rem;
  font-weight:700;
  letter-spacing:.1em;
  text-transform:uppercase;
  color:var(--yellow);
  margin-bottom:.8rem;
}
.err-eyebrow i{ font-size:.7rem; }

.err-main h1{
  font-size:clamp(1.5rem,2.6vw,1.9rem);
  margin-bottom:.6rem;
  line-height:1.25;
}

.err-main p{
  color:var(--muted);
  font-size:.92rem;
  line-height:1.6;
  max-width:420px;
  margin-bottom:1.8rem;
}

.err-actions{ display:flex; gap:.8rem; flex-wrap:wrap; }
.err-actions a{ min-width:150px; text-align:center; }

.err-search{
  margin-top:1.6rem;
  display:flex;
  gap:.6rem;
  max-width:360px;
}
.err-search input{
  flex:1;
  background:var(--panel-2);
  border:1px solid var(--line);
  border-radius:10px;
  padding:.65rem .9rem;
  color:var(--text);
  font-size:.85rem;
}
.err-search input::placeholder{ color:var(--muted-2); }
.err-search input:focus{ outline:2px solid var(--pink); outline-offset:1px; }
.err-search button{
  background:var(--panel-2);
  border:1px solid var(--line);
  border-radius:10px;
  color:var(--text);
  padding:0 1rem;
  font-size:.9rem;
}
.err-search button:hover{ border-color:var(--pink); color:var(--pink); }

.err-footnote{
  text-align:center;
  margin-top:2rem;
  font-size:.78rem;
  color:var(--muted-2);
}
.err-footnote a{ color:var(--muted); text-decoration:underline; }

@keyframes err-drop{
  0%{ transform:translateY(-14px) rotate(-1.5deg); opacity:0; }
  100%{ transform:translateY(0) rotate(-1.5deg); opacity:1; }
}
.err-ticket{ animation:err-drop .55s cubic-bezier(.34,1.56,.64,1); }

@media (max-width:700px){
  .err-ticket{ flex-direction:column; }
  .err-stub{ width:auto; flex-direction:row; justify-content:center; gap:1rem; padding:1.6rem; }
  .err-code{ font-size:2.6rem; margin-bottom:0; }
  .err-stub-label{ margin-bottom:0; }
  .err-barcode{ display:none; }
  .err-divider{ width:auto; height:0; border-left:none; border-top:2px dashed var(--line); }
  .err-divider::before,
  .err-divider::after{ top:50%; left:auto; transform:translateY(-50%); }
  .err-divider::before{ left:-14px; }
  .err-divider::after{ left:auto; right:-14px; }
  .err-main{ padding:2rem 1.6rem; text-align:center; }
  .err-main p{ margin-left:auto; margin-right:auto; }
  .err-actions{ justify-content:center; }
  .err-search{ margin-left:auto; margin-right:auto; }
}
</style>

<div class="err-wrap">
  <div class="container">

    <div class="err-ticket">
      <div class="err-stub">
        <div class="err-code">4<span>0</span>4</div>
        <div class="err-stub-label">Not found</div>
        <div class="err-barcode">
          <span></span><span></span><span></span><span></span><span></span><span></span>
          <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
      </div>

      <div class="err-divider"></div>

      <div class="err-main">
        <div class="err-eyebrow"><i class="fa-solid fa-ticket"></i> Sold out of pages</div>
        <h1>This one didn't make the guest list.</h1>
        <p>The page or event you're looking for doesn't exist, moved, or the link's just plain wrong. Let's get you back to something real.</p>

        <div class="err-actions">
          <a href="/" class="btn-pink">Back to home</a>
          <a href="/events" class="btn-dark-outline">Browse events</a>
        </div>

        <form class="err-search" action="/events" method="get">
          <input type="text" name="q" placeholder="Search events instead...">
          <button type="submit" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
      </div>
    </div>

    <div class="err-footnote">
      Think this is a mistake? <a href="/contact">Let us know</a>.
    </div>

  </div>
</div>

<?php endif; // isMobile ?>

<?php include $isMobile ? 'include/footerapp.php' : 'include/footer.php'; ?>