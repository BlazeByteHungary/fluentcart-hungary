/* FluentCart Hungary — admin workspace behaviour (progressive enhancement only). */
(function () {
    'use strict';

    var strings = window.FchAdmin || {};

    function confirmations() {
        return {
            delete: strings.confirmDelete || 'Are you sure you want to delete the stored value? This cannot be undone.',
            reissue: strings.confirmReissue || 'This creates a new provider document. Continue?',
            reconcile: strings.confirmReconcile || 'Run the read-back reconciliation for the selected items?'
        };
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-fch-confirm]');
        if (!button) {
            return;
        }
        var kind = button.getAttribute('data-fch-confirm');
        var message = confirmations()[kind];
        if (message && !window.confirm(message)) {
            event.preventDefault();
            event.stopPropagation();
        }
    });

    document.addEventListener('change', function (event) {
        var master = event.target.closest('[data-fch-select-all]');
        if (!master) {
            if (event.target.classList.contains('fch-row-check')) {
                var group = event.target.name;
                var all = document.querySelectorAll('input[name="' + group + '"]');
                var checked = document.querySelectorAll('input[name="' + group + '"]:checked');
                var toggles = document.querySelectorAll('[data-fch-select-all="' + group.replace(/\[\]$/, '') + '"]');
                for (var t = 0; t < toggles.length; t++) {
                    toggles[t].checked = all.length > 0 && checked.length === all.length;
                }
            }
            return;
        }
        var name = master.getAttribute('data-fch-select-all');
        var rows = document.querySelectorAll('input[name="' + name + '[]"]');
        for (var i = 0; i < rows.length; i++) {
            rows[i].checked = master.checked;
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }
        var submitter = event.submitter || form.querySelector('button[type="submit"]');
        if (!submitter || submitter.classList.contains('fch-hu-no-busy')) {
            return;
        }
        if (!form.checkValidity || form.checkValidity()) {
            // Never disable the submitter: a disabled control is excluded from the submitted payload,
            // which would silently drop its name/value (e.g. fch_do=delete:field).
            submitter.dataset.fchOriginal = submitter.textContent;
            submitter.textContent = strings.working || 'Working…';
            window.setTimeout(function () {
                if (submitter.dataset.fchOriginal) {
                    submitter.textContent = submitter.dataset.fchOriginal;
                }
            }, 8000);
        }
    });
})();
