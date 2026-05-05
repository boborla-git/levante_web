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
                <div class="section-head-actions">
                    <?php foreach ($actions as $action): ?>
                        <?php
                        $href = (string)($action['href'] ?? '#');
                        $label = (string)($action['label'] ?? '');
                        $actionIcon = trim((string)($action['icon'] ?? ''));
                        $actionClass = trim((string)($action['class'] ?? 'btn btn-light'));
                        ?>
                        <a class="<?= hrUiEscape($actionClass) ?>" href="<?= hrUiEscape($href) ?>">
                            <?php if ($actionIcon !== ''): ?><i class="<?= hrUiEscape($actionIcon) ?>" aria-hidden="true"></i> <?php endif; ?><?= hrUiEscape($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
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

