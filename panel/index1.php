<?php
include 'include/config.php';
?>
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Partner With Highspinn — List Your Venue, Fill The Room</title>
<meta name="description" content="List your venue on Highspinn, manage guest lists and check-ins, and reach thousands of people looking for their next night out.">

<link rel="shortcut icon" href="/img/favicon.ico" type="image/x-icon">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
:root{
  --void:      #0a0a0d;
  --panel:     #131317;
  --panel-2:   #1a1a1f;
  --line:      #26262c;
  --pink:      #ff3d68;
  --orange:    #ff7a45;
  --yellow:    #ffc94d;
  --green:     #3ddc84;
  --text:      #f5f5f7;
  --muted:     #96969f;
  --muted-2:   #6f6f78;
}
*{ box-sizing:border-box; }
html{ scroll-behavior:smooth; }
body{
  background:var(--void);
  color:var(--text);
  font-family:'Inter',sans-serif;
  font-size:15px;
  position:relative;
  overflow-x:hidden;
}
a{ text-decoration:none; color:inherit; }
::selection{ background:var(--pink); color:#fff; }
h1,h2,h3,h4{ font-family:'Sora',sans-serif; font-weight:800; letter-spacing:-0.02em; color:var(--text); }
.eyebrow{ font-size:.72rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); }

.grad-text{
  background:linear-gradient(90deg,var(--orange),var(--pink));
  -webkit-background-clip:text; background-clip:text; color:transparent;
}

/* ambient glows, same language as register */
.glow-tr{ position:fixed; top:-220px; right:-160px; width:560px; height:560px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,61,104,.16), transparent 70%); pointer-events:none; z-index:0; }
.glow-bl{ position:fixed; bottom:-260px; left:-180px; width:520px; height:520px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,122,69,.11), transparent 70%); pointer-events:none; z-index:0; }

.container-xl{ max-width:1180px; margin:0 auto; padding:0 1.5rem; position:relative; z-index:1; }

/* ---------- NAV ---------- */
.navbar-hs{
  position:sticky; top:0; z-index:50; background:rgba(10,10,13,.82); backdrop-filter:blur(10px);
  border-bottom:1px solid var(--line);
}
.navbar-hs .inner{ display:flex; align-items:center; justify-content:space-between; padding:1rem 0; }
.brand-mark{ display:inline-flex; align-items:center; gap:.6rem; font-weight:800; font-size:1.15rem; font-family:'Sora',sans-serif; }
.brand-mark img{ height:30px; width:auto; }
.nav-links{ display:flex; align-items:center; gap:2rem; }
.nav-links a{ font-size:.85rem; font-weight:600; color:var(--muted); transition:color .15s; }
.nav-links a:hover{ color:var(--text); }
.nav-cta{ display:flex; align-items:center; gap:.9rem; }
.btn-ghost{ font-size:.85rem; font-weight:700; color:var(--text); padding:.55rem 1rem; }
.btn-pink{
  background:var(--pink); border:none; color:#fff; font-weight:700;
  padding:.7rem 1.3rem; border-radius:10px; font-size:.85rem;
  display:inline-flex; align-items:center; gap:.5rem;
  transition:filter .2s ease, transform .2s ease;
}
.btn-pink:hover{ filter:brightness(1.1); transform:translateY(-1px); color:#fff; }
.btn-outline{
  background:transparent; border:1px solid var(--line); color:var(--text); font-weight:700;
  padding:.7rem 1.3rem; border-radius:10px; font-size:.55rem;
  display:inline-flex; align-items:center; gap:.5rem; transition:border-color .15s, background .15s;
}
.btn-outline:hover{ border-color:var(--pink); background:rgba(255,61,104,.06); color:var(--text); }
.navToggle{ display:none; background:none; border:1px solid var(--line); border-radius:8px; color:var(--text); width:38px; height:38px; flex:none; }

/* ---------- OFF-CANVAS MOBILE MENU ---------- */
.offcanvas-overlay{
  position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(2px);
  opacity:0; pointer-events:none; transition:opacity .25s ease; z-index:90;
}
.offcanvas-overlay.show{ opacity:1; pointer-events:auto; }
.offcanvas-panel{
  position:fixed; top:0; right:0; height:100%; width:min(320px,84vw);
  background:var(--panel); border-left:1px solid var(--line);
  padding:1.5rem 1.5rem 2rem; z-index:91;
  transform:translateX(100%); transition:transform .3s ease;
  display:flex; flex-direction:column; overflow-y:auto;
}
.offcanvas-panel.show{ transform:translateX(0); }
.offcanvas-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:2rem; }
.offcanvas-close{ background:none; border:1px solid var(--line); border-radius:8px; color:var(--text); width:34px; height:34px; }
.offcanvas-links{ display:flex; flex-direction:column; gap:1.4rem; margin-bottom:2rem; }
.offcanvas-links a{ font-family:'Sora',sans-serif; font-weight:700; font-size:1.05rem; color:var(--text); }
.offcanvas-actions{ display:flex; flex-direction:column; gap:.8rem; margin-top:auto; }
.offcanvas-actions .btn-outline, .offcanvas-actions .btn-pink{ justify-content:center; width:100%; }
body.offcanvas-open{ overflow:hidden; }

