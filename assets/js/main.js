'use strict';


/* APP_BASE_URL is injected by includes/header.php as APP_BASE_URL */


document.addEventListener('DOMContentLoaded', function () {


    // ── Sidebar toggle ──────────────────────────────────────
    const sidebarToggle = document.getElementById('sidebarToggle');
    const wrapper       = document.getElementById('wrapper');
    if (sidebarToggle && wrapper) {
        // Restore state from localStorage
        if (localStorage.getItem('sidebarCollapsed') === '1') {
            wrapper.classList.add('toggled');
        }
        sidebarToggle.addEventListener('click', function () {
            wrapper.classList.toggle('toggled');
            localStorage.setItem('sidebarCollapsed', wrapper.classList.contains('toggled') ? '1' : '0');
        });
    }


    // ── Mark active sidebar links ──────────────────────────
    const current = window.location.pathname;
    document.querySelectorAll('.sidebar-link').forEach(function (link) {
        const href = link.getAttribute('href') || '';
        if (!href) return;
        const linkPath = href.replace(APP_BASE_URL, '').split('?')[0];
        if (linkPath && current.endsWith(linkPath)) {
            link.classList.add('active');
        }
    });


    // ── Auto-dismiss success alerts after 5s ───────────────
    document.querySelectorAll('.alert.alert-success').forEach(function (el) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });


    // ── Confirm data-confirm on links/buttons ───────────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            const msg = this.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(msg)) e.preventDefault();
        });
    });


    // ── AJAX Employee Search ───────────────────────────────
    const searchInput   = document.getElementById('empSearch');
    const searchResults = document.getElementById('searchResults');
    if (searchInput && searchResults) {
        let timer;
        searchInput.addEventListener('input', function () {
            clearTimeout(timer);
            const q = this.value.trim();
            if (q.length < 2) { searchResults.innerHTML = ''; return; }
            timer = setTimeout(function () {
                fetch(APP_BASE_URL + '/employees/search_ajax.php?q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(function (data) {
                    if (data.employees && data.employees.length > 0) {
                        let html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
                                 + '<thead><tr><th>Code</th><th>Name</th><th>Dept</th><th>DOB</th><th></th></tr></thead><tbody>';
                        data.employees.forEach(function (emp) {
                            html += '<tr>'
                                + '<td><code>' + emp.emp_code + '</code></td>'
                                + '<td>' + emp.emp_name + '</td>'
                                + '<td class="text-muted small">' + (emp.department_name || '—') + '</td>'
                                + '<td class="small">' + (emp.dob || '—') + '</td>'
                                + '<td><a href="' + APP_BASE_URL + '/employees/view.php?code=' + encodeURIComponent(emp.emp_code) + '" class="btn btn-xs btn-outline-primary btn-sm py-0">View</a></td>'
                                + '</tr>';
                        });
                        html += '</tbody></table></div>';
                        searchResults.innerHTML = html;
                    } else {
                        searchResults.innerHTML = '<p class="text-muted small py-2">No employees found.</p>';
                    }
                })
                .catch(() => {
                    searchResults.innerHTML = '<p class="text-danger small py-2">Search unavailable.</p>';
                });
            }, 350);
        });
    }


    // ── Password show/hide toggle ───────────────────────────
    document.querySelectorAll('[data-toggle-pwd]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = document.getElementById(this.getAttribute('data-toggle-pwd'));
            if (!target) return;
            const icon = this.querySelector('i');
            if (target.type === 'password') {
                target.type = 'text';
                if (icon) icon.className = 'bi bi-eye-slash';
            } else {
                target.type = 'password';
                if (icon) icon.className = 'bi bi-eye';
            }
        });
    });
});

