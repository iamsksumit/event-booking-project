<?php
/**
 * include/events-ui.php
 *
 * Shared helpers + a small CSS add-on for every page under /events/.
 * Depends on the CSS custom properties + shared classes already defined
 * in include/style.php (--panel, --line, --pink, badge-pill, btn-mini,
 * panel-card, form-control-c, table-clean, etc). Do not duplicate those
 * here - just extend them for things the dashboard didn't need
 * (event card grid, wizard-style stepper, ticket row editor).
 */

if (!function_exists('h')) {
    // Short escape helper used everywhere below.
    function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('event_status_badge')) {
    /**
     * Renders a badge-pill matching the dashboard's status colour language.
     * events.status : 0 = Past/Archived, 1 = Published/Live, 2 = Draft,
     *                  3 = Unapproved, 4 = Submitted for Approval
     */
    function event_status_badge($status) {
        $status = (int) $status;
        switch ($status) {
            case 1:  return '<span class="badge-pill badge-live">Published</span>';
            case 2:  return '<span class="badge-pill badge-draft">Draft</span>';
            case 3:  return '<span class="badge-pill badge-pending">Unapproved</span>';
            case 4:  return '<span class="badge-pill badge-pending">Submitted for Approval</span>';
            case 0:  return '<span class="badge-pill badge-past">Past</span>';
            default: return '<span class="badge-pill badge-past">Expired</span>';
        }
    }
}

if (!function_exists('fmt_event_date')) {
    function fmt_event_date($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '—';
        $t = strtotime($datetime);
        return $t ? date('d M Y, g:i A', $t) : h($datetime);
    }
}

if (!function_exists('event_slug')) {
    function event_slug($title) {
        $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', trim($title));
        $slug = trim($slug, '-');
        return $slug . time();
    }
}
?>
<style>
/* ===================== Events module add-ons ===================== */

/* Toolbar: search + sort + create, matches topbar search-pill language */
.events-toolbar{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; }
.events-toolbar .search-pill{ max-width:340px; }
.events-toolbar .search-pill form{ display:flex; align-items:center; gap:.6rem; width:100%; }
.events-toolbar .search-pill button{ background:transparent; border:none; color:var(--muted-2); padding:0; display:flex; }
.events-sort-select{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text); border-radius:10px;
  padding:.55rem .9rem; font-size:.82rem; font-weight:600;
}
.events-sort-select:focus{ outline:none; border-color:var(--pink); }

