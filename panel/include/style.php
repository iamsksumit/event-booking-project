<?php
/**
 * include/style.php
 * Shared CSS lifted verbatim from admin-dashboard.html / partner-dashboard.html
 * (the two are ~99% identical; this is the superset). Do NOT reintroduce the
 * old Dexign/Bootstrap-4 theme here - both panels now share this dark theme.
 * A few extra rules at the bottom (marked) add the notification + user dropdown
 * that the static mockups didn't need but a real logged-in app does.
 */
?>
<style>

:root{
  --void:      #0a0a0d;
  --panel:     #131317;
  --panel-2:   #1a1a1f;
  --panel-3:   #202027;
  --line:      #26262c;
  --pink:      #ff3d68;
  --orange:    #ff7a45;
  --yellow:    #ffc94d;
  --green:     #3ddc84;
  --blue:      #4d9fff;
  --text:      #f5f5f7;
  --muted:     #96969f;
  --muted-2:   #6f6f78;
  --sidebar-w: 264px;
}
*{ box-sizing:border-box; }
html,body{ height:100%; }
body{ background:var(--void); color:var(--text); font-family:'Inter',sans-serif; font-size:14.5px; overflow-x:hidden; }
a{ text-decoration:none; color:inherit; }
::selection{ background:var(--pink); color:#fff; }
h1,h2,h3,h4,h5,h6{ font-weight:800; letter-spacing:-0.02em; color:var(--text); margin:0; }
.grad-text{ background:linear-gradient(90deg,var(--orange),var(--pink)); -webkit-background-clip:text; background-clip:text; color:transparent; }
::-webkit-scrollbar{ width:8px; height:8px; }
::-webkit-scrollbar-thumb{ background:var(--line); border-radius:10px; }
::-webkit-scrollbar-track{ background:transparent; }

/* ===== Sidebar ===== */
.sidebar{
  position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w); z-index:40;
  background:linear-gradient(180deg,var(--panel) 0%, var(--void) 100%);
  border-right:1px solid var(--line); display:flex; flex-direction:column; transition:transform .25s ease;
}
.sidebar-brand{ display:flex; align-items:center; gap:.6rem; padding:1.35rem 1.4rem; border-bottom:1px solid var(--line); flex:none; }
.sidebar-brand img{ height:30px; width:auto; }
.sidebar-brand span{ font-weight:800; font-size:1.05rem; }
.sidebar-scroll{ flex:1; overflow-y:auto; padding:1rem .8rem 1.5rem; }
.nav-label{ font-size:.68rem; font-weight:800; letter-spacing:.08em; color:var(--muted-2); text-transform:uppercase; padding:1rem .6rem .4rem; }
.nav-item{ margin-bottom:.15rem; }
.nav-link-item{ display:flex; align-items:center; gap:.7rem; padding:.62rem .7rem; border-radius:10px; color:var(--muted); font-weight:600; font-size:.86rem; cursor:pointer; position:relative; }
.nav-link-item i.ic{ width:18px; text-align:center; font-size:.88rem; color:var(--muted-2); flex:none; }
.nav-link-item .chev{ margin-left:auto; font-size:.7rem; transition:transform .2s ease; color:var(--muted-2); }
.nav-link-item:hover{ background:var(--panel-2); color:var(--text); }
.nav-link-item:hover i.ic{ color:var(--pink); }
.nav-item.active > .nav-link-item{ background:var(--panel-2); color:var(--text); }
.nav-item.active > .nav-link-item i.ic{ color:var(--pink); }
.nav-item.open > .nav-link-item .chev{ transform:rotate(90deg); }
.submenu{ list-style:none; margin:0; padding:.2rem 0 .3rem 2.35rem; display:none; }
.nav-item.open .submenu{ display:block; }
.submenu li a{ display:block; padding:.44rem .5rem; font-size:.82rem; color:var(--muted); font-weight:600; border-radius:8px; border-left:2px solid var(--line); padding-left:.8rem; margin-bottom:.1rem; }
.submenu li a:hover, .submenu li a.active{ color:var(--text); border-left-color:var(--pink); background:var(--panel-2); }
.badge-count{ background:var(--pink); color:#fff; font-size:.62rem; font-weight:800; border-radius:99px; padding:.1rem .4rem; margin-left:.3rem; }
.sidebar-user{ border-top:1px solid var(--line); padding:1rem 1.2rem; display:flex; align-items:center; gap:.7rem; flex:none; }
.avatar-sm{ width:38px; height:38px; border-radius:11px; background:linear-gradient(135deg,var(--pink),var(--orange)); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.85rem; flex:none; color:#fff; }
.sidebar-user .u-name{ font-size:.82rem; font-weight:700; color:var(--text); }
.sidebar-user .u-role{ font-size:.7rem; color:var(--muted-2); font-weight:600; }

/* ===== Layout ===== */
.main-wrap{ margin-left:var(--sidebar-w); min-height:100vh; display:flex; flex-direction:column; }
.topbar{ position:sticky; top:0; z-index:30; display:flex; align-items:center; gap:1rem; padding:.9rem 1.8rem; background:rgba(10,10,13,.85); backdrop-filter:blur(10px); border-bottom:1px solid var(--line); }
.burger-btn{ display:none; background:var(--panel-2); border:1px solid var(--line); color:var(--text); width:38px; height:38px; border-radius:10px; align-items:center; justify-content:center; }
.search-pill{ flex:1; max-width:380px; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; display:flex; align-items:center; gap:.6rem; padding:.55rem .9rem; }
.search-pill i{ color:var(--muted-2); font-size:.85rem; }
.search-pill input{ background:transparent; border:none; outline:none; color:var(--text); font-size:.85rem; width:100%; }
.search-pill input::placeholder{ color:var(--muted-2); }
.topbar-actions{ margin-left:auto; display:flex; align-items:center; gap:.7rem; }
.icon-btn{ width:40px; height:40px; border-radius:10px; background:var(--panel-2); border:1px solid var(--line); display:flex; align-items:center; justify-content:center; color:var(--muted); position:relative; flex:none; }
.icon-btn:hover{ color:var(--text); border-color:var(--pink); }
.dot-alert{ position:absolute; top:7px; right:8px; width:7px; height:7px; border-radius:50%; background:var(--pink); border:2px solid var(--void); }
.topbar-user{ display:flex; align-items:center; gap:.6rem; padding:.3rem .7rem .3rem .3rem; border-radius:12px; border:1px solid var(--line); background:var(--panel-2); }
.topbar-user .u-name{ font-size:.82rem; font-weight:700; }
.topbar-user .u-role{ font-size:.68rem; color:var(--muted-2); font-weight:600; }
.btn-pink-sm{ background:var(--pink); border:none; color:#fff; font-weight:700; font-size:.8rem; padding:.55rem 1rem; border-radius:10px; display:inline-flex; align-items:center; gap:.4rem; }
.btn-pink-sm:hover{ filter:brightness(1.1); color:#fff; }

.content{ padding:1.7rem 1.8rem 3rem; }
.page-head{ display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.6rem; }
.page-head h2{ font-size:1.5rem; margin-bottom:.2rem; }
.page-head p{ color:var(--muted); font-size:.85rem; margin:0; }

/* Welcome banner */
.welcome-banner{
  border-radius:18px; padding:1.8rem 2rem; margin-bottom:1.6rem; position:relative; overflow:hidden;
  background:linear-gradient(120deg, rgba(255,61,104,.16), rgba(255,122,69,.1) 60%, transparent), var(--panel);
  border:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1.2rem;
}
.welcome-banner h3{ font-size:1.35rem; margin-bottom:.35rem; }
.welcome-banner p{ color:var(--muted); font-size:.85rem; max-width:480px; margin:0; }
.plan-chip{ display:inline-flex; align-items:center; gap:.4rem; background:var(--panel-2); border:1px solid var(--line); border-radius:99px; padding:.35rem .8rem; font-size:.72rem; font-weight:700; color:var(--yellow); margin-bottom:.7rem; }

.range-tabs{ display:flex; gap:.35rem; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.3rem; }
.range-tabs button{ background:transparent; border:none; color:var(--muted); font-weight:700; font-size:.78rem; padding:.45rem .85rem; border-radius:8px; }
.range-tabs button.active{ background:var(--pink); color:#fff; }

.stat-card{ background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:1.3rem 1.4rem; height:100%; position:relative; overflow:hidden; }
.stat-card .ic-wrap{ width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.05rem; margin-bottom:1rem; color:#fff; }
.stat-card .val{ font-size:1.65rem; font-weight:800; letter-spacing:-.02em; }
.stat-card .lbl{ color:var(--muted); font-size:.8rem; font-weight:600; margin-top:.15rem; }
.stat-card .delta{ font-size:.74rem; font-weight:700; margin-top:.7rem; display:inline-flex; align-items:center; gap:.3rem; }
.delta.up{ color:var(--green); }
.delta.down{ color:var(--pink); }

.panel-card{ background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:1.4rem 1.5rem; }
.panel-card .head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:1.1rem; }
.panel-card .head h5{ font-size:1rem; }
.panel-card .head .sub{ color:var(--muted-2); font-size:.76rem; font-weight:600; }
.link-more{ color:var(--pink); font-size:.78rem; font-weight:700; }

.table-clean{ width:100%; border-collapse:separate; border-spacing:0; }
.table-clean thead th{ text-align:left; font-size:.68rem; letter-spacing:.06em; text-transform:uppercase; color:var(--muted-2); font-weight:800; padding:.5rem .7rem; border-bottom:1px solid var(--line); }
.table-clean tbody td{ padding:.8rem .7rem; border-bottom:1px solid var(--line); font-size:.84rem; vertical-align:middle; }
.table-clean tbody tr:last-child td{ border-bottom:none; }
.table-clean tbody tr:hover{ background:var(--panel-2); }
.cell-title{ font-weight:700; color:var(--text); }
.cell-sub{ color:var(--muted-2); font-size:.75rem; }
.thumb{ width:38px; height:38px; border-radius:9px; object-fit:cover; flex:none; }

.badge-pill{ font-size:.7rem; font-weight:800; padding:.28rem .65rem; border-radius:99px; display:inline-block; }
.badge-live{ background:rgba(61,220,132,.14); color:var(--green); }
.badge-pending{ background:rgba(255,201,77,.14); color:var(--yellow); }
.badge-past{ background:rgba(150,150,159,.14); color:var(--muted); }
.badge-draft{ background:rgba(77,159,255,.14); color:var(--blue); }
.badge-paid{ background:rgba(61,220,132,.14); color:var(--green); }
.badge-processing{ background:rgba(77,159,255,.14); color:var(--blue); }

.btn-mini{ border:1px solid var(--line); background:var(--panel-2); color:var(--text); font-size:.72rem; font-weight:700; padding:.35rem .65rem; border-radius:8px; }
.btn-mini:hover{ border-color:var(--pink); color:var(--pink); }

.wallet-card{ background:linear-gradient(135deg, var(--panel-2), var(--panel-3)); border:1px solid var(--line); border-radius:16px; padding:1.5rem; height:100%; }
.wallet-card .amt{ font-size:2rem; font-weight:800; letter-spacing:-.02em; margin:.4rem 0 1.1rem; }

.promo-card{ border:1px solid var(--line); border-radius:14px; padding:1rem 1.1rem; margin-bottom:.8rem; background:var(--panel-2); }
.promo-card:last-child{ margin-bottom:0; }
.progress-thin{ height:6px; background:var(--panel-3); border-radius:99px; overflow:hidden; margin-top:.5rem; }
.progress-thin > span{ display:block; height:100%; border-radius:99px; background:linear-gradient(90deg,var(--orange),var(--pink)); }

.review-row{ display:flex; gap:.7rem; padding:.75rem 0; border-bottom:1px solid var(--line); }
.review-row:last-child{ border-bottom:none; }
.stars{ color:var(--yellow); font-size:.72rem; }

@media (max-width: 1199.98px){ :root{ --sidebar-w:250px; } }
@media (max-width: 991.98px){
  .sidebar{ transform:translateX(-100%); }
  .sidebar.show{ transform:translateX(0); }
  .main-wrap{ margin-left:0; }
  .burger-btn{ display:flex; }
  .search-pill{ display:none; }
}

/* ===== Modals (dark themed) ===== */
.modal-content{ background:var(--panel); border:1px solid var(--line); border-radius:18px; color:var(--text); }
.modal-header{ border-bottom:1px solid var(--line); padding:1.3rem 1.5rem; }
.modal-header h5{ font-size:1.1rem; }
.modal-body{ padding:1.5rem; }
.modal-footer{ border-top:1px solid var(--line); padding:1.1rem 1.5rem; }
.btn-close{ filter:invert(1) grayscale(1); opacity:.7; }
.step-track{ display:flex; align-items:center; gap:.5rem; margin-bottom:1.4rem; }
.step-track .seg{ height:4px; flex:1; background:var(--line); border-radius:99px; }
.step-track .seg.done{ background:var(--pink); }
.type-select-card{
  border:1.5px solid var(--line); background:var(--panel-2); border-radius:14px; padding:1.2rem;
  cursor:pointer; height:100%; transition:border-color .15s ease, background .15s ease;
}
.type-select-card:hover{ border-color:#3a3a42; }
.type-select-card input{ display:none; }
.type-select-card .ic-wrap{ width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1rem;color:#fff;margin-bottom:.8rem; }
.type-select-card h6{ font-size:.92rem; margin-bottom:.25rem; }
.type-select-card p{ font-size:.76rem; color:var(--muted); margin:0; }
.type-select-card.selected{ border-color:var(--pink); background:rgba(255,61,104,.08); }
.form-label-c{ font-size:.78rem; font-weight:700; color:var(--muted); margin-bottom:.45rem; display:block; }
.form-control-c{
  background:var(--panel-2); border:1px solid var(--line); border-radius:10px; color:var(--text);
  padding:.7rem .9rem; font-size:.88rem; width:100%;
}
.form-control-c:focus{ outline:none; border-color:var(--pink); background:var(--panel-2); color:var(--text); box-shadow:none; }
.form-control-c::placeholder{ color:var(--muted-2); }

/* Scanner modal */
.scan-frame{
  aspect-ratio:1/1; max-width:280px; margin:0 auto; border-radius:20px; background:var(--void);
  border:1px solid var(--line); position:relative; overflow:hidden; display:flex; align-items:center; justify-content:center;
}
.scan-frame .corner{ position:absolute; width:34px; height:34px; border:3px solid var(--pink); }
.scan-frame .tl{ top:16px; left:16px; border-right:none; border-bottom:none; border-radius:8px 0 0 0; }
.scan-frame .tr{ top:16px; right:16px; border-left:none; border-bottom:none; border-radius:0 8px 0 0; }
.scan-frame .bl{ bottom:16px; left:16px; border-right:none; border-top:none; border-radius:0 0 0 8px; }
.scan-frame .br{ bottom:16px; right:16px; border-left:none; border-top:none; border-radius:0 0 8px 0; }
.scan-frame .beam{
  position:absolute; left:16px; right:16px; height:2px; background:linear-gradient(90deg,transparent,var(--pink),transparent);
  animation:scanmove 2.2s ease-in-out infinite;
}
@keyframes scanmove{ 0%{ top:20%; } 50%{ top:78%; } 100%{ top:20%; } }
.scan-frame i{ font-size:2.4rem; color:var(--muted-2); }
.scan-divider{ display:flex; align-items:center; gap:.8rem; margin:1.3rem 0; color:var(--muted-2); font-size:.72rem; font-weight:700; }
.scan-divider::before, .scan-divider::after{ content:""; flex:1; height:1px; background:var(--line); }
.scan-stat{ text-align:center; }
.scan-stat b{ display:block; font-size:1.3rem; font-weight:800; }
.scan-stat span{ font-size:.7rem; color:var(--muted-2); font-weight:600; }

/* Toast */
.toast-c{
  position:fixed; bottom:1.6rem; right:1.6rem; z-index:1080; background:var(--panel); border:1px solid var(--line);
  border-left:3px solid var(--green); border-radius:12px; padding:.9rem 1.2rem; display:flex; align-items:center; gap:.7rem;
  box-shadow:0 20px 40px rgba(0,0,0,.4); transform:translateY(20px); opacity:0; pointer-events:none; transition:all .25s ease;
  max-width:320px;
}
.toast-c.show{ transform:translateY(0); opacity:1; }
.toast-c i{ color:var(--green); font-size:1.1rem; }
.toast-c .t-title{ font-weight:700; font-size:.85rem; }
.toast-c .t-sub{ font-size:.75rem; color:var(--muted); }

/* =====================================================================
   Added for the live app (not in the original static mockups):
   notification bell dropdown + top-right user/account dropdown.
   Kept in the same visual language as the rest of the theme above.
   ===================================================================== */
.dropdown-wrap{ position:relative; }
.dropdown-panel{
  position:absolute; top:calc(100% + 10px); right:0; width:340px; max-height:420px; overflow-y:auto;
  background:var(--panel); border:1px solid var(--line); border-radius:14px; box-shadow:0 20px 50px rgba(0,0,0,.5);
  z-index:60; display:none;
}
.dropdown-panel.show{ display:block; }
.dropdown-panel .dp-head{ display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.1rem; border-bottom:1px solid var(--line); }
.dropdown-panel .dp-head h6{ font-size:.85rem; }
.dropdown-panel .dp-head a{ font-size:.72rem; color:var(--pink); font-weight:700; }
.notif-item{ display:flex; gap:.7rem; padding:.85rem 1.1rem; border-bottom:1px solid var(--line); }
.notif-item:last-child{ border-bottom:none; }
.notif-item .n-dot{ width:8px; height:8px; border-radius:50%; background:var(--pink); margin-top:.4rem; flex:none; }
.notif-item.read .n-dot{ background:var(--line); }
.notif-item .n-title{ font-size:.82rem; font-weight:700; color:var(--text); }
.notif-item .n-msg{ font-size:.76rem; color:var(--muted); margin-top:.1rem; }
.notif-item .n-time{ font-size:.68rem; color:var(--muted-2); margin-top:.3rem; font-weight:600; }
.dropdown-panel .dp-empty{ padding:2rem 1rem; text-align:center; color:var(--muted-2); font-size:.82rem; }
.user-menu{ width:220px; padding:.5rem; }
.user-menu a{ display:flex; align-items:center; gap:.6rem; padding:.6rem .7rem; border-radius:9px; font-size:.83rem; font-weight:600; color:var(--muted); }
.user-menu a:hover{ background:var(--panel-2); color:var(--text); }
.user-menu a.danger{ color:var(--pink); }
.user-menu hr{ border-color:var(--line); margin:.4rem 0; }
</style>
