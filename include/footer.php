<style>
/* ---------- why us ---------- */
.stat-box{ padding:1.4rem 0; border-bottom:1px solid var(--line); }
.stat-box:last-child{ border-bottom:none; }
.stat-box .num{ font-size:1.9rem; font-weight:800; }
.stat-box small{ color:var(--muted); font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.05em; }

.feat-card{ background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:1.3rem; height:100%; }
.feat-icon{
  width:42px; height:42px; border-radius:11px; background:var(--panel-2);
  display:flex; align-items:center; justify-content:center; color:var(--pink); font-size:1.05rem; margin-bottom:.9rem;
}
.feat-card h5{ font-size:.95rem; font-weight:700; margin-bottom:.35rem; }
.feat-card p{ font-size:.82rem; color:var(--muted); margin:0; }

/* ---------- testimonials ---------- */
.testi-card{ background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:1.5rem; height:100%; }
.testi-card p{ font-size:.9rem; color:var(--text); font-style:italic; margin-bottom:1.2rem; }
.testi-user{ display:flex; align-items:center; gap:.7rem; }
.testi-avatar{
  width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.8rem; color:#fff;
}
.testi-user h6{ font-size:.85rem; font-weight:700; margin:0; }
.testi-user small{ color:var(--muted-2); font-size:.75rem; }

/* ---------- newsletter cta ---------- */
.news-cta{
  background:linear-gradient(120deg,#3a0a1e,#1c0610 60%);
  border:1px solid var(--line);
  border-radius:22px; padding:3rem; position:relative; overflow:hidden;
}
.news-cta::before{
  content:""; position:absolute; width:320px; height:320px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,61,104,.35), transparent 70%);
  top:-140px; right:-60px;
}
.news-cta h2{ font-size:clamp(1.6rem,3vw,2.2rem); }
.news-form{ display:flex; gap:.6rem; max-width:420px; }
.news-form input{
  flex:1; background:var(--void); border:1px solid var(--line); color:#fff;
  border-radius:999px; padding:.7rem 1.2rem; font-size:.85rem; outline:none;
}
.news-form button{
  background:var(--pink); border:none; color:#fff; font-weight:700;
  padding:.7rem 1.4rem; border-radius:999px; font-size:.85rem; white-space:nowrap;
}

/* ---------- app bar ---------- */
.app-bar{
  background:var(--panel); border:1px solid var(--line); border-radius:18px;
  padding:1.6rem 2rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;
}
.app-bar .icon-sq{
  width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.1rem; flex:none;
}
.store-btn{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-weight:700; font-size:.82rem; padding:.55rem 1.1rem; border-radius:10px;
  display:inline-flex; align-items:center; gap:.5rem;
}
.store-btn:hover{ border-color:var(--pink); color:var(--text); }

