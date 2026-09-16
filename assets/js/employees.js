/**
 * employees.js — behavior for dashboard/employees.php
 *
 * Externalized on purpose: the site sends a CSP of `script-src 'self'`,
 * which blocks inline <script> blocks AND inline onclick/onchange/onkeydown
 * attributes. Everything below used to live inline in employees.php; moving
 * it here (loaded via <script src>) is what makes it actually run.
 *
 * PHP values the page needs (BASE_URL, flash message, import summary, etc.)
 * are passed in via data-* attributes on <body>, read at the top of
 * initToastsAndFlags() below — never via inline script.
 */
(function () {
  'use strict';

  var BASE_URL = document.body.getAttribute('data-base-url') || '';

  // ---------------------------------------------------------------------
  // Column toggle panel
  // ---------------------------------------------------------------------
  function toggleColumnPanel() {
    var panel = document.getElementById('column-toggle-panel');
    if (!panel) return;
    panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
  }

  // Remembers which columns are hidden so the state survives htmx table
  // refreshes (search-as-you-type and pagination replace #emp-results,
  // which recreates #emp-table with every column visible again).
  var hiddenColumns = new Set();

  function applyColumnVisibility(table) {
    if (!table) return;
    var rows = table.querySelectorAll('tr');
    rows.forEach(function (row) {
      var cells = row.children;
      hiddenColumns.forEach(function (colIndex) {
        if (cells[colIndex]) cells[colIndex].style.display = 'none';
      });
    });
  }

  function toggleColumn(colIndex, show) {
    if (show) hiddenColumns.delete(colIndex);
    else hiddenColumns.add(colIndex);

    var table = document.getElementById('emp-table');
    if (!table) return;
    var rows = table.querySelectorAll('tr');
    rows.forEach(function (row) {
      var cells = row.children;
      if (cells[colIndex]) cells[colIndex].style.display = show ? '' : 'none';
    });
  }

  // ---------------------------------------------------------------------
  // Add Employee — ID gate → full form
  // ---------------------------------------------------------------------
  function openAddGate() {
    var addForm = document.getElementById('add-form');
    if (addForm) addForm.classList.add('hidden');
    var msg = document.getElementById('gate-msg');
    if (msg) { msg.textContent = ''; msg.style.color = ''; }
    var gate = document.getElementById('emp-id-gate');
    if (gate) gate.classList.remove('hidden');
    var input = document.getElementById('gate-emp-id');
    if (input) { input.value = ''; input.focus(); }
  }

  function checkEmpId() {
    var input = document.getElementById('gate-emp-id');
    var msg   = document.getElementById('gate-msg');
    var btn   = document.getElementById('gate-continue');
    var empId = (input ? input.value : '').trim();
    if (!empId) {
      if (msg) { msg.style.color = '#b91c1c'; msg.textContent = 'Please enter an Employee ID.'; }
      return;
    }
    var tokenEl = document.querySelector('#add-emp-form input[name="csrf_token"]');
    var body = new URLSearchParams();
    body.set('emp_id', empId);
    body.set('csrf_token', tokenEl ? tokenEl.value : '');
    if (btn) { btn.disabled = true; btn.textContent = 'Checking…'; }
    if (msg) { msg.style.color = '#64748b'; msg.textContent = 'Checking availability…'; }

    fetch(BASE_URL + '/dashboard/check_emp_id.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response.' }; }); })
      .then(function (d) {
        if (btn) { btn.disabled = false; btn.textContent = 'Check & continue'; }
        if (!d.ok)    { if (msg) { msg.style.color = '#b91c1c'; msg.textContent = d.error || 'Could not check the Employee ID.'; } return; }
        if (!d.valid) { if (msg) { msg.style.color = '#b91c1c'; msg.textContent = d.message || 'Invalid Employee ID.'; } return; }
        if (d.exists) { if (msg) { msg.style.color = '#b91c1c'; msg.textContent = d.message; } return; }
        proceedToAddForm(empId);
      })
      .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'Check & continue'; }
        if (msg) { msg.style.color = '#b91c1c'; msg.textContent = 'Network error — please try again.'; }
      });
  }

  function proceedToAddForm(empId) {
    var gate    = document.getElementById('emp-id-gate');
    var addForm = document.getElementById('add-form');
    var idField = document.getElementById('form-emp-id');
    var note    = document.getElementById('form-emp-id-note');
    if (idField) { idField.value = empId; idField.setAttribute('readonly', 'readonly'); idField.style.background = '#f1f5f9'; }
    if (note)    { note.style.color = '#15803d'; note.textContent = 'Verified available. To change it, cancel and start again.'; }
    if (gate)    gate.classList.add('hidden');
    if (addForm) addForm.classList.remove('hidden');
    var nameField = document.querySelector('#add-emp-form input[name="emp_name"]');
    if (nameField) nameField.focus();
  }

  // ---------------------------------------------------------------------
  // One-time page-load flags (toast message / reopen add form / import summary)
  // formerly rendered as inline <script>, now read from data-* on <body>.
  // ---------------------------------------------------------------------
  function initToastsAndFlags() {
    var body = document.body;

    var toastMsg = body.getAttribute('data-toast-msg');
    if (toastMsg && window.showToast) {
      window.showToast(toastMsg, body.getAttribute('data-toast-type') === 'error' ? 'error' : 'success');
    }

    if (body.getAttribute('data-reopen-add') === '1') {
      var f = document.getElementById('add-form');
      if (f) { f.classList.remove('hidden'); f.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }

    var summaryRaw = body.getAttribute('data-import-summary');
    if (summaryRaw) {
      try {
        var summary = JSON.parse(summaryRaw);
        var panel = document.getElementById('io-panel');
        if (panel) panel.classList.remove('hidden');
        var hasErrors = summary.errors && summary.errors.length;
        if (!hasErrors && window.showSuccess) {
          window.showSuccess('Import complete: ' + (summary.inserted || 0) + ' added, ' + (summary.updated || 0) + ' updated.');
        } else if (hasErrors && window.showWarning) {
          window.showWarning('Import finished with ' + summary.errors.length + ' notice(s). See details in the panel.');
        }
        location.hash = 'import';
      } catch (e) {
        /* malformed/absent summary — nothing to show */
      }
    }
  }

  // ---------------------------------------------------------------------
  // Wire everything up (replaces the removed onclick/onchange/onkeydown attrs)
  // ---------------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', function () {
    var btnToggleColumns = document.getElementById('btn-toggle-columns');
    if (btnToggleColumns) btnToggleColumns.addEventListener('click', toggleColumnPanel);

    var btnIo = document.getElementById('btn-io');
    if (btnIo) btnIo.addEventListener('click', function () {
      var panel = document.getElementById('io-panel');
      if (panel) panel.classList.toggle('hidden');
    });

    var btnAdd = document.getElementById('btn-add');
    if (btnAdd) btnAdd.addEventListener('click', openAddGate);

    var gateCancel = document.getElementById('gate-cancel');
    if (gateCancel) gateCancel.addEventListener('click', function () {
      var gate = document.getElementById('emp-id-gate');
      if (gate) gate.classList.add('hidden');
    });

    var gateContinue = document.getElementById('gate-continue');
    if (gateContinue) gateContinue.addEventListener('click', checkEmpId);

    var gateInput = document.getElementById('gate-emp-id');
    if (gateInput) gateInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); checkEmpId(); }
    });

    var addFormCancel = document.getElementById('add-form-cancel');
    if (addFormCancel) addFormCancel.addEventListener('click', function () {
      var f = document.getElementById('add-form');
      if (f) f.classList.add('hidden');
    });

    document.querySelectorAll('.col-toggle-checkbox').forEach(function (cb) {
      cb.addEventListener('change', function () {
        toggleColumn(parseInt(cb.getAttribute('data-col-index'), 10), cb.checked);
      });
    });

    var addEmpForm = document.getElementById('add-emp-form');
    if (addEmpForm) addEmpForm.addEventListener('submit', function () {
      var b = document.getElementById('add-save-btn');
      if (b) { b.disabled = true; b.textContent = 'Saving…'; }
    });

    if (window.lucide && typeof lucide.createIcons === 'function') lucide.createIcons();
    applyColumnVisibility(document.getElementById('emp-table'));
    initToastsAndFlags();
  });

  // htmx replaces #emp-results (search-as-you-type, pagination) — re-apply
  // icons and column visibility to the freshly rendered table each time.
  document.body.addEventListener('htmx:afterSwap', function () {
    if (window.lucide && typeof lucide.createIcons === 'function') lucide.createIcons();
    applyColumnVisibility(document.getElementById('emp-table'));
  });
})();