/* PantryPivot — progressive enhancement. App works fully with JS off (form POSTs);
   this only adds autocomplete + ajax-free refreshing convenience. No frameworks. */
(function () {
  'use strict';

  var nameInput = document.getElementById('add-name');
  if (!nameInput || typeof fetch !== 'function') return;

  // ---- autocomplete (datalist fallback keeps JS-off flow intact) ----
  var tId = null;
  nameInput.setAttribute('autocomplete', 'off');
  var list = document.createElement('datalist');
  list.id = 'suggestions';
  nameInput.parentNode.insertBefore(list, nameInput.nextSibling);
  nameInput.setAttribute('list', 'suggestions');

  nameInput.addEventListener('input', function () {
    var q = nameInput.value.trim();
    if (tId) clearTimeout(tId);
    if (q.length < 2) return;
    tId = setTimeout(function () {
      fetch('/api/suggest?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          list.innerHTML = '';
          (data.suggestions || []).forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.name;
            if (s.canonical !== s.name) opt.label = '→ ' + s.canonical;
            list.appendChild(opt);
          });
        })
        .catch(function () { /* JS-off flow still works */ });
    }, 150);
  });

  // ---- highlight have/missing rows already server-rendered: nothing to do.
  // Everything else is plain forms; the server re-renders on POST redirect.
})();