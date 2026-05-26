/*
 * LEVANTE WEB - HR/Admin common behaviours
 *
 * Regola architetturale:
 * - i filtri tabellari restano gestiti da includes/layout.php con data-table-filter/data-quick-filter;
 * - i filtri sulle card HR/Admin sono gestiti qui con data-card-filter/data-card-filter-item;
 * - durante la transizione supportiamo anche le card Admin storiche già presenti,
 *   evitando duplicazioni future nelle singole pagine PHP.
 */
(function () {
    'use strict';

    function normalizeText(value) {
        return (value || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    function getCardSearchText(card) {
        return normalizeText(
            card.getAttribute('data-search-text') ||
            card.getAttribute('data-search') ||
            card.textContent ||
            ''
        );
    }

    function filterCards(input, cards, emptyElement) {
        var query = normalizeText(input.value.trim());
        var visible = 0;

        cards.forEach(function (card) {
            var match = query === '' || getCardSearchText(card).indexOf(query) !== -1;
            card.style.display = match ? '' : 'none';
            if (match) {
                visible += 1;
            }
        });

        if (emptyElement) {
            emptyElement.style.display = visible === 0 ? '' : 'none';
        }
    }

    function bindCardFilter(input, cards, emptyElement) {
        if (!input || !cards || cards.length === 0 || input.getAttribute('data-common-filter-bound') === '1') {
            return;
        }

        input.setAttribute('data-common-filter-bound', '1');
        filterCards(input, cards, emptyElement || null);
        input.addEventListener('input', function () {
            filterCards(input, cards, emptyElement || null);
        });
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return value.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }

    document.querySelectorAll('[data-card-filter]').forEach(function (input) {
        var target = input.getAttribute('data-card-filter');
        if (!target) {
            return;
        }

        var selector = '[data-card-filter-item="' + cssEscape(target) + '"]';
        var cards = Array.prototype.slice.call(document.querySelectorAll(selector));
        var empty = document.querySelector('[data-card-filter-empty="' + cssEscape(target) + '"]');
        bindCardFilter(input, cards, empty);
    });

    /* Compatibilità controllata con le pagine Admin già esistenti. */
    [
        {
            inputId: 'utentiSearch',
            cardSelector: '#utentiCards .admin-user-card'
        },
        {
            inputId: 'ruoliUtentiSearch',
            cardSelector: '#ruoliUtentiCards .admin-role-user-card'
        }
    ].forEach(function (config) {
        var input = document.getElementById(config.inputId);
        var cards = Array.prototype.slice.call(document.querySelectorAll(config.cardSelector));
        bindCardFilter(input, cards, null);
    });
}());
