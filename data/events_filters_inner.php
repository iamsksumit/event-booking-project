<?php
/**
 * data/events_filters_inner.php
 *
 * Shared filter-panel markup for events.php. Included twice on the page
 * (once for the desktop sidebar, once inside the mobile offcanvas) so
 * both stay visually and functionally identical — the JS in events.php
 * wires up every element matching these classes in both places.
 *
 * Expects $filterCats, $filterVenues, $initialFilters, $currentCityName
 * to already be set by events.php before this is included.
 */
?>
<div class="filter-group">
    <div class="filter-group-title">Search</div>
    <div class="filter-search-pill">
        <i class="fa-solid fa-magnifying-glass text-muted-c2 small"></i>
        <input type="text" class="js-filter-search" placeholder="Search events, venues">
    </div>
</div>

<?php if (!empty($filterCats)): ?>
<div class="filter-group">
    <div class="filter-group-title">Category</div>
    <div class="filter-chip-list">
        <?php foreach ($filterCats as $c): ?>
            <span class="filter-chip js-cat-chip" data-slug="<?= htmlspecialchars(strtolower($c['cat_slug'])) ?>">
                <?= htmlspecialchars($c['cat_name']) ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($filterVenues)): ?>
<div class="filter-group">
    <div class="filter-group-title">Venue</div>
    <div class="filter-check-list">
        <?php foreach ($filterVenues as $v): ?>
            <label class="filter-check">
                <input type="checkbox" class="js-venue-check" value="<?= (int)$v['id'] ?>">
                <span><?= htmlspecialchars($v['name']) ?></span>
                <span class="cnt"><?= (int)$v['live_cnt'] ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="filter-group">
    <div class="filter-group-title">Entry</div>
    <div class="filter-chip-list">
        <span class="filter-chip js-entry-chip" data-entry="free">Free</span>
        <span class="filter-chip js-entry-chip" data-entry="paid">Paid</span>
    </div>
</div>

<div class="filter-group">
    <div class="filter-group-title">Max price</div>
    <div class="price-input-pill">
        <span>&#8377;</span>
        <input type="number" min="0" step="50" class="js-max-price" placeholder="Any">
    </div>
</div>

<div class="filter-group">
    <div class="filter-group-title">Date</div>
    <div class="filter-chip-list">
        <span class="filter-chip js-date-chip" data-bucket="all">Anytime</span>
        <span class="filter-chip js-date-chip" data-bucket="today">Today</span>
        <span class="filter-chip js-date-chip" data-bucket="tomorrow">Tomorrow</span>
        <span class="filter-chip js-date-chip" data-bucket="week">This week</span>
        <span class="filter-chip js-date-chip" data-bucket="weekend">Weekend</span>
        <span class="filter-chip js-date-chip" data-bucket="pick">Pick a date</span>
    </div>
    <input type="date" class="date-pick-input js-date-pick">
</div>

<div class="filter-group">
    <button type="button" class="btn-clear-filters js-clear-filters">
        <i class="fa-solid fa-rotate-left me-1"></i> Clear all filters
    </button>
</div>