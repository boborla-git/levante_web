<?php
declare(strict_types=1);

if (!function_exists('hrUiEscape')) {
    function hrUiEscape(?string $valore): string
    {
        return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('renderHrPageHeader')) {
    /**
     * Renderizza l'intestazione standard delle pagine operative.
     *
     * Configurazione attesa:
     * - title: string
     * - subtitle: string|null
     * - icon: string|null              classe icona, es. "la la-check-circle"
     * - class: string|null             classi del contenitore
     * - tag: string|null               div|section
     * - actions: array[]               href,label,icon,class
     * - extra_html: string|null        contenuto HTML aggiuntivo sotto il sottotitolo
     */
    function renderHrPageHeader(array $config): void
    {
        $tag = strtolower((string)($config['tag'] ?? 'section'));
        if (!in_array($tag, ['div', 'section'], true)) {
            $tag = 'section';
        }

        $class = trim((string)($config['class'] ?? 'card card-compact'));
        $title = (string)($config['title'] ?? '');
        $subtitle = (string)($config['subtitle'] ?? '');
        $icon = trim((string)($config['icon'] ?? ''));
        $actions = is_array($config['actions'] ?? null) ? $config['actions'] : [];
        $extraHtml = (string)($config['extra_html'] ?? '');
        $innerClass = trim((string)($config['inner_class'] ?? ''));
        ?>
        <<?= $tag ?> class="<?= hrUiEscape($class) ?>">
            <?php if ($innerClass !== ''): ?>
                <div class="<?= hrUiEscape($innerClass) ?>">
            <?php endif; ?>
            <div>
                <h1><?php if ($icon !== ''): ?><i class="<?= hrUiEscape($icon) ?>" aria-hidden="true"></i> <?php endif; ?><?= hrUiEscape($title) ?></h1>
                <?php if ($subtitle !== ''): ?>
                    <p class="text-muted meta"><?= hrUiEscape($subtitle) ?></p>
                <?php endif; ?>
                <?= $extraHtml ?>
            </div>

            <?php if (count($actions) > 0): ?>
                <?php renderHrToolbar($actions, 'section-head-actions hr-toolbar'); ?>
            <?php endif; ?>
            <?php if ($innerClass !== ''): ?>
                </div>
            <?php endif; ?>
        </<?= $tag ?>>
        <?php
    }
}

if (!function_exists('renderHrSummaryLine')) {
    /**
     * Renderizza una riga riepilogativa compatta.
     * Ogni elemento accetta: value, label.
     */
    function renderHrSummaryLine(array $items, string $class = 'hr-summary-line', string $ariaLabel = 'Riepilogo'): void
    {
        ?>
        <section class="<?= hrUiEscape($class) ?>" aria-label="<?= hrUiEscape($ariaLabel) ?>">
            <?php foreach ($items as $item): ?>
                <span><strong><?= (int)($item['value'] ?? 0) ?></strong> <?= hrUiEscape((string)($item['label'] ?? '')) ?></span>
            <?php endforeach; ?>
        </section>
        <?php
    }
}

if (!function_exists('renderHrAlert')) {
    /**
     * Renderizza un messaggio operativo standard.
     * Tipi supportati: success, danger, warning, info.
     */
    function renderHrAlert(string $message, string $type = 'info'): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $allowed = ['success', 'danger', 'warning', 'info'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }
        ?>
        <div class="alert alert-<?= hrUiEscape($type) ?>" role="status"><?= hrUiEscape($message) ?></div>
        <?php
    }
}