/* Card grid */
.event-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:1.1rem; }
.event-card{ background:var(--panel); border:1px solid var(--line); border-radius:16px; overflow:hidden; display:flex; flex-direction:column; transition:border-color .15s ease; }
.event-card:hover{ border-color:#34343c; }
.event-card .thumb-wrap{ position:relative; aspect-ratio:16/10; background:var(--panel-2); overflow:hidden; }
.event-card .thumb-wrap img{ width:100%; height:100%; object-fit:cover; display:block; }
.event-card .thumb-wrap .badge-pill{ position:absolute; top:.7rem; left:.7rem; box-shadow:0 4px 12px rgba(0,0,0,.4); }
.event-card-body{ padding:1.1rem 1.2rem 1.3rem; display:flex; flex-direction:column; gap:.5rem; flex:1; }
.event-card-body h4{ font-size:1rem; line-height:1.35; }
.event-card-body h4 a{ color:var(--text); }
.event-card-body h4 a:hover{ color:var(--pink); }
.event-meta-row{ display:flex; align-items:center; gap:.5rem; color:var(--muted); font-size:.8rem; }
.event-meta-row i{ width:14px; text-align:center; color:var(--muted-2); }
.event-card-actions{ display:flex; gap:.5rem; margin-top:.6rem; }
.event-card-actions a, .event-card-actions button{ flex:1; text-align:center; }
.empty-state{ text-align:center; padding:3.5rem 1rem; color:var(--muted-2); }
.empty-state i{ font-size:2rem; display:block; margin-bottom:.7rem; color:var(--muted-2); }

/* Approve/reject rows (approval queue) */
.approval-row{ display:flex; align-items:center; gap:.8rem; padding:.85rem 0; border-bottom:1px solid var(--line); }
.approval-row:last-child{ border-bottom:none; }
.approval-thumb{ width:44px; height:44px; border-radius:11px; overflow:hidden; flex:none; background:var(--panel-2); display:flex; align-items:center; justify-content:center; color:var(--muted-2); }
.approval-thumb img{ width:100%; height:100%; object-fit:cover; }
.btn-mini.approve{ border-color:rgba(61,220,132,.35); color:var(--green); }
.btn-mini.approve:hover{ border-color:var(--green); }
.btn-mini.reject{ border-color:rgba(255,61,104,.35); color:var(--pink); }
.btn-mini.reject:hover{ border-color:var(--pink); }

/* Pagination, adapted from the shared table-pagination language */
.events-pagination{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-top:1.6rem; padding-top:1.2rem; border-top:1px solid var(--line); }
.events-pagination small{ color:var(--muted-2); font-size:.78rem; }
.events-pagination nav ul{ list-style:none; display:flex; gap:.35rem; margin:0; padding:0; }
.events-pagination .page-link{ width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; background:var(--panel-2); border:1px solid var(--line); color:var(--muted); font-size:.78rem; font-weight:700; }
.events-pagination .page-item.active .page-link{ background:var(--pink); border-color:var(--pink); color:#fff; }
.events-pagination .page-item.disabled .page-link{ opacity:.4; pointer-events:none; }
.events-pagination .page-link:hover{ border-color:var(--pink); color:var(--text); }

/* Multi-step form (create / edit event) */
.ev-steps{ display:flex; gap:.5rem; margin-bottom:1.6rem; flex-wrap:wrap; }
.ev-steps button{
  flex:1; min-width:120px; background:var(--panel-2); border:1px solid var(--line); color:var(--muted);
  font-size:.78rem; font-weight:700; padding:.65rem .5rem; border-radius:10px; cursor:pointer; transition:all .15s ease;
}
.ev-steps button.active{ background:var(--pink); border-color:var(--pink); color:#fff; }
.ev-steps button.done{ color:var(--green); border-color:rgba(61,220,132,.3); }
.ev-section{ display:none; }
.ev-section.active{ display:block; }
.ev-field{ margin-bottom:1.1rem; }
.ev-field .required{ color:var(--pink); }
textarea.form-control-c{ min-height:110px; resize:vertical; }
.ev-form-nav{ display:flex; justify-content:space-between; margin-top:1.6rem; padding-top:1.3rem; border-top:1px solid var(--line); }
.btn-ghost-c{ background:var(--panel-2); border:1px solid var(--line); color:var(--text); font-weight:700; font-size:.85rem; padding:.65rem 1.2rem; border-radius:10px; }
.btn-ghost-c:hover{ border-color:var(--pink); color:var(--pink); }

/* Ticket row editor */
.ticket-editor{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:1rem; margin-bottom:1rem; }
.ticket-card{ background:var(--panel-2); border:1px solid var(--line); border-radius:12px; padding:1rem; position:relative; }
.ticket-card .remove-ticket{ position:absolute; top:.6rem; right:.7rem; color:var(--pink); font-size:.72rem; font-weight:700; cursor:pointer; }
.ticket-card input, .ticket-card textarea{ margin-bottom:.6rem; }
.ticket-card textarea{ min-height:70px; }
.add-ticket-card{
  border:1.5px dashed var(--line); border-radius:12px; display:flex; flex-direction:column; align-items:center;
  justify-content:center; gap:.4rem; color:var(--muted-2); cursor:pointer; min-height:150px; font-weight:700; font-size:.8rem;
}
.add-ticket-card:hover{ border-color:var(--pink); color:var(--pink); }
.add-ticket-card i{ font-size:1.6rem; }

/* Detail page */
.detail-hero{ display:grid; grid-template-columns:320px 1fr; gap:1.6rem; }
.detail-hero img.detail-image{ width:100%; border-radius:16px; object-fit:cover; aspect-ratio:4/5; border:1px solid var(--line); }
.detail-stat-row{ display:flex; align-items:center; gap:.6rem; color:var(--muted); font-size:.85rem; margin-bottom:.55rem; }
.detail-stat-row i{ width:16px; text-align:center; color:var(--muted-2); }
.detail-actions{ display:flex; flex-wrap:wrap; gap:.6rem; margin-top:1.2rem; }
.copy-field{ display:flex; gap:.6rem; }
.copy-field input{ flex:1; }
@media (max-width: 820px){ .detail-hero{ grid-template-columns:1fr; } }
</style>
