<?php
/**
 * include/app-bottombar.php
 * Shared bottom tab bar for every mobile "app" view.
 *
 * Include near the end of the page (after header.php has set
 * $isLoggedIn and hs_city_url() is available). Set $activeTab
 * before including if you want a tab pre-highlighted:
 *   $activeTab = 'discover'; // 'discover' | 'search' | 'passes' | 'profile' | ''
 */
$activeTab   = $activeTab ?? '';
$cityUrlBase = $cityUrlBase ?? (function_exists('hs_city_url') ? hs_city_url() : '/');
?>
<style>
.app-tabbar{
  position:fixed; bottom:0; left:0; right:0; z-index:1050;
  max-width:480px; margin:0 auto;
  display:flex; justify-content:space-around; align-items:center;
  padding:9px 6px calc(9px + env(safe-area-inset-bottom));
  background:rgba(15,15,18,.95); backdrop-filter:blur(16px); border-top:1px solid var(--line);
}
.app-tab{ display:flex; flex-direction:column; align-items:center; gap:3px; flex:1; padding:4px 0; background:none; border:none; color:var(--muted-2); font-size:10px; font-weight:700; cursor:pointer; }
.app-tab i{ font-size:18px; }
.app-tab.active{ color:var(--pink); }
body.hs-app-view{ padding-bottom: 92px; } /* clears the fixed bar on any page that includes it */
</style>

<div class="app-tabbar" id="hsAppTabbar">
  <button type="button" class="app-tab <?= $activeTab === 'discover' ? 'active' : '' ?>" id="tabDiscover"><i class="fa-solid fa-compass"></i>Discover</button>
  <button type="button" class="app-tab <?= $activeTab === 'search'   ? 'active' : '' ?>" id="tabSearch"><i class="fa-solid fa-magnifying-glass"></i>Search</button>
  <button type="button" class="app-tab <?= $activeTab === 'passes'   ? 'active' : '' ?>" id="tabPasses"><i class="fa-solid fa-ticket"></i>My Passes</button>
  <button type="button" class="app-tab <?= $activeTab === 'profile'  ? 'active' : '' ?>" id="tabProfile"><i class="fa-solid fa-circle-user"></i>Profile</button>
</div>

<script>
(function(){
  var isLoggedIn   = <?= json_encode((bool)($isLoggedIn ?? false)) ?>;
  var cityUrlBase  = <?= json_encode(rtrim($cityUrlBase, '/')) ?>;

  function requireAuthOrGo(path){
    if (!isLoggedIn) {
      var authModalEl = document.getElementById('authModal');
      if (authModalEl && window.bootstrap) { new bootstrap.Modal(authModalEl).show(); return; }
    }
    window.location.href = cityUrlBase + path;
  }

  var tabDiscover = document.getElementById('tabDiscover');
  var tabSearch   = document.getElementById('tabSearch');
  var tabPasses   = document.getElementById('tabPasses');
  var tabProfile  = document.getElementById('tabProfile');

  tabDiscover.addEventListener('click', function(){
    // If we're already on the discover page, let its own script (if any)
    // handle scroll/reset instead of a full reload.
    if (typeof window.hsGoDiscover === 'function') { window.hsGoDiscover(); return; }
    window.location.href = cityUrlBase + '/';
  });

  tabSearch.addEventListener('click', function(){
    var localSearchInput = document.getElementById('appSearchInput');
    if (localSearchInput) {
      // already on discover — just focus the existing search bar
      window.scrollTo({ top: 0, behavior: 'smooth' });
      setTimeout(function(){ localSearchInput.focus(); }, 250);
      return;
    }
    // any other page — go to discover and ask it to auto-focus search
    window.location.href = cityUrlBase + '/?focusSearch=1';
  });

  tabPasses.addEventListener('click', function(){ requireAuthOrGo('/account/#bookings'); });
  tabProfile.addEventListener('click', function(){ requireAuthOrGo('/account/'); });
})();
</script>