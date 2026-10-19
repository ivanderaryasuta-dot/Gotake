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
