<?php

declare(strict_types=1);

if (!function_exists('adminEscape')) {
    function adminEscape(?string $valore): string
    {
        return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('renderAdminTabs')) {
    /**
     * Renderizza la navigazione comune dell'area Admin.
     *
     * Chiavi attive supportate:
     * - utenti
     * - ruoli_utenti
     * - permessi_ruoli
     */
    function renderAdminTabs(string $active): void
    {
        $tabs = [
            'utenti' => [
                'href' => 'utenti.php',
                'icon' => 'la la-users',
                'label' => 'Utenti',
            ],
            'ruoli_utenti' => [
                'href' => 'ruoli_utenti.php',
                'icon' => 'la la-user-tag',
                'label' => 'Ruoli utenti',
            ],
            'permessi_ruoli' => [
                'href' => 'permessi_ruoli.php',
                'icon' => 'la la-key',
                'label' => 'Permessi ruoli',
            ],
        ];
        ?>
        <div class="admin-tabs" aria-label="Sezioni amministrazione utenti">
            <span class="admin-tabs-label">Sezione:</span>
            <?php foreach ($tabs as $key => $tab): ?>
                <a class="<?= $key === $active ? 'active' : '' ?>" href="<?= adminEscape($tab['href']) ?>">
                    <i class="<?= adminEscape($tab['icon']) ?>" aria-hidden="true"></i> <?= adminEscape($tab['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderAdminQuickFilter')) {
    /** Renderizza il filtro rapido comune delle tabelle admin. */
    function renderAdminQuickFilter(string $id, string $tableId, string $placeholder = 'Cerca in tutte le colonne...'): void
    {
        ?>
        <div class="form-group admin-list-filter">
            <label for="<?= adminEscape($id) ?>">Filtro rapido</label>
            <input
                type="search"
                id="<?= adminEscape($id) ?>"
                placeholder="<?= adminEscape($placeholder) ?>"
                autocomplete="off"
                data-table-filter="<?= adminEscape($tableId) ?>"
            >
        </div>
        <?php
    }
}