/* ---------- HERO ---------- */
.hero{ padding:5.5rem 0 4rem; position:relative; }
.hero-pill{
  display:inline-flex; align-items:center; gap:.5rem; background:var(--panel-2); border:1px solid var(--line);
  border-radius:999px; padding:.4rem 1rem; font-size:.75rem; font-weight:700; color:var(--muted); margin-bottom:1.4rem;
}
.hero-pill .dot{ width:6px; height:6px; border-radius:50%; background:var(--pink); display:inline-block; }
.hero h1{ font-size:clamp(2.4rem,5vw,3.9rem); line-height:1.08; max-width:820px; margin-bottom:1.3rem; }
.hero p.lead-c{ color:var(--muted); font-size:1.05rem; max-width:560px; margin-bottom:2rem; }
.hero-actions{ display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:3rem; }
.btn-lg{ padding:.9rem 1.7rem; font-size:.92rem; border-radius:12px; }

.stat-strip{ display:flex; gap:0; border:1px solid var(--line); border-radius:16px; overflow:hidden; background:var(--panel); max-width:720px; }
.stat-cell{ flex:1; padding:1.3rem 1.5rem; border-right:1px solid var(--line); position:relative; }
.stat-cell:last-child{ border-right:none; }
.stat-cell b{ display:block; font-family:'Sora',sans-serif; font-size:1.7rem; font-weight:800; }
.stat-cell span{ font-size:.72rem; color:var(--muted-2); font-weight:600; text-transform:uppercase; letter-spacing:.06em; }

/* ---------- MARQUEE (signature element: scrolling event-type ticket strip) ---------- */
.marquee-wrap{ border-top:1px solid var(--line); border-bottom:1px solid var(--line); background:var(--panel); overflow:hidden; position:relative; z-index:1; }
.marquee-track{ display:flex; width:max-content; animation:scrollX 32s linear infinite; }
.marquee-track:hover{ animation-play-state:paused; }
.ticket{
  display:flex; align-items:center; gap:.65rem; padding:1.1rem 2rem; white-space:nowrap;
  border-right:1px dashed var(--line); font-family:'Sora',sans-serif; font-weight:700; font-size:.95rem; color:var(--text);
}
.ticket i{ color:var(--pink); font-size:.8rem; }
@keyframes scrollX{ from{ transform:translateX(0); } to{ transform:translateX(-50%); } }

/* ---------- SECTION SHARED ---------- */
.section{ padding:5rem 0; position:relative; z-index:1; }
.section-head{ max-width:640px; margin-bottom:3rem; }
.section-head h2{ font-size:clamp(1.7rem,3vw,2.4rem); margin:.6rem 0 .8rem; }
.section-head p{ color:var(--muted); font-size:.98rem; }

/* ---------- STEPS (real sequence -> numbered) ---------- */
.steps-row{ display:grid; grid-template-columns:repeat(3,1fr); gap:1.5rem; }
.step-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:2rem 1.8rem; position:relative; }
.step-num{
  font-family:'Sora',sans-serif; font-size:.78rem; font-weight:800; color:var(--pink);
  border:1px solid rgba(255,61,104,.35); background:rgba(255,61,104,.08); border-radius:999px;
  padding:.3rem .8rem; display:inline-block; margin-bottom:1.1rem;
}
.step-card h4{ font-size:1.15rem; margin-bottom:.6rem; }
.step-card p{ color:var(--muted); font-size:.9rem; line-height:1.55; margin:0; }
.step-arrow{ position:absolute; top:2rem; right:-1.9rem; color:var(--muted-2); font-size:1.1rem; z-index:2; }
@media (max-width:991.98px){ .step-arrow{ display:none; } }