if (!function_exists('renderHrSectionHeader')) {
    /**
     * Renderizza un'intestazione standard per card/sezioni operative.
     *
     * Configurazione attesa:
     * - title: string
     * - subtitle: string|null
     * - icon: string|null
     * - class: string|null
     * - actions: array[] href,label,icon,class
     * - filter_html: string|null
     */
    function renderHrSectionHeader(array $config): void
    {
        $title = (string)($config['title'] ?? '');
        $subtitle = (string)($config['subtitle'] ?? '');
        $icon = trim((string)($config['icon'] ?? ''));
        $class = trim((string)($config['class'] ?? 'hr-section-header'));
        $actions = is_array($config['actions'] ?? null) ? $config['actions'] : [];
        $filterHtml = (string)($config['filter_html'] ?? '');
        ?>
        <div class="<?= hrUiEscape($class) ?>">
            <div class="hr-section-title">
                <h2><?php if ($icon !== ''): ?><i class="<?= hrUiEscape($icon) ?>" aria-hidden="true"></i> <?php endif; ?><?= hrUiEscape($title) ?></h2>
                <?php if ($subtitle !== ''): ?>
                    <p class="text-muted meta"><?= hrUiEscape($subtitle) ?></p>
                <?php endif; ?>
            </div>

            <?php if (count($actions) > 0 || trim($filterHtml) !== ''): ?>
                <div class="hr-section-tools">
                    <?php if (count($actions) > 0): ?>
                        <?php renderHrToolbar($actions, 'section-head-actions hr-toolbar'); ?>
                    <?php endif; ?>
                    <?= $filterHtml ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderHrCardOpen')) {
    /** Apre una card standard del modulo HR. */
    function renderHrCardOpen(string $class = 'card hr-card'): void
    {
        ?>
        <section class="<?= hrUiEscape($class) ?>">
        <?php
    }
}

if (!function_exists('renderHrCardClose')) {
    /** Chiude una card standard del modulo HR. */
    function renderHrCardClose(): void
    {
        ?>
        </section>
        <?php
    }
}


if (!function_exists('renderHrToolbar')) {
    /**
     * Renderizza una toolbar azioni standard, usabile in header, card e righe operative.
     * Ogni azione accetta: href, label, icon, class, type, name, value, attributes.
     */
    function renderHrToolbar(array $actions, string $class = 'hr-toolbar', string $tag = 'div'): void
    {
        if (count($actions) === 0) {
            return;
        }
        $tag = strtolower($tag);
        if (!in_array($tag, ['div', 'nav'], true)) {
            $tag = 'div';
        }
        ?>
        <<?= $tag ?> class="<?= hrUiEscape($class) ?>">
            <?php foreach ($actions as $action): ?>
                <?php renderHrAction($action); ?>
            <?php endforeach; ?>
        </<?= $tag ?>>
        <?php
    }
}

if (!function_exists('renderHrAction')) {
    /** Renderizza un singolo pulsante/link azione coerente con il design system. */
    function renderHrAction(array $action): void
    {
        $label = (string)($action['label'] ?? '');
        $icon = trim((string)($action['icon'] ?? ''));
        $class = trim((string)($action['class'] ?? 'btn btn-light'));
        $href = trim((string)($action['href'] ?? ''));
        $type = trim((string)($action['type'] ?? 'button'));
        $name = trim((string)($action['name'] ?? ''));
        $value = (string)($action['value'] ?? '');
        $attributes = is_array($action['attributes'] ?? null) ? $action['attributes'] : [];
        $attrHtml = '';
        foreach ($attributes as $attrName => $attrValue) {
            $attrName = preg_replace('/[^a-zA-Z0-9_:\-]/', '', (string)$attrName);
            if ($attrName === '') {
                continue;
            }
            if ($attrValue === true) {
                $attrHtml .= ' ' . $attrName;
            } elseif ($attrValue !== false && $attrValue !== null) {
                $attrHtml .= ' ' . $attrName . '="' . hrUiEscape((string)$attrValue) . '"';
            }
        }
        if ($href !== ''): ?>
            <a class="<?= hrUiEscape($class) ?>" href="<?= hrUiEscape($href) ?>"<?= $attrHtml ?>>
                <?php if ($icon !== ''): ?><i class="<?= hrUiEscape($icon) ?>" aria-hidden="true"></i> <?php endif; ?><?= hrUiEscape($label) ?>
            </a>
        <?php else: ?>
            <button class="<?= hrUiEscape($class) ?>" type="<?= hrUiEscape($type) ?>"<?= $name !== '' ? ' name="' . hrUiEscape($name) . '"' : '' ?><?= $name !== '' ? ' value="' . hrUiEscape($value) . '"' : '' ?><?= $attrHtml ?>>
                <?php if ($icon !== ''): ?><i class="<?= hrUiEscape($icon) ?>" aria-hidden="true"></i> <?php endif; ?><?= hrUiEscape($label) ?>
            </button>
        <?php endif;
    }
}
