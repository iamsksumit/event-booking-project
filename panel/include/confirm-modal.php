<?php
/**
 * include/confirm-modal.php
 *
 * One shared Bootstrap confirmation modal, used instead of native
 * window.confirm(). Native confirm() is the likely cause of the
 * "delete/cancel not working properly" reports — it's blocked/ignored
 * in some embedded webviews, doesn't match the app's styling, and the
 * old code had two separate copies of near-identical confirm logic
 * (js-delete-pkg, js-cancel-promo) that were easy to let drift apart.
 *
 * Include this ONCE per page, anywhere after header.php.
 *
 * Usage — just add the classes/data-attributes to any link:
 *
 *   <a href="?action=delete&id=5"
 *      class="js-confirm"
 *      data-confirm-title="Delete package?"
 *      data-confirm-body="This cannot be undone.">Delete</a>
 *
 * No extra JS needed per page — this file wires up every .js-confirm
 * element on the page via event delegation, including ones added
 * dynamically later.
 */
?>
<div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="appConfirmTitle">Are you sure?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p id="appConfirmBody" class="cell-sub" style="margin:0;"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="appConfirmOkBtn" class="btn-pink-sm">Yes, continue</a>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var modalEl = document.getElementById('appConfirmModal');
  if (!modalEl || typeof bootstrap === 'undefined') return;

  var modal   = new bootstrap.Modal(modalEl);
  var titleEl = document.getElementById('appConfirmTitle');
  var bodyEl  = document.getElementById('appConfirmBody');
  var okBtn   = document.getElementById('appConfirmOkBtn');

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('.js-confirm');
    if (!trigger) return;
    e.preventDefault();

    titleEl.textContent = trigger.dataset.confirmTitle || 'Are you sure?';
    bodyEl.textContent  = trigger.dataset.confirmBody || 'This action cannot be undone.';
    okBtn.href = trigger.getAttribute('href') || '#';

    modal.show();
  }, false);
})();
</script>
