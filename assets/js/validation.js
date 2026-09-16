/*
 * validation.js — lightweight client-side mirror of includes/validation.php.
 *
 * Fast feedback only; the server (includes/validation.php) stays authoritative
 * and re-checks everything. Same rule tokens as the PHP side so the two agree.
 *
 *   const errors = TynorValidate.validate(
 *       { email: 'required|email|max:150', full_name: 'required|min:2' },
 *       { email: form.email.value, full_name: form.full_name.value }
 *   );
 *   // errors === {} when valid, else { field: 'message' }
 *
 *   TynorValidate.bindForm(formEl, rulesMap);  // shows inline messages on submit
 */
(function (global) {
  'use strict';

  function label(field) {
    var s = field.replace(/_/g, ' ');
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function isEmail(v) { return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v); }
  function isDate(v)  { return /^\d{4}-\d{2}-\d{2}$/.test(v) && !isNaN(Date.parse(v)); }

  function checkField(field, value, spec) {
    value = (value == null ? '' : String(value)).trim();
    var checks = Array.isArray(spec) ? spec : String(spec).split('|');
    var required = checks.indexOf('required') >= 0;
    var L = label(field);

    if (!value) { return required ? (L + ' is required.') : null; }

    for (var i = 0; i < checks.length; i++) {
      if (checks[i] === 'required' || checks[i] === '') { continue; }
      var idx = checks[i].indexOf(':');
      var name = idx < 0 ? checks[i] : checks[i].slice(0, idx);
      var arg  = idx < 0 ? null : checks[i].slice(idx + 1);

      if (name === 'email'   && !isEmail(value))                 { return L + ' must be a valid email address.'; }
      if (name === 'int'     && !/^-?\d+$/.test(value))          { return L + ' must be a whole number.'; }
      if (name === 'numeric' && isNaN(Number(value)))            { return L + ' must be a number.'; }
      if (name === 'max'     && value.length > +arg)             { return L + ' must be at most ' + arg + ' characters.'; }
      if (name === 'min'     && value.length < +arg)             { return L + ' must be at least ' + arg + ' characters.'; }
      if (name === 'maxval'  && Number(value) > +arg)            { return L + ' must be at most ' + arg + '.'; }
      if (name === 'minval'  && Number(value) < +arg)            { return L + ' must be at least ' + arg + '.'; }
      if (name === 'in'      && String(arg).split(',').indexOf(value) < 0) { return L + ' is not a valid option.'; }
      if (name === 'date'    && !isDate(value))                  { return L + ' must be a valid date (YYYY-MM-DD).'; }
      if (name === 'phone'   && !/^[0-9+\-\s()]{7,20}$/.test(value)) { return L + ' must be a valid phone number.'; }
    }
    return null;
  }

  function validate(rulesMap, data) {
    var errors = {};
    Object.keys(rulesMap).forEach(function (field) {
      var e = checkField(field, data[field], rulesMap[field]);
      if (e) { errors[field] = e; }
    });
    return errors;
  }

  // Optional convenience: block submit + show inline messages next to inputs.
  function bindForm(form, rulesMap) {
    if (!form) { return; }
    form.addEventListener('submit', function (ev) {
      var data = {};
      Object.keys(rulesMap).forEach(function (f) {
        var el = form.elements[f];
        data[f] = el ? el.value : '';
      });
      var errors = validate(rulesMap, data);
      form.querySelectorAll('[data-validation-msg]').forEach(function (n) { n.remove(); });
      if (Object.keys(errors).length) {
        ev.preventDefault();
        Object.keys(errors).forEach(function (f) {
          var el = form.elements[f];
          if (!el) { return; }
          var msg = document.createElement('div');
          msg.setAttribute('data-validation-msg', '1');
          msg.style.color = '#c00';
          msg.style.fontSize = '0.85em';
          msg.style.marginTop = '4px';
          msg.textContent = errors[f];
          el.insertAdjacentElement('afterend', msg);
        });
      }
    });
  }

  global.TynorValidate = { validate: validate, field: checkField, bindForm: bindForm };
})(window);