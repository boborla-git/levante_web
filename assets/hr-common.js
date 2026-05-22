/*
 * LEVANTE WEB - HR common behaviours
 *
 * Regola architetturale:
 * - i filtri tabellari restano gestiti da includes/layout.php con data-table-filter/data-quick-filter;
 * - i filtri sulle card HR sono gestiti qui con data-card-filter/data-card-filter-item;
 * - le pagine HR non devono duplicare script locali per filtrare card.
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

    function applyCardFilter(input) {
        var target = input.getAttribute('data-card-filter');
        if (!target) {
            return;
        }

        var query = normalizeText(input.value.trim());
        var selector = '[data-card-filter-item="' + target.replace(/"/g, '\\"') + '"]';
        var cards = document.querySelectorAll(selector);
        var visible = 0;

        cards.forEach(function (card) {
            var match = query === '' || getCardSearchText(card).indexOf(query) !== -1;
            card.style.display = match ? '' : 'none';
            if (match) {
                visible += 1;
            }
        });

        var empty = document.querySelector('[data-card-filter-empty="' + target.replace(/"/g, '\\"') + '"]');
        if (empty) {
            empty.style.display = visible === 0 ? '' : 'none';
        }
    }

    document.querySelectorAll('[data-card-filter]').forEach(function (input) {
        applyCardFilter(input);
        input.addEventListener('input', function () {
            applyCardFilter(input);
        });
    });
}());
