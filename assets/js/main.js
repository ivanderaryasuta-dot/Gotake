/* ============================================================
   assets/js/main.js  —  SHARED JAVASCRIPT HELPERS
   ------------------------------------------------------------
   Your old main.js was empty. It now holds small helper
   functions that several pages use, so each page's own
   <script> stays short.

   JavaScript runs inside the visitor's BROWSER (PHP runs on the
   server). We use it for things that must react instantly
   without reloading the page: opening pop-ups, live search,
   sending a form in the background, etc.

   Loaded at the bottom of every logged-in page by includes/footer.php.
   ============================================================ */


/* ------------------------------------------------------------
   1. MODALS (pop-up windows)
   showModal('myModalId')  -> opens   <div class="modal" id="myModalId">
   hideModal('myModalId')  -> closes it
   The CSS shows a modal only while it has the class "active".
   ------------------------------------------------------------ */
function showModal(id) {
    document.getElementById(id).classList.add('active');
}

function hideModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Clicking the dark area around a modal, or pressing Esc, closes it.
document.addEventListener('click', function (event) {
    if (event.target.classList && event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(function (m) { m.classList.remove('active'); });
    }
});


/* ------------------------------------------------------------
   2. SENDING DATA TO THE api/ FILES IN THE BACKGROUND (AJAX)
   postJSON(url, {a: 1})  -> sends JSON, returns the server's JSON answer
   postForm(url, formEl)  -> sends a whole <form> (including file inputs)

   Both return a Promise that resolves to  {success: true/false, error: '...'}.
   ------------------------------------------------------------ */
function postJSON(url, data) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(function (res) { return res.json(); });
}

function postForm(url, formElement) {
    return fetch(url, {
        method: 'POST',
        body: new FormData(formElement)
    }).then(function (res) { return res.json(); });
}


/* ------------------------------------------------------------
   3. LIVE SEARCH FOR CARDS / TABLE ROWS
   liveFilter('searchInputId', '.item-card')
     -> as you type, hides every element matching the selector
        whose text does not contain what you typed.

   It hides elements with the CSS class "hidden-by-search" (see
   style.css). Category chips use a DIFFERENT class
   ("hidden-by-filter"), so the two filters can work together.
   ------------------------------------------------------------ */
function liveFilter(inputId, itemSelector) {
    var input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('input', function () {
        var term = input.value.trim().toLowerCase();
        document.querySelectorAll(itemSelector).forEach(function (el) {
            var matches = el.textContent.toLowerCase().indexOf(term) !== -1;
            el.classList.toggle('hidden-by-search', !matches);
        });
    });
}


/* ------------------------------------------------------------
   4. PHOTO SLOTS  (NEW in v3)  — add / replace / delete photos
   ------------------------------------------------------------
   The same component is used for:
     - reference photos of an item        (manage_items.php  -> api/item_photo.php)
     - pick-up / return photos of a loan  (manage_requests.php -> api/loan_photos.php)

   buildPhotoSlots(container, photos, handlers)
     container : the <div> to fill
     photos    : {front: 'uploads/...', back: '...'}   (sides that already have a photo)
     handlers  : {upload(side, file) -> Promise, remove(side) -> Promise}
                 Each promise must resolve to {success: true/false, file_path, error}.
                 Pass null for a read-only view.
   A side marked with a red * is REQUIRED (the server refuses to continue without it).
   ------------------------------------------------------------ */
var PHOTO_SIDES    = { front: 'Front', back: 'Back', left: 'Left', right: 'Right', top: 'Top' };
var PHOTO_REQUIRED = ['front', 'back', 'left', 'right'];

