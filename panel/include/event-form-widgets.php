<style>
.select-with-add { display: flex; gap: .5rem; align-items: center; }
.select-with-add .select2-container { flex: 1; }
.select-with-add .btn-mini { flex-shrink: 0; height: 42px; }

/* ===================== Select2 (Venue / Category / Artist pickers) =====================
   Unlike inputs and textareas, Select2 doesn't have any existing dark-theme
   styling anywhere on the site to fall back on - its default theme is
   plain white, which is exactly the white boxes / unreadable white-on-white
   tags you're seeing. This skins it fully, using the same variables as the
   rest of the form so it actually matches. */
.select2-container--default .select2-selection--single,
.select2-container--default .select2-selection--multiple {
    background: var(--panel-2) !important;
    border: 1px solid var(--line) !important;
    border-radius: 10px !important;
    min-height: 42px !important;
}
.select2-container--default .select2-selection--single {
    height: 42px !important;
    display: flex;
    align-items: center;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    color: var(--text) !important;
    line-height: 40px !important;
    padding-left: .9rem !important;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder {
    color: var(--muted-2) !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 40px !important;
    right: 8px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow b {
    border-color: var(--muted) transparent transparent transparent !important;
}

.select2-container--default .select2-selection--multiple {
    padding: .25rem .5rem;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
}
.select2-container--default .select2-selection--multiple .select2-selection__rendered {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    padding: 0;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background: rgba(255, 61, 104, .14) !important;
    border: 1px solid rgba(255, 61, 104, .35) !important;
    color: var(--text) !important;
    border-radius: 8px !important;
    padding: .15rem .5rem .15rem .35rem !important;
    margin: 0 !important;
    display: flex;
    align-items: center;
    gap: .3rem;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: var(--pink) !important;
    border: none !important;
    margin-right: 0 !important;
    order: -1;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    color: #fff !important;
    background: transparent !important;
}
.select2-container--default .select2-search--inline .select2-search__field {
    color: var(--text) !important;
}

.select2-container--default.select2-container--focus .select2-selection--multiple,
.select2-container--default.select2-container--open .select2-selection--single {
    border-color: var(--pink) !important;
    box-shadow: 0 0 0 3px rgba(255, 61, 104, .15);
}

.select2-dropdown {
    background: var(--panel) !important;
    border: 1px solid var(--line) !important;
    color: var(--text) !important;
    border-radius: 10px !important;
    overflow: hidden;
}
.select2-search--dropdown { background: var(--panel); padding: .6rem; }
.select2-search--dropdown .select2-search__field {
    background: var(--panel-2) !important;
    border: 1px solid var(--line) !important;
    color: var(--text) !important;
    border-radius: 8px !important;
    padding: .4rem .7rem;
}
.select2-results__option {
    color: var(--text) !important;
    padding: .5rem .9rem !important;
}
.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background: var(--pink) !important;
    color: #fff !important;
}
.select2-container--default .select2-results__option[aria-selected=true] {
    background: rgba(255, 61, 104, .1) !important;
}
.select2-results__message { color: var(--muted-2) !important; }

.field-error, .select2-container.field-error .select2-selection {
    border-color: var(--pink) !important;
    box-shadow: 0 0 0 2px rgba(255,61,104,.15);
}

/* Description / Terms & Conditions editors now use CKEditor too - see the
   CKEditor dark-skin block below, which applies to every .ck.ck-editor on
   the page (both these and the quick-add modal). */

/* ===================== CKEditor (quick-add Description fields) =====================
   Same dark skin as the Venues page so Artist/Venue quick-add matches. */
.ck.ck-editor{ --ck-color-base-background: var(--panel-2); --ck-color-base-border: var(--line); }
.ck.ck-toolbar{ background:var(--panel-2) !important; border-color:var(--line) !important; }
.ck.ck-editor__main > .ck-editor__editable{ background:var(--panel-2) !important; color:var(--text) !important; border-color:var(--line) !important; min-height:140px; }
.ck.ck-button, .ck .ck-button{ color:var(--muted) !important; }
.ck.ck-button:hover, .ck .ck-button:hover{ background:var(--panel-3, var(--panel)) !important; color:var(--text) !important; }
.ck.ck-button.ck-on{ background:rgba(255,61,104,.16) !important; color:var(--pink) !important; }
.ck.ck-toolbar__separator{ background:var(--line) !important; }
.ck.ck-dropdown__panel, .ck.ck-list{ background:var(--panel-2) !important; border-color:var(--line) !important; }
.ck.ck-list__item .ck-button{ color:var(--text) !important; }

.quickadd-hint { display: block; margin-top: .35rem; }
</style>

<!-- ================= QUICK ADD MODAL (Venue / Category / Artist) ================= -->
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form id="quickAddForm" enctype="multipart/form-data">
        <div class="modal-header">
          <div>
            <h5 id="quickAddTitle">Add New</h5>
            <div class="cell-sub" id="quickAddSubtitle"></div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div id="quickAddError" class="cell-sub mb-3" style="display:none; color:var(--pink); font-weight:600;"></div>

          <!-- Category -->
          <div id="qaGroupCategory" class="row g-3 d-none">
            <div class="col-12">
              <label class="form-label-c">Category Name</label>
              <input type="text" class="form-control-c" id="qaCatName" placeholder="e.g. Ladies Night">
              <small class="cell-sub quickadd-hint">URL slug is generated automatically from the name.</small>
            </div>
            <div class="col-12">
              <label class="form-label-c">Category Image</label>
              <input type="file" class="form-control-c" id="qaCatImage" accept="image/*">
            </div>
          </div>

          <!-- Artist -->
          <div id="qaGroupArtist" class="row g-3 d-none">
            <div class="col-md-6">
              <label class="form-label-c">Artist Name</label>
              <input type="text" class="form-control-c" id="qaArtistName" placeholder="e.g. DJ Esha">
              <small class="cell-sub quickadd-hint">URL slug is generated automatically from the name.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">City</label>
              <input type="text" class="form-control-c" id="qaArtistCity" placeholder="Start typing a city…" autocomplete="off">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Genre</label>
              <input type="text" class="form-control-c" id="qaArtistGenre" placeholder="Start typing a genre…" autocomplete="off">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Artist Image</label>
              <input type="file" class="form-control-c" id="qaArtistImage" accept="image/*">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Facebook</label>
              <input type="text" class="form-control-c" id="qaArtistFb" placeholder="Facebook URL or handle">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Instagram</label>
              <input type="text" class="form-control-c" id="qaArtistInsta" placeholder="Instagram URL or handle">
            </div>
            <div class="col-12">
              <label class="form-label-c">Description</label>
              <textarea class="form-control-c" id="qaArtistDesc" rows="6" placeholder="Short artist bio"></textarea>
            </div>
          </div>

          <!-- Venue -->
          <div id="qaGroupPlace" class="row g-3 d-none">
            <div class="col-md-6">
              <label class="form-label-c">Venue Name</label>
              <input type="text" class="form-control-c" id="qaPlaceName" placeholder="e.g. Sky Lounge">
              <small class="cell-sub quickadd-hint">URL slug is generated automatically from the name.</small>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">City</label>
              <input type="text" class="form-control-c" id="qaPlaceCity" placeholder="Start typing a city…" autocomplete="off">
            </div>
            <div class="col-12">
              <label class="form-label-c">Address</label>
              <input type="text" class="form-control-c" id="qaPlaceAddress" placeholder="Full street address">
            </div>
            <div class="col-12">
              <label class="form-label-c">Venue Image</label>
              <input type="file" class="form-control-c" id="qaPlaceImage" accept="image/*">
            </div>
            <div class="col-12">
              <label class="form-label-c">Description</label>
              <textarea class="form-control-c" id="qaPlaceDesc" rows="6" placeholder="What makes this venue worth mentioning"></textarea>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn-pink-sm" id="quickAddSave">Add New</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/39.0.0/classic/ckeditor.js"></script>

<script>
/* ===================== quick-add modal (Bootstrap) ===================== */
document.addEventListener('DOMContentLoaded', function () {
    var quickAddModalEl = document.getElementById('quickAddModal');
    var quickAddModal = new bootstrap.Modal(quickAddModalEl);
    var quickAddContext = null; // { type: 'place'|'category'|'artist', select: <DOM select el> }
    var qaDescEditor = null;

    var QA_GROUPS    = { place: 'qaGroupPlace', category: 'qaGroupCategory', artist: 'qaGroupArtist' };
    var QA_TITLES    = { place: 'Venue', category: 'Category', artist: 'Artist' };
    var QA_SUBTITLES = {
        place: 'Shown when partners select a venue for their event',
        category: 'Used to tag events on the public site',
        artist: 'Shown on the public event & artist pages'
    };
    var QA_DESC_FIELD = { place: 'qaPlaceDesc', artist: 'qaArtistDesc', category: null };

    window.openQuickAdd = function (type, selectEl) {
        quickAddContext = { type: type, select: selectEl };

        document.getElementById('quickAddTitle').textContent = 'Add New ' + QA_TITLES[type];
        document.getElementById('quickAddSubtitle').textContent = QA_SUBTITLES[type] || '';
        document.getElementById('quickAddSave').textContent = 'Add New ' + QA_TITLES[type];
        document.getElementById('quickAddError').style.display = 'none';

        document.getElementById('quickAddForm').reset();
        Object.keys(QA_GROUPS).forEach(function (t) {
            document.getElementById(QA_GROUPS[t]).classList.add('d-none');
        });
        document.getElementById(QA_GROUPS[type]).classList.remove('d-none');

        quickAddModal.show();
    };

    function initDescEditor() {
        var descFieldId = quickAddContext && QA_DESC_FIELD[quickAddContext.type];
        if (!descFieldId || qaDescEditor || typeof ClassicEditor === 'undefined') return;
        var el = document.getElementById(descFieldId);
        ClassicEditor.create(el).then(function (editor) {
            qaDescEditor = editor;
        }).catch(function (err) {
            console.error('CKEditor failed to load, falling back to plain textarea.', err);
        });
    }
    function destroyDescEditor() {
        if (!qaDescEditor) return;
        var editor = qaDescEditor;
        qaDescEditor = null;
        editor.destroy().catch(function () {});
    }

    quickAddModalEl.addEventListener('shown.bs.modal', initDescEditor);
    quickAddModalEl.addEventListener('hidden.bs.modal', destroyDescEditor);

    document.getElementById('quickAddSave').addEventListener('click', function () {
        if (!quickAddContext) return;
        var type = quickAddContext.type;
        var errEl = document.getElementById('quickAddError');
        errEl.style.display = 'none';

        var fd = new FormData();
        fd.append('type', type);
        var name = '';

        if (type === 'place') {
            name = document.getElementById('qaPlaceName').value.trim();
            fd.append('name', name);
            fd.append('city', document.getElementById('qaPlaceCity').value.trim());
            fd.append('address', document.getElementById('qaPlaceAddress').value.trim());
            fd.append('description', qaDescEditor ? qaDescEditor.getData() : document.getElementById('qaPlaceDesc').value.trim());
            var pImg = document.getElementById('qaPlaceImage').files[0];
            if (pImg) fd.append('image', pImg);
        } else if (type === 'category') {
            name = document.getElementById('qaCatName').value.trim();
            fd.append('name', name);
            var cImg = document.getElementById('qaCatImage').files[0];
            if (cImg) fd.append('image', cImg);
        } else if (type === 'artist') {
            name = document.getElementById('qaArtistName').value.trim();
            fd.append('name', name);
            fd.append('city', document.getElementById('qaArtistCity').value.trim());
            fd.append('genre', document.getElementById('qaArtistGenre').value.trim());
            fd.append('fb', document.getElementById('qaArtistFb').value.trim());
            fd.append('insta', document.getElementById('qaArtistInsta').value.trim());
            fd.append('description', qaDescEditor ? qaDescEditor.getData() : document.getElementById('qaArtistDesc').value.trim());
            var aImg = document.getElementById('qaArtistImage').files[0];
            if (aImg) fd.append('image', aImg);
        }

        if (!name) {
            errEl.textContent = 'Name is required.';
            errEl.style.display = 'block';
            return;
        }

        // Build the URL from the current path rather than a bare relative
        // string, so it always resolves against this exact page's protocol
        // and host - if the request still gets redirected somewhere by the
        // server (e.g. an http->https or www canonicalization rule), the
        // error below will say so instead of silently failing.
        var quickAddUrl = window.location.pathname.replace(/[^\/]*$/, '') + 'quick-add.php';

        fetch(quickAddUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                if (r.redirected) {
                    throw new Error('Request was redirected to ' + r.url + ' - a server-side redirect (http\u2192https, www, etc.) is turning this POST into a GET. Check your .htaccess.');
                }
                return r.text().then(function (text) {
                    var data;
                    try { data = JSON.parse(text); }
                    catch (e) { throw new Error('Unexpected response (HTTP ' + r.status + '): ' + text.slice(0, 200)); }
                    return data;
                });
            })
            .then(function (data) {
                if (data.error) { errEl.textContent = data.error; errEl.style.display = 'block'; return; }
                var $select = jQuery(quickAddContext.select);
                var newOption = new Option(data.text, data.id, true, true);
                $select.append(newOption).trigger('change');
                quickAddModal.hide();
            })
            .catch(function (err) {
                errEl.textContent = err.message || 'Network error, please try again.';
                errEl.style.display = 'block';
            });
    });
});

/* ===================== autocomplete (Select2 + search.php) ===================== */
function initAutocomplete(selectId, type, placeholder, addBtnEl) {
    var $select = jQuery('#' + selectId);
    if (!$select.length) return;
    $select.select2({
        placeholder: placeholder,
        allowClear: !$select.prop('multiple'),
        minimumInputLength: 0,
        width: '100%',
        ajax: {
            url: 'search.php',
            dataType: 'json',
            delay: 250,
            data: function (params) { return { term: params.term || '', type: type }; },
            processResults: function (data) { return { results: data.results || [] }; }
        }
    });
    if (addBtnEl) {
        addBtnEl.addEventListener('click', function () { openQuickAdd(type, $select.get(0)); });
    }
}

/* ===================== CKEditor - used for the main event Description / Terms ===================== */
function initEventEditor(textareaId, assignToGlobal) {
    var el = document.getElementById(textareaId);
    if (!el || typeof ClassicEditor === 'undefined') return;
    ClassicEditor.create(el).then(function (editor) {
        window[assignToGlobal] = editor;
    }).catch(function (err) {
        console.error('CKEditor failed to load for #' + textareaId + ', falling back to plain textarea.', err);
    });
}
</script>