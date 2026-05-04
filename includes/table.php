<?php
declare(strict_types=1);

if (!function_exists('hrTableEscape')) {
    function hrTableEscape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('renderHrTableSection')) {
    /**
     * Renderizza una sezione tabellare standard per le pagine HR.
     *
     * Config:
     * - title: string
     * - subtitle: string|null
     * - rows: array<int,array<string,mixed>>
     * - columns: array<int,array{label:string,style?:string,class?:string}>
     * - row_renderer: callable(array $row): void
     * - empty_message: string
     * - section_class: string
     * - title_class: string|null
     * - responsive_class: string
     * - table_class: string
     */
    function renderHrTableSection(array $config): void
    {
        $title = (string)($config['title'] ?? '');
        $subtitle = (string)($config['subtitle'] ?? '');
        $rows = is_array($config['rows'] ?? null) ? $config['rows'] : [];
        $columns = is_array($config['columns'] ?? null) ? $config['columns'] : [];
        $rowRenderer = $config['row_renderer'] ?? null;
        $emptyMessage = (string)($config['empty_message'] ?? 'Nessun dato disponibile.');
        $sectionClass = (string)($config['section_class'] ?? 'card');
        $titleClass = (string)($config['title_class'] ?? '');
        $responsiveClass = (string)($config['responsive_class'] ?? 'table-responsive');
        $tableClass = (string)($config['table_class'] ?? 'table');

        if (!is_callable($rowRenderer)) {
            throw new InvalidArgumentException('renderHrTableSection richiede row_renderer callable.');
        }
        ?>
        <section class="<?= hrTableEscape($sectionClass) ?>">
            <?php if ($titleClass !== ''): ?>
                <div class="<?= hrTableEscape($titleClass) ?>">
                    <div>
                        <h2><?= hrTableEscape($title) ?></h2>
                        <?php if ($subtitle !== ''): ?>
                            <p class="text-muted"><?= hrTableEscape($subtitle) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <h2><?= hrTableEscape($title) ?></h2>
                <?php if ($subtitle !== ''): ?>
                    <p class="meta"><?= hrTableEscape($subtitle) ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (count($rows) === 0): ?>
                <p class="text-muted"><?= hrTableEscape($emptyMessage) ?></p>
            <?php else: ?>
                <div class="<?= hrTableEscape($responsiveClass) ?>">
                    <table class="<?= hrTableEscape($tableClass) ?>">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                    <?php
                                    $label = (string)($column['label'] ?? '');
                                    $style = trim((string)($column['style'] ?? ''));
                                    $class = trim((string)($column['class'] ?? ''));
                                    ?>
                                    <th<?= $class !== '' ? ' class="' . hrTableEscape($class) . '"' : '' ?><?= $style !== '' ? ' style="' . hrTableEscape($style) . '"' : '' ?>><?= hrTableEscape($label) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $rowRenderer($row); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }
}