function buildPhotoSlots(container, photos, handlers) {
    container.innerHTML = '';
    var grid = document.createElement('div');
    grid.className = 'photo-grid';

    Object.keys(PHOTO_SIDES).forEach(function (side) {
        var current = photos[side] || null;      // path of the photo that exists now (or null)

        // --- the box: label, thumbnail, buttons, small status line ---
        var slot  = document.createElement('div');  slot.className  = 'photo-slot';
        var label = document.createElement('div');  label.className = 'photo-label';
        label.textContent = PHOTO_SIDES[side];
        if (PHOTO_REQUIRED.indexOf(side) !== -1) {
            var star = document.createElement('span');
            star.className = 'req';
            star.textContent = ' *';
            label.appendChild(star);
        }
        var thumb  = document.createElement('div');  thumb.className  = 'photo-thumb';
        var status = document.createElement('div');  status.className = 'photo-status';
        slot.appendChild(label);
        slot.appendChild(thumb);

        var addBtn = null, delBtn = null;

        // Draws the current photo (or an empty box) and updates the button texts
        function refresh() {
            thumb.innerHTML = '';
            if (current) {
                var link = document.createElement('a');
                link.href = current; link.target = '_blank';
                var img = document.createElement('img');
                img.src = current; img.alt = PHOTO_SIDES[side];
                link.appendChild(img);
                thumb.appendChild(link);
            }
            if (addBtn) addBtn.textContent = current ? 'Replace' : 'Add';
            if (delBtn) delBtn.style.display = current ? '' : 'none';
        }

        function showStatus(text, isError) {
            status.textContent = text;
            status.className = 'photo-status' + (isError ? ' error' : '');
        }

        if (handlers) {
            var input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/jpeg,image/png,image/webp';
            input.style.display = 'none';

            var actions = document.createElement('div');
            actions.className = 'photo-actions';

            addBtn = document.createElement('button');
            addBtn.type = 'button'; addBtn.className = 'btn btn-outline';
            addBtn.onclick = function () { input.click(); };

            delBtn = document.createElement('button');
            delBtn.type = 'button'; delBtn.className = 'btn btn-outline';
            delBtn.textContent = 'Delete';
            delBtn.onclick = function () {
                if (!confirm('Delete the ' + PHOTO_SIDES[side].toLowerCase() + ' photo?')) return;
                showStatus('Deleting...', false);
                handlers.remove(side).then(function (res) {
                    if (res.success) { current = null; refresh(); showStatus('', false); }
                    else showStatus(res.error, true);
                }).catch(function () { showStatus('Network error', true); });
            };

            // When a file is chosen it is uploaded immediately (no extra "save" button)
            input.onchange = function () {
                if (!input.files.length) return;
                showStatus('Uploading...', false);
                handlers.upload(side, input.files[0]).then(function (res) {
                    if (res.success) { current = res.file_path; refresh(); showStatus('Saved', false); }
                    else showStatus(res.error, true);
                    input.value = '';
                }).catch(function () { showStatus('Network error', true); });
            };

            actions.appendChild(addBtn);
            actions.appendChild(delBtn);
            slot.appendChild(actions);
            slot.appendChild(input);
        }

        slot.appendChild(status);
        refresh();
        grid.appendChild(slot);
    });

    container.appendChild(grid);
}

/**
 * loadLoanPhotos()  — fills a container with one photo grid PER ITEM of a loan.
 *   phase = 'pickup' or 'return'.  onLoaded(data) is called with the items afterwards.
 */
function loadLoanPhotos(loanId, phase, container, onLoaded) {
    container.textContent = 'Loading...';
    fetch('api/loan_photos.php?action=list&loan_id=' + loanId + '&phase=' + phase)
        .then(function (res) { return res.json(); })
        .then(function (data) {
            container.innerHTML = '';
            if (!data.success) { container.textContent = data.error; return; }

            data.items.forEach(function (it) {
                var box = document.createElement('div');  box.className = 'photo-item';
                box.setAttribute('data-item', it.item_id);   // lets the Process-return pop-up show only damaged items
                var title = document.createElement('h4');
                title.textContent = it.name + ' (' + it.code + ') x' + it.qty;
                var holder = document.createElement('div');
                box.appendChild(title); box.appendChild(holder);
                container.appendChild(box);

                // Builds the form data for both upload and delete
                function send(action, side, file) {
                    var fd = new FormData();
                    fd.append('action', action);   fd.append('loan_id', loanId);
                    fd.append('item_id', it.item_id); fd.append('phase', phase);
                    fd.append('side', side);
                    if (file) fd.append('photo', file);
                    return fetch('api/loan_photos.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
                }

                buildPhotoSlots(holder, it.photos, {
                    upload: function (side, file) { return send('upload', side, file); },
                    remove: function (side)       { return send('delete', side, null); }
                });
            });
            if (onLoaded) onLoaded(data);
        })
        .catch(function () { container.textContent = 'Could not load the photos.'; });
}

/* ------------------------------------------------------------
   5. ITEM GALLERY + FULL-SCREEN VIEWER  (angles of an item)
   A) <div class="gallery" data-photos='[{"src":"...","label":"Front"}, ...]'> with arrows
      that swap the picture; clicking the picture opens the viewer.
   B) The viewer: big picture, big arrows, thumbnails, keyboard (← → Esc), swipe on phones.
   C) <a class="lb" data-group="x" data-label="Front" href="photo.jpg"> opens the viewer
      with ALL links of the same data-group as its pages.
   ------------------------------------------------------------ */
var lightbox = null;
var lbPhotos = [];
var lbIndex  = 0;
var lbOnChange = null;        // tells the small gallery which photo is shown now

function buildLightbox() {
    if (lightbox) return;
    lightbox = document.createElement('div');
    lightbox.className = 'lightbox';
    lightbox.innerHTML =
        '<button type="button" class="lb-close" aria-label="Close">×</button>' +
        '<div class="lb-stage">' +
            '<div class="lb-frame">' +
                '<button type="button" class="lb-arrow lb-prev" aria-label="Previous angle">‹</button>' +
                '<img alt="">' +
                '<button type="button" class="lb-arrow lb-next" aria-label="Next angle">›</button>' +
            '</div>' +
            '<div class="lb-caption"></div>' +
        '</div>' +
        '<div class="lb-thumbs"></div>';
    document.body.appendChild(lightbox);

    lightbox.querySelector('.lb-close').onclick = closeLightbox;
    lightbox.querySelector('.lb-prev').onclick  = function (e) { e.stopPropagation(); lbShow(lbIndex - 1); };
    lightbox.querySelector('.lb-next').onclick  = function (e) { e.stopPropagation(); lbShow(lbIndex + 1); };

    // Click anywhere on the dark area (outside the picture, the arrows and the thumbnails) closes the viewer
    lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox || e.target.classList.contains('lb-stage')) closeLightbox();
    });

    // Keyboard: ← → change angle, Esc closes
    document.addEventListener('keydown', function (e) {
        if (!lightbox.classList.contains('open')) return;
        if (e.key === 'ArrowLeft')  { e.preventDefault(); lbShow(lbIndex - 1); }
        if (e.key === 'ArrowRight') { e.preventDefault(); lbShow(lbIndex + 1); }
        if (e.key === 'Escape')     closeLightbox();
    });

    // Swipe on phones
    var startX = null;
    lightbox.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
    lightbox.addEventListener('touchend', function (e) {
        if (startX === null) return;
        var dx = e.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 50) lbShow(lbIndex + (dx < 0 ? 1 : -1));
        startX = null;
    });
}