/* ---------- FEATURE GRID ---------- */
.feat-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:1.4rem; }
.feat-card{
  background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:1.9rem 1.7rem;
  transition:border-color .2s, transform .2s;
}
.feat-card:hover{ border-color:rgba(255,61,104,.35); transform:translateY(-3px); }
.feat-icon{
  width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center;
  font-size:1.05rem; margin-bottom:1.1rem; color:#fff;
}
.feat-card h4{ font-size:1.05rem; margin-bottom:.5rem; }
.feat-card p{ color:var(--muted); font-size:.88rem; line-height:1.55; margin:0; }

/* ---------- WHY / BIG STATS BAND ---------- */
.band{
  border-radius:24px; border:1px solid var(--line);
  background:linear-gradient(160deg, var(--panel) 0%, var(--void) 100%);
  padding:3.2rem 2.8rem; position:relative; overflow:hidden;
}
.band::before{ content:""; position:absolute; top:-140px; right:-100px; width:360px; height:360px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,201,77,.14), transparent 70%); pointer-events:none; }
.band-grid{ display:grid; grid-template-columns:1.1fr .9fr; gap:2.5rem; align-items:center; position:relative; z-index:1; }
.band ul{ list-style:none; padding:0; margin:1.6rem 0 0; display:grid; gap:1rem; }
.band ul li{ display:flex; gap:.8rem; align-items:flex-start; color:var(--muted); font-size:.92rem; line-height:1.5; }
.band ul li i{ color:var(--green); margin-top:.25rem; }
.band-stats{ display:grid; grid-template-columns:1fr 1fr; gap:1.2rem; }
.band-stat{ background:var(--panel-2); border:1px solid var(--line); border-radius:16px; padding:1.4rem 1.3rem; }
.band-stat b{ display:block; font-family:'Sora',sans-serif; font-size:2rem; font-weight:800; }
.band-stat span{ font-size:.78rem; color:var(--muted-2); font-weight:600; }
@media (max-width:991.98px){ .band-grid{ grid-template-columns:1fr; } }

/* ---------- FAQ ---------- */
.faq-item{ border-bottom:1px solid var(--line); }
.faq-q{
  width:100%; text-align:left; background:none; border:none; color:var(--text);
  padding:1.4rem .2rem; font-family:'Sora',sans-serif; font-weight:700; font-size:1rem;
  display:flex; align-items:center; justify-content:space-between; gap:1rem; cursor:pointer;
}
.faq-q .fa-plus{ color:var(--pink); transition:transform .25s ease; flex:none; }
.faq-item.open .faq-q .fa-plus{ transform:rotate(45deg); }
.faq-a{ max-height:0; overflow:hidden; transition:max-height .3s ease; }
.faq-a-inner{ padding:0 .2rem 1.4rem; color:var(--muted); font-size:.9rem; line-height:1.65; max-width:760px; }

/* ---------- FINAL CTA ---------- */
.cta-band{
  border-radius:24px; padding:3.5rem 2.5rem; text-align:center;
  background:linear-gradient(135deg, rgba(255,61,104,.14), rgba(255,122,69,.10));
  border:1px solid rgba(255,61,104,.28); position:relative; overflow:hidden;
}
.cta-band h2{ font-size:clamp(1.6rem,3vw,2.2rem); margin-bottom:.8rem; }
.cta-band p{ color:var(--muted); max-width:480px; margin:0 auto 1.8rem; }

