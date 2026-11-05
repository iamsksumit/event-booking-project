<?php /**
 * include/mobile-detail.css.php
 * -----------------------------------------------------------------------
 * Shared <style> for the mobile "detail" pages (venue, artist, event).
 * Echo this once near the top of each page's markup:
 *     <?php include __DIR__ . '/mobile-detail.css.php'; ?>
 * Keeps the hero/back-button/stacked-card look identical across all three
 * instead of copy-pasted CSS drifting out of sync.
 * -----------------------------------------------------------------------
 */
?>
<style>
body.hs-app-view .navbar-hs,
body.hs-app-view footer { display:none !important; }
body.hs-app-view{ padding-bottom:0; background:var(--void); }

.hs-detail{ max-width:480px; margin:0 auto; }

/* ---------- hero ---------- */
.dt-hero{ position:relative; background-size:cover; background-position:center; background-color:#1a1a20; }
.dt-hero::after{ content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(10,10,13,0) 45%, var(--void) 100%); }
.dt-back-btn{
  position:absolute; top:18px; left:16px; z-index:3;
  width:38px; height:38px; border-radius:50%; background:rgba(10,10,13,.55); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff; display:flex; align-items:center; justify-content:center;
  text-decoration:none; font-size:.95rem;
}
.dt-action-btn{
  position:absolute; top:18px; z-index:3;
  width:38px; height:38px; border-radius:50%; background:rgba(10,10,13,.55); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff; display:flex; align-items:center; justify-content:center;
}
.dt-action-btn.share{ right:16px; }
.dt-action-btn.heart{ right:64px; }

/* ---------- content ---------- */
.dt-content{ padding:16px 18px 26px; position:relative; z-index:2; }
.dt-rating-pill{
  display:inline-flex; align-items:center; gap:5px; background:rgba(255,201,77,.14); border:1px solid rgba(255,201,77,.4);
  color:var(--yellow); font-size:12px; font-weight:800; padding:5px 11px; border-radius:100px; margin-bottom:10px;
}
.dt-eyebrow{
  display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:800; letter-spacing:.08em;
  text-transform:uppercase; color:var(--cyan, #3ddbe0); margin-bottom:8px;
}
.dt-eyebrow .dot{ width:6px; height:6px; border-radius:50%; background:var(--cyan, #3ddbe0); }
.dt-title{ font-size:24px; font-weight:800; line-height:1.15; color:var(--text); margin-bottom:6px; }
.dt-subline{ font-size:13.5px; color:var(--muted); display:flex; align-items:center; gap:6px; margin-bottom:4px; }
.dt-subline i{ color:var(--pink); }

/* ---------- stats row ---------- */
.dt-stats{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:18px 0 6px; }
.dt-stat{ background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:14px 8px; text-align:center; }
.dt-stat .num{ font-size:19px; font-weight:800; color:var(--pink); line-height:1; margin-bottom:4px; }
.dt-stat .lbl{ font-size:10.5px; color:var(--muted-2); font-weight:700; text-transform:uppercase; letter-spacing:.03em; }

/* ---------- section ---------- */
.dt-section{ margin-top:24px; }
.dt-section h3{ font-size:17px; font-weight:800; color:var(--text); margin-bottom:10px; }
.dt-body-text{ color:var(--muted); font-size:13.5px; line-height:1.7; }

/* ---------- social row ---------- */
.dt-social-row{ display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
.dt-social-chip{
  display:inline-flex; align-items:center; gap:6px; background:var(--panel-2); border:1px solid var(--line);
  border-radius:100px; padding:8px 14px; font-size:12px; font-weight:700; color:var(--text); text-decoration:none;
}

/* ---------- map ---------- */
.dt-map{ border-radius:14px; overflow:hidden; aspect-ratio:16/9; position:relative; background:#141416; border:1px solid var(--line); margin-top:10px; }
.dt-map iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; filter:grayscale(.15) contrast(1.05); }
.dt-map-cta{
  display:block; text-align:center; margin-top:10px; background:var(--panel-2); border:1px solid var(--line);
  color:var(--text); font-size:12.5px; font-weight:700; padding:11px; border-radius:100px; text-decoration:none;
}

/* ---------- stacked event card (venue / artist upcoming lists) ---------- */
.app-ev-card{ display:block; background:var(--panel); border:1px solid var(--line); border-radius:16px; overflow:hidden; margin-bottom:14px; text-decoration:none; color:var(--text); }
.app-ev-media{ height:150px; background-size:cover; background-position:center; position:relative; }
.app-ev-badge{ position:absolute; top:10px; left:10px; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; padding:5px 11px; border-radius:100px; letter-spacing:.02em; }
.app-ev-price{ position:absolute; top:10px; right:10px; font-size:10.5px; font-weight:800; padding:5px 11px; border-radius:100px; }
.app-ev-body{ padding:12px 14px; }
.app-ev-date{ font-size:11.5px; color:var(--cyan, #3ddbe0); font-weight:600; margin-bottom:6px; display:flex; align-items:center; gap:6px; }
.app-ev-title{ font-size:15px; font-weight:800; line-height:1.25; margin-bottom:6px; }
.app-ev-venue{ font-size:12px; color:var(--muted); display:flex; align-items:center; gap:6px; }

/* ---------- horizontal-scroll card row (similar venues/artists) ---------- */
.dt-hscroll{ display:flex; gap:12px; overflow-x:auto; scrollbar-width:none; margin:0 -18px; padding:0 18px; }
.dt-hscroll::-webkit-scrollbar{ display:none; }
.dt-mini-card{ flex:0 0 auto; width:150px; border-radius:14px; overflow:hidden; border:1px solid var(--line); background:var(--panel); text-decoration:none; color:var(--text); }
.dt-mini-media{ height:95px; background-size:cover; background-position:center; position:relative; }
.dt-mini-media .rating{ position:absolute; top:8px; left:8px; background:rgba(10,10,13,.7); color:var(--yellow); font-size:10px; font-weight:800; padding:4px 8px; border-radius:100px; }
.dt-mini-body{ padding:10px; }
.dt-mini-name{ font-size:12.5px; font-weight:800; margin-bottom:3px; }
.dt-mini-meta{ font-size:10.5px; color:var(--muted-2); }

.dt-empty{ color:var(--muted-2); font-size:13px; padding:8px 0; }
</style>