function openLightbox(photos, index, onChange) {
    buildLightbox();
    lbPhotos = photos;
    lbOnChange = onChange || null;

    var thumbs = lightbox.querySelector('.lb-thumbs');
    thumbs.innerHTML = '';
    photos.forEach(function (p, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-label', p.label);
        var im = document.createElement('img');
        im.src = p.src; im.alt = p.label;
        b.appendChild(im);
        b.onclick = function (e) { e.stopPropagation(); lbShow(i); };
        thumbs.appendChild(b);
    });

    var many = photos.length > 1;                       // one picture only -> no arrows / thumbnails
    lightbox.querySelector('.lb-prev').style.display = many ? '' : 'none';
    lightbox.querySelector('.lb-next').style.display = many ? '' : 'none';
    thumbs.style.display = many ? '' : 'none';

    lightbox.classList.add('open');
    lbShow(index);
}

function lbShow(i) {
    lbIndex = (i + lbPhotos.length) % lbPhotos.length;  // wraps: after the last comes the first
    var p = lbPhotos[lbIndex];
    lightbox.querySelector('.lb-stage img').src = p.src;
    lightbox.querySelector('.lb-stage img').alt = p.label;
    lightbox.querySelector('.lb-caption').textContent = p.label + (lbPhotos.length > 1 ? '  ·  ' + (lbIndex + 1) + ' / ' + lbPhotos.length : '');
    lightbox.querySelectorAll('.lb-thumbs button').forEach(function (b, n) { b.classList.toggle('current', n === lbIndex); });
    if (lbOnChange) lbOnChange(lbIndex);
}

function closeLightbox() {
    if (lightbox) lightbox.classList.remove('open');
}

// ---- A) small galleries on cards ----
function initGalleries() {
    document.querySelectorAll('.gallery[data-photos]').forEach(function (box) {
        if (box.getAttribute('data-ready')) return;
        box.setAttribute('data-ready', '1');

        var photos;
        try { photos = JSON.parse(box.getAttribute('data-photos')); } catch (e) { return; }
        if (!photos.length) return;

        var img   = box.querySelector('img');
        var count = box.querySelector('.gallery-count');
        var index = 0;

        function show(i) {
            index = (i + photos.length) % photos.length;
            img.src = photos[index].src;
            img.alt = photos[index].label;
            if (count) count.setAttribute('data-text', photos[index].label + ' · ' + (index + 1) + '/' + photos.length);
        }

        box.querySelectorAll('.gallery-arrow').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                show(index + (btn.classList.contains('next') ? 1 : -1));
            });
        });

        // Click on the picture = big viewer, starting at the angle shown now
        img.addEventListener('click', function () { openLightbox(photos, index, show); });
    });
}

// ---- C) links that open the viewer ----
document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('a.lb') : null;
    if (!link) return;
    e.preventDefault();
    var group = link.getAttribute('data-group');
    var links = [].slice.call(document.querySelectorAll('a.lb')).filter(function (a) { return a.getAttribute('data-group') === group; });
    openLightbox(links.map(function (a) { return { src: a.getAttribute('href'), label: a.getAttribute('data-label') || '' }; }), links.indexOf(link));
});

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initGalleries);
else initGalleries();