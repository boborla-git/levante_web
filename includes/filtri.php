<?php

declare(strict_types=1);

if (!function_exists('hrFiltriEscape')) {
    function hrFiltriEscape(mixed $valore): string
    {
        return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('renderHrFiltri')) {
    /**
     * Renderizza il box filtri standard condiviso del portale.
     *
     * Configurazione attesa:
     * - action: string
     * - method: string, default get
     * - active: bool
     * - hidden: array<string,mixed>
     * - fields: array<int,array{name:string,label:string,type:string,value:mixed,options?:array,placeholder?:string,id?:string}>
     * - reset_url: string
     * - submit_label: string, default Applica
     * - reset_label: string, default Pulisci
     */
    function renderHrFiltri(array $config): void
    {
        $action = (string)($config['action'] ?? '');
        $method = (string)($config['method'] ?? 'get');
        $active = (bool)($config['active'] ?? false);
        $hidden = is_array($config['hidden'] ?? null) ? $config['hidden'] : [];
        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $resetUrl = (string)($config['reset_url'] ?? $action);
        $submitLabel = (string)($config['submit_label'] ?? 'Applica');
        $resetLabel = (string)($config['reset_label'] ?? 'Pulisci');
        ?>
<section class="card approvals-filters">
    <div class="approvals-filters-header">
        <div class="approvals-filters-title">
            <i class="la la-filter" aria-hidden="true"></i>
            <span>Filtri</span>
            <?php if ($active): ?>
                <span class="badge badge-warning">attivi</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="<?= hrFiltriEscape($method) ?>" action="<?= hrFiltriEscape($action) ?>" class="approvals-filter-grid">
        <?php foreach ($hidden as $name => $value): ?>
            <input type="hidden" name="<?= hrFiltriEscape($name) ?>" value="<?= hrFiltriEscape($value) ?>">
        <?php endforeach; ?>

        <?php foreach ($fields as $field): ?>
            <?php
            $name = (string)($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $id = (string)($field['id'] ?? ('filtro_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name)));
            $label = (string)($field['label'] ?? $name);
            $type = (string)($field['type'] ?? 'text');
            $value = (string)($field['value'] ?? '');
            $placeholder = (string)($field['placeholder'] ?? '');
            ?>
            <label for="<?= hrFiltriEscape($id) ?>">
                <span><?= hrFiltriEscape($label) ?></span>
                <?php if ($type === 'select'): ?>
                    <select name="<?= hrFiltriEscape($name) ?>" id="<?= hrFiltriEscape($id) ?>">
                        <?php foreach ((array)($field['options'] ?? []) as $option): ?>
                            <?php
                            $optionValue = (string)($option['value'] ?? '');
                            $optionLabel = (string)($option['label'] ?? $optionValue);
                            $selected = array_key_exists('selected', $option)
                                ? (bool)$option['selected']
                                : ($optionValue === $value);
                            ?>
                            <option value="<?= hrFiltriEscape($optionValue) ?>" <?= $selected ? 'selected' : '' ?>><?= hrFiltriEscape($optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input
                        type="<?= hrFiltriEscape($type) ?>"
                        name="<?= hrFiltriEscape($name) ?>"
                        id="<?= hrFiltriEscape($id) ?>"
                        value="<?= hrFiltriEscape($value) ?>"
                        <?= $placeholder !== '' ? 'placeholder="' . hrFiltriEscape($placeholder) . '"' : '' ?>
                    >
                <?php endif; ?>
            </label>
        <?php endforeach; ?>

        <div class="approvals-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="la la-search" aria-hidden="true"></i> <?= hrFiltriEscape($submitLabel) ?></button>
            <a class="btn btn-outline-primary btn-sm" href="<?= hrFiltriEscape($resetUrl) ?>"><i class="la la-undo" aria-hidden="true"></i> <?= hrFiltriEscape($resetLabel) ?></a>
        </div>
    </form>
</section>
        <?php
    }
}
