/* auth.js — progressive enhancement shared by login + register.
 *
 *   1) Password show/hide for every .pw-field that contains a .pw-toggle button.
 *   2) Caps Lock hint for any input carrying data-caps-hint="<hintElementId>".
 *
 * Markup-driven and zero-config, so both auth pages reuse it as-is. The
 * controls stay hidden until this runs, so the no-JS experience is a normal
 * (fully functional) password field. */
(function () {
  // 1) Show/hide password.
  document.querySelectorAll('.pw-field').forEach(function (field) {
    var input = field.querySelector('input');
    var btn   = field.querySelector('.pw-toggle');
    if (!input || !btn) return;
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
      var label = reveal ? 'Hide password' : 'Show password';
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      input.focus();
    });
  });

  // 2) Caps Lock warning (announced politely via the hint's role="status").
  document.querySelectorAll('input[data-caps-hint]').forEach(function (input) {
    var hint = document.getElementById(input.getAttribute('data-caps-hint'));
    if (!hint) return;
    var check = function (e) {
      if (typeof e.getModifierState === 'function') hint.hidden = !e.getModifierState('CapsLock');
    };
    input.addEventListener('keydown', check);
    input.addEventListener('keyup', check);
    input.addEventListener('blur', function () { hint.hidden = true; });
  });
})();