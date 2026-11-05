<style>


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