/* ---------- FOOTER ---------- */
footer{ border-top:1px solid var(--line); padding:3rem 0 2rem; position:relative; z-index:1; }
.footer-grid{ display:grid; grid-template-columns:1.4fr 1fr 1fr 1fr; gap:2rem; margin-bottom:2.2rem; }
.footer-grid h6{ font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted-2); margin-bottom:1rem; }
.footer-grid ul{ list-style:none; padding:0; margin:0; display:grid; gap:.65rem; }
.footer-grid ul li a{ color:var(--muted); font-size:.87rem; }
.footer-grid ul li a:hover{ color:var(--pink); }
.footer-bottom{ display:flex; justify-content:space-between; align-items:center; padding-top:1.6rem; border-top:1px solid var(--line); color:var(--muted-2); font-size:.8rem; flex-wrap:wrap; gap:1rem; }
.social-row{ display:flex; gap:.8rem; }
.social-row a{ width:34px; height:34px; border-radius:9px; border:1px solid var(--line); display:flex; align-items:center; justify-content:center; color:var(--muted); transition:.15s; }
.social-row a:hover{ color:var(--pink); border-color:rgba(255,61,104,.4); }

@media (max-width: 991.98px){
  .nav-links{ display:none; }
  .navToggle{ display:inline-flex; align-items:center; justify-content:center; }
  .nav-cta{ gap:.5rem; }
  .nav-cta .btn-ghost, .nav-cta .btn-pink{ padding:.6rem .85rem; font-size:.58rem; }
  .steps-row, .feat-grid{ grid-template-columns:1fr; }
  .footer-grid{ grid-template-columns:1fr 1fr; }
  .stat-strip{ flex-wrap:wrap; }
  .stat-cell{ flex:0 0 50%; border-bottom:1px solid var(--line); }
}
@media (max-width: 575.98px){
  .footer-grid{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div class="glow-tr"></div>
<div class="glow-bl"></div>

<!-- ================= NAV ================= -->
<nav class="navbar-hs">
  <div class="container-xl inner">
    <a href="/" class="brand-mark">
      <img src="https://panel.highspinn.com/images/logo.png" alt="Highspinn">
      
    </a>
    <div class="nav-links">
      <a href="#how-it-works">How it works</a>
      <a href="#features">What you get</a>
      <a href="#why">Why partner</a>
      <a href="#faq">FAQ</a>
    </div>
    <div class="nav-cta">
      <a href="login" class="btn-outline">Sign in</a>
      <a href="register" class="btn-pink">Become a partner <i class="fa-solid fa-arrow-right"></i></a>
      <button class="navToggle d-lg-none" id="navToggleBtn" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
    </div>
  </div>
</nav>

<!-- ================= OFF-CANVAS MOBILE MENU ================= -->
<div class="offcanvas-overlay" id="offcanvasOverlay"></div>
<div class="offcanvas-panel" id="offcanvasPanel">
  <div class="offcanvas-head">
    <a href="/" class="brand-mark">
      <img src="https://panel.highspinn.com/images/logo.png" alt="Highspinn">
      
    </a>
    <button class="offcanvas-close" id="offcanvasClose" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="offcanvas-links">
    <a href="#how-it-works" class="offcanvas-link">How it works</a>
    <a href="#features" class="offcanvas-link">What you get</a>
    <a href="#why" class="offcanvas-link">Why partner</a>
    <a href="#faq" class="offcanvas-link">FAQ</a>
  </div>
  <div class="offcanvas-actions">
    <a href="login" class="btn-outline">Sign in</a>
    <a href="register" class="btn-pink">Become a partner <i class="fa-solid fa-arrow-right"></i></a>
  </div>
</div>

<!-- ================= HERO ================= -->
<header class="hero">
  <div class="container-xl">
    <span class="hero-pill"><span class="dot"></span> Highspinn Partner Panel</span>
    <h1>Bring your venue,<br><span class="grad-text">fill the room.</span></h1>
    <p class="lead-c">List your nights, manage guest lists and check-ins, and get discovered by a crowd that's already out looking for somewhere to be tonight.</p>
    <div class="hero-actions">
      <a href="register" class="btn-pink btn-lg">Register your venue <i class="fa-solid fa-arrow-right"></i></a>
      <a href="#how-it-works" class="btn-outline btn-lg">See how it works</a>
    </div>
    <div class="stat-strip">
      <div class="stat-cell"><b>50K+</b><span>Active users</span></div>
      <div class="stat-cell"><b>80+</b><span>Partner venues</span></div>
      <div class="stat-cell"><b>1.2K+</b><span>Events hosted</span></div>
      <div class="stat-cell"><b>4.7★</b><span>Avg. partner rating</span></div>
    </div>
  </div>
</header>

<!-- ================= MARQUEE ================= -->
<div class="marquee-wrap">
  <div class="marquee-track" id="marqueeTrack">
    <!-- content duplicated via JS for seamless loop -->
  </div>
</div>

<!-- ================= HOW IT WORKS ================= -->
<section class="section" id="how-it-works">
  <div class="container-xl">
    <div class="section-head">
      <span class="eyebrow">How it works</span>
      <h2>From sign-up to sold out, in three steps.</h2>
      <p>No onboarding calls, no paperwork back-and-forth. Verify your number and you can have your first event live today.</p>
    </div>
    <div class="steps-row">
      <div class="step-card">
        <span class="step-num">Step 1</span>
        <h4>Register &amp; verify</h4>
        <p>Sign up with your mobile number, get your OTP, and add your venue and account details. Takes under two minutes.</p>
        <i class="fa-solid fa-arrow-right step-arrow"></i>
      </div>
      <div class="step-card">
        <span class="step-num">Step 2</span>
        <h4>List your event</h4>
        <p>Add your event details, ticket tiers, and guestlist options for couples, stags, or open entry. Publish when you're ready.</p>
        <i class="fa-solid fa-arrow-right step-arrow"></i>
      </div>
      <div class="step-card">
        <span class="step-num">Step 3</span>
        <h4>Sell &amp; check in</h4>
        <p>Tickets go live to the Highspinn audience. Track sales in real time and check guests in at the door from the app.</p>
      </div>
    </div>
  </div>
</section>

<!-- ================= FEATURES ================= -->
<section class="section" id="features">
  <div class="container-xl">
    <div class="section-head">
      <span class="eyebrow">What you get</span>
      <h2>Everything to run the night, not just the ticket link.</h2>
      <p>The partner panel is built around the actual job of running a venue — the guest list, the door, the payout.</p>
    </div>
    <div class="feat-grid">
      <div class="feat-card">
        <div class="feat-icon" style="background:linear-gradient(135deg,var(--pink),var(--orange));"><i class="fa-solid fa-list-check"></i></div>
        <h4>Guest list management</h4>
        <p>Build and edit guest lists per event, with separate options for couples, stags, and group entries.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon" style="background:linear-gradient(135deg,var(--orange),var(--yellow));"><i class="fa-solid fa-qrcode"></i></div>
        <h4>Check-in from the app</h4>
        <p>Scan tickets and check attendees in directly from the organizer app — no separate door hardware needed.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon" style="background:linear-gradient(135deg,var(--yellow),var(--green));"><i class="fa-solid fa-bullhorn"></i></div>
        <h4>Marketing &amp; promotion</h4>
        <p>Your events get placed in front of Highspinn's audience of party-goers already browsing what's on tonight.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon" style="background:linear-gradient(135deg,var(--green),#2bb5e0);"><i class="fa-solid fa-chart-line"></i></div>
        <h4>Real-time sales dashboard</h4>
        <p>Watch ticket sales, guest list fill, and revenue update live as your event goes out to the crowd.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon" style="background:linear-gradient(135deg,#2bb5e0,var(--pink));"><i class="fa-solid fa-code"></i></div>
        <h4>Web integration API</h4>
        <p>Sell tickets on your own site with Highspinn's web integration, and still manage everything from one panel.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon" style="background:linear-gradient(135deg,var(--pink),var(--yellow));"><i class="fa-solid fa-headset"></i></div>
        <h4>Dedicated partner support</h4>
        <p>A support line for partners — not a ticket queue — when something needs fixing before doors open.</p>
      </div>
    </div>
  </div>
</section>

<!-- ================= WHY PARTNER ================= -->
<section class="section" id="why">
  <div class="container-xl">
    <div class="band">
      <div class="band-grid">
        <div>
          <span class="eyebrow">Why partner with Highspinn</span>
          <h2 style="margin-top:.6rem;">Reach the crowd that's already looking for a night out.</h2>
          <ul>
            <li><i class="fa-solid fa-circle-check"></i> Built for nightlife — DJ shows, Bollywood nights, karaoke, open-mic and more, not generic ticketing.</li>
            <li><i class="fa-solid fa-circle-check"></i> Guest list and check-in tools made for how doors actually run.</li>
            <li><i class="fa-solid fa-circle-check"></i> Transparent payouts with no surprise deductions.</li>
            <li><i class="fa-solid fa-circle-check"></i> Free web integration if you'd rather sell from your own site.</li>
          </ul>
        </div>
        <div class="band-stats">
          <div class="band-stat"><b>50K+</b><span>Active users on Highspinn</span></div>
          <div class="band-stat"><b>80+</b><span>Venues already partnered</span></div>
          <div class="band-stat"><b>1.2K+</b><span>Events hosted to date</span></div>
          <div class="band-stat"><b>0%</b><span>Cost to list your event</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= FAQ ================= -->
<section class="section" id="faq">
  <div class="container-xl">
    <div class="section-head">
      <span class="eyebrow">FAQ</span>
      <h2>Questions partners ask us</h2>
      <p>Can't find what you're looking for? Reach out to the partner support line from your dashboard once you're signed in.</p>
    </div>
    <div class="faq-list" style="max-width:820px;">

      <div class="faq-item open">
        <button class="faq-q"><span>Is it free to list my venue and events?</span><i class="fa-solid fa-plus"></i></button>
        <div class="faq-a"><div class="faq-a-inner">Yes. Creating a partner account and listing events costs nothing. Highspinn takes a small service fee only on tickets actually sold, so there's no upfront cost to try it out.</div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q"><span>How do I register as a partner?</span><i class="fa-solid fa-plus"></i></button>
        <div class="faq-a"><div class="faq-a-inner">Tap "Become a partner," verify your mobile number with an OTP, and fill in your name, email, city, and a password. Once that's done you land straight in the partner panel and can create your first event.</div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q"><span>How fast do I get paid out?</span><i class="fa-solid fa-plus"></i></button>
        <div class="faq-a"><div class="faq-a-inner">Payouts are processed on a regular settlement cycle after each event, straight to the bank account on your partner profile. You can track pending and completed payouts from the dashboard at any time.</div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q"><span>Can I manage the guest list and check guests in myself?</span><i class="fa-solid fa-plus"></i></button>
        <div class="faq-a"><div class="faq-a-inner">Yes. You can add and edit guest list entries per event — including separate options for couples, stags, and groups — and check attendees in at the door using the organizer app's QR scanner.</div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q"><span>What kinds of events can I list?</span><i class="fa-solid fa-plus"></i></button>
        <div class="faq-a"><div class="faq-a-inner">Highspinn is built for nightlife and live experiences — DJ nights, Bollywood nights, karaoke and open-mic evenings, and similar events. If you're unsure whether your event fits, reach out to partner support before you list.</div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q"><span>Can I sell tickets from my own website?</span><i class="fa-solid fa-plus"></i></button>
        <div class="faq-a"><div class="faq-a-inner">Yes, through Highspinn's free web integration API. Tickets sold on your own site still flow into the same partner dashboard alongside sales from the Highspinn app.</div></div>
      </div>

      <div class="faq-item">
        <button class="faq-q"><span>I forgot my partner account password. What now?</span><i class="fa-solid fa-plus"></i></button>
        <div class="faq-a"><div class="faq-a-inner">Use "Forgot password" on the sign-in page and verify with an OTP sent to your registered mobile number to set a new one.</div></div>
      </div>

    </div>
  </div>
</section>

<!-- ================= FINAL CTA ================= -->
<section class="section" style="padding-top:0;">
  <div class="container-xl">
    <div class="cta-band">
      <span class="eyebrow">Get started</span>
      <h2 style="margin-top:.6rem;">Ready to fill the room?</h2>
      <p>Set up your partner account in a couple of minutes and get your first event listed today.</p>
      <a href="register" class="btn-pink btn-lg">Register your venue <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ================= FOOTER ================= -->
<footer>
  <div class="container-xl">
    <div class="footer-grid">
      <div>
        <a href="/" class="brand-mark" style="margin-bottom:1rem; display:inline-flex;">
          <img src="https://panel.highspinn.com/images/logo.png" alt="Highspinn">
          Highspinn
        </a>
        <p style="color:var(--muted); font-size:.87rem; max-width:280px; margin:0;">The partner platform for venues and event hosts to list, sell, and run the night.</p>
      </div>
      <div>
        <h6>Partners</h6>
        <ul>
          <li><a href="#how-it-works">How it works</a></li>
          <li><a href="#features">What you get</a></li>
          <li><a href="#why">Why partner</a></li>
          <li><a href="register">Register</a></li>
        </ul>
      </div>
      <div>
        <h6>Support</h6>
        <ul>
          <li><a href="#faq">FAQ</a></li>
          <li><a href="login">Sign in</a></li>
          <li><a href="mailto:partners@highspinn.com">partners@highspinn.com</a></li>
        </ul>
      </div>
      <div>
        <h6>Company</h6>
        <ul>
          <li><a href="https://highspinn.com/">Highspinn.com</a></li>
          <li><a href="https://highspinn.com/about">About</a></li>
          <li><a href="https://highspinn.com/terms">Terms</a></li>
          <li><a href="https://highspinn.com/privacy">Privacy</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> Highspinn. All rights reserved.</span>
      <div class="social-row">
        <a href="#"><i class="fa-brands fa-instagram"></i></a>
        <a href="#"><i class="fa-brands fa-facebook-f"></i></a>
        <a href="#"><i class="fa-brands fa-x-twitter"></i></a>
      </div>
    </div>
  </div>
</footer>

<script>
(function(){
  // ---- marquee: build ticket strip, duplicate once for seamless loop ----
  var items = [
    { icon:'fa-music', label:'DJ Nights' },
    { icon:'fa-champagne-glasses', label:'Bollywood Nights' },
    { icon:'fa-microphone', label:'Karaoke' },
    { icon:'fa-microphone-lines', label:'Open Mic' },
    { icon:'fa-users', label:'Couples & Stags' },
    { icon:'fa-glass-cheers', label:'New Year Parties' },
    { icon:'fa-star', label:'Live Performances' },
    { icon:'fa-building', label:'Rooftop & Lounge Nights' }
  ];
  var track = document.getElementById('marqueeTrack');
  function renderSet(){
    var html = '';
    items.forEach(function(it){
      html += '<span class="ticket"><i class="fa-solid ' + it.icon + '"></i>' + it.label + '</span>';
    });
    return html;
  }
  track.innerHTML = renderSet() + renderSet();

  // ---- faq accordion ----
  document.querySelectorAll('.faq-item').forEach(function(item){
    var q = item.querySelector('.faq-q');
    var a = item.querySelector('.faq-a');
    function setState(open){
      item.classList.toggle('open', open);
      a.style.maxHeight = open ? a.scrollHeight + 'px' : 0;
    }
    setState(item.classList.contains('open'));
    q.addEventListener('click', function(){
      var willOpen = !item.classList.contains('open');
      document.querySelectorAll('.faq-item.open').forEach(function(other){
        if (other !== item) setState.call(other, false);
      });
      setState(willOpen);
    });
    window.addEventListener('resize', function(){
      if (item.classList.contains('open')) a.style.maxHeight = a.scrollHeight + 'px';
    });
  });

  // ---- mobile off-canvas menu ----
  var navToggleBtn   = document.getElementById('navToggleBtn');
  var offcanvasPanel = document.getElementById('offcanvasPanel');
  var offcanvasOverlay = document.getElementById('offcanvasOverlay');
  var offcanvasClose = document.getElementById('offcanvasClose');

  function openOffcanvas(){
    offcanvasPanel.classList.add('show');
    offcanvasOverlay.classList.add('show');
    document.body.classList.add('offcanvas-open');
  }
  function closeOffcanvas(){
    offcanvasPanel.classList.remove('show');
    offcanvasOverlay.classList.remove('show');
    document.body.classList.remove('offcanvas-open');
  }

  if (navToggleBtn) navToggleBtn.addEventListener('click', openOffcanvas);
  if (offcanvasClose) offcanvasClose.addEventListener('click', closeOffcanvas);
  if (offcanvasOverlay) offcanvasOverlay.addEventListener('click', closeOffcanvas);
  document.querySelectorAll('.offcanvas-link').forEach(function(link){
    link.addEventListener('click', closeOffcanvas);
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeOffcanvas();
  });
})();
</script>
</body>
</html>