/* ---------- footer ---------- */
footer{ background:var(--void); border-top:1px solid var(--line); padding:3.5rem 0 1.5rem; }
footer h6{ font-size:.72rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:var(--muted-2); margin-bottom:1.1rem; }
footer ul{ list-style:none; padding:0; margin:0; }
footer li{ margin-bottom:.6rem; }
footer a{ color:var(--muted); font-size:.87rem; }
footer a:hover{ color:var(--pink); }
.social-circle{
  width:34px; height:34px; border-radius:50%; border:1px solid var(--line);
  display:flex; align-items:center; justify-content:center; color:var(--muted);
}
.social-circle:hover{ background:var(--pink); border-color:var(--pink); color:#fff; }
.foot-bottom{ border-top:1px solid var(--line); margin-top:2.5rem; padding-top:1.5rem; font-size:.8rem; color:var(--muted-2); }

</style></style>


<!-- ================= NEWSLETTER ================= -->
<section id="hideonapp">
  <div class="container">
    <div class="news-cta">
      <span class="eyebrow" style="color:var(--yellow);">Weekly drop</span>
      <h2 class="mb-2">Never miss a Friday again.</h2>
      <p class="text-muted-c mb-4" style="max-width:420px;">
        Get the hottest Bangalore events dropped straight to your inbox every Wednesday.
      </p>
      <form class="news-form" onsubmit="return false;">
        <input type="email" placeholder="Enter your email" required>
        <button type="submit"><i class="fa-solid fa-paper-plane me-1"></i> Subscribe</button>
      </form>
    </div>
  </div>
</section>

<!-- ================= APP BAR ================= -->
<section class="pt-0" id="hideonapp">
  <div class="container">
    <div class="app-bar">
      <div class="d-flex align-items-center gap-3">
        <span class="icon-sq"><i class="fa-solid fa-mobile-screen-button"></i></span>
        <div>
          <h5 class="mb-1" style="font-size:1.05rem;">Get the HighSpinn app.</h5>
          <p class="text-muted-c mb-0 small">One-tap RSVPs, digital passes and pre-sale drops.</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <a href="https://apps.apple.com/in/app/highspinn/id6739353376" class="store-btn"><i class="fa-brands fa-apple"></i> App Store</a>
        <a href="https://play.google.com/store/apps/details?id=com.highspinn&hl=en_IN" class="store-btn"><i class="fa-brands fa-google-play"></i> Play Store</a>
      </div>
    </div>
  </div>
</section>

<!-- ================= FOOTER ================= -->
<footer>
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4">
        <a class="brand-mark mb-3 d-inline-flex" href="#">
          <span class="icon-sq"><i class="fa-solid fa-record-vinyl"></i></span>
          High<span class="accent">Spinn</span>
        </a>
        <p class="text-muted-c mt-3" style="max-width:280px;">
          Bangalore's go-to destination for nightlife, parties, and unforgettable
          experiences. Discover, book, celebrate.
        </p>
        <div class="d-flex gap-2 mt-3">
            
            
            
          <a href="<?= $instagram ?>" class="social-circle"><i class="fa-brands fa-instagram"></i></a>
          <a href="<?= $facebook ?>" class="social-circle"><i class="fa-brands fa-facebook-f"></i></a>
          <a  href="<?= $twitter ?>" class="social-circle"><i class="fa-brands fa-x-twitter"></i></a>
          <a href="<?= $youtube ?>" class="social-circle"><i class="fa-brands fa-youtube"></i></a>
        </div>
      </div>
      <div class="col-6 col-lg-2 offset-lg-1">
        <h6>Explore</h6>
        <ul>
 	<?php
                    // Fetch menu items to display
                    $category_sql = "SELECT * FROM menu where place = 'category'";
                    $resultcategory = mysqli_query($conn, $category_sql);
                    while ($menu_category = mysqli_fetch_assoc($resultcategory)):
                    ?>
		        <li><span><a href="<?= $menu_category['url'] ?>"><?= $menu_category['name'] ?></a></span></li>
		       
                    <?php endwhile; ?>
        </ul>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Company</h6>
        <ul>
            	<?php
                    // Fetch menu items to display
                    $quick_link_sql = "SELECT * FROM menu where place = 'quick_link'";
                    $result_quick_link = mysqli_query($conn, $quick_link_sql);
                    while ($menu_quick_link = mysqli_fetch_assoc($result_quick_link)):
                    ?>
		        <li><span><a href="<?= $menu_quick_link['url'] ?>"><?= $menu_quick_link['name'] ?></a></span></li>
		       
                    <?php endwhile; ?>
            
            
  
        </ul>
      </div>
      <div class="col-6 col-lg-3">
        <h6>Support</h6>
        <ul>
          <li><a href="#">Help center</a></li>
          <li><a href="#">Contact</a></li>
          <li><a href="#">Refunds</a></li>
          <li><a href="#">Terms</a></li>
        </ul>
      </div>
    </div>
    <div class="foot-bottom d-flex flex-wrap justify-content-between gap-2">
      <span>&copy; 2026 HighSpinn. All rights reserved.</span>
      <span class="d-flex gap-3">
        <a href="#">Privacy</a><a href="#">Terms</a><a href="#">Sitemap</a>
      </span>
    </div>
  </div>
</footer>

<script>
(function(){
  // ---- Tonight in Bangalore: category pill filtering ----
  var grid = document.getElementById('homeEventGrid');
  var items = Array.prototype.slice.call(grid.querySelectorAll('.evt-item'));
  var pills = document.querySelectorAll('#homeCatPills .filter-pill');
  var emptyState = document.getElementById('homeEmptyState');

  function applyFilter(cat){
    var visibleCount = 0;
    items.forEach(function(item){
      var show = !cat || item.getAttribute('data-cat') === cat;
      item.style.display = show ? '' : 'none';
      if (show) visibleCount++;
    });
    emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
    grid.style.display = visibleCount === 0 ? 'none' : '';
  }

  function setActivePill(cat){
    pills.forEach(function(p){
      p.classList.toggle('active', p.getAttribute('data-cat') === cat);
    });
  }

  pills.forEach(function(p){
    p.addEventListener('click', function(){
      var cat = p.getAttribute('data-cat') || '';
      setActivePill(cat);
      applyFilter(cat);
    });
  });

  // Honor ?cat= from category card links (e.g. events.html-style deep link on this page too)
  var urlParams = new URLSearchParams(window.location.search);
  var initialCat = urlParams.get('cat') || '';
  var validCats = Array.prototype.map.call(pills, function(p){ return p.getAttribute('data-cat'); });
  if (initialCat && validCats.indexOf(initialCat) !== -1){
    setActivePill(initialCat);
    applyFilter(initialCat);
    var evSection = document.getElementById('events');
    if (evSection) window.scrollTo({ top: evSection.offsetTop - 90, behavior: 'smooth' });
  }

  // ---- heart / save toggle across events, artists and venues ----
  document.querySelectorAll('.heart-btn').forEach(function(h){
    h.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      var icon = h.querySelector('i');
      icon.classList.toggle('fa-regular');
      icon.classList.toggle('fa-solid');
      h.style.color = icon.classList.contains('fa-solid') ? '#ff3d68' : '#fff';
    });
  });
})();
</script>
</body>
</html>