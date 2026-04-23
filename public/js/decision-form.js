(function () {
    'use strict';

    function autogrow(el) {
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }

    document.querySelectorAll('.df-autogrow').forEach(function (el) {
        autogrow(el);
        el.addEventListener('input', function () { autogrow(el); });
    });

    // Char counter for the change description
    var changeField = document.querySelector('textarea[data-maxlen]');
    if (changeField) {
        var counter = changeField.closest('.col-12').querySelector('.df-counter__current');
        var max = parseInt(changeField.getAttribute('data-maxlen'), 10) || 2000;
        var update = function () {
            var n = changeField.value.length;
            counter.textContent = n;
            var parent = counter.parentElement;
            parent.classList.toggle('df-counter--near', n > max * 0.85 && n <= max);
            parent.classList.toggle('df-counter--over', n > max);
        };
        changeField.addEventListener('input', update);
        update();
    }

    // Collapsible sections — open if already has values
    document.querySelectorAll('[data-df-collapsible]').forEach(function (section) {
        var header = section.querySelector('[data-df-toggle]');
        var body = section.querySelector('.df-section__body');
        var hasValue = Array.from(section.querySelectorAll('input, textarea')).some(function (el) {
            return el.value && el.value.trim() !== '';
        });
        if (hasValue) {
            section.classList.add('is-open');
            header.setAttribute('aria-expanded', 'true');
            if (body) body.hidden = false;
        }
        header.addEventListener('click', function () {
            var open = section.classList.toggle('is-open');
            header.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (body) body.hidden = !open;
        });
    });

    // Quick-date buttons
    var dateField = document.querySelector('input[type="date"][name$="[followUpDate]"]');
    document.querySelectorAll('[data-df-quick-date]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!dateField) return;
            var v = btn.getAttribute('data-df-quick-date');
            if (v === 'clear') { dateField.value = ''; return; }
            var d = new Date();
            d.setDate(d.getDate() + parseInt(v, 10));
            dateField.value = d.toISOString().slice(0, 10);
        });
    });
})();
