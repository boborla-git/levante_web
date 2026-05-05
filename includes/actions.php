<?php
declare(strict_types=1);

if (!function_exists('hrActionButtonEscape')) {
    function hrActionButtonEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('renderHrActionButton')) {
    /**
     * Rende un pulsante azione standard per le pagine HR.
     *
     * Variants supportate:
     * - primary
     * - outline-danger
     * - light
     * - outline-primary
     */
    function renderHrActionButton(array $options): string
    {
        $type = (string)($options['type'] ?? 'button');
        $variant = (string)($options['variant'] ?? 'primary');
        $label = (string)($options['label'] ?? 'Azione');
        $icon = (string)($options['icon'] ?? '');
        $size = (string)($options['size'] ?? 'sm');
        $disabled = !empty($options['disabled']);
        $extraClass = trim((string)($options['class'] ?? ''));
        $attributes = $options['attributes'] ?? [];

        $allowedTypes = ['button', 'submit', 'reset'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'button';
        }

        $classes = ['btn'];
        if ($size !== '') {
            $classes[] = 'btn-' . $size;
        }
        $classes[] = 'btn-' . $variant;
        if ($extraClass !== '') {
            $classes[] = $extraClass;
        }

        $attrHtml = '';
        if (is_array($attributes)) {
            foreach ($attributes as $name => $value) {
                $name = preg_replace('/[^a-zA-Z0-9_:\-]/', '', (string)$name);
                if ($name === '') {
                    continue;
                }
                if ($value === true) {
                    $attrHtml .= ' ' . $name;
                } elseif ($value !== false && $value !== null) {
                    $attrHtml .= ' ' . $name . '="' . hrActionButtonEscape((string)$value) . '"';
                }
            }
        }

        $iconHtml = $icon !== ''
            ? '<i class="' . hrActionButtonEscape($icon) . '" aria-hidden="true"></i> '
            : '';

        return '<button type="' . hrActionButtonEscape($type) . '" class="' . hrActionButtonEscape(implode(' ', $classes)) . '"'
            . ($disabled ? ' disabled' : '')
            . $attrHtml
            . '>' . $iconHtml . hrActionButtonEscape($label) . '</button>';
    }
}

if (!function_exists('renderHrPrimaryActionButton')) {
    function renderHrPrimaryActionButton(string $label, string $icon = '', bool $disabled = false, string $size = 'sm'): string
    {
        return renderHrActionButton([
            'type' => 'submit',
            'variant' => 'primary',
            'label' => $label,
            'icon' => $icon,
            'disabled' => $disabled,
            'size' => $size,
        ]);
    }
}

if (!function_exists('renderHrDangerOutlineActionButton')) {
    function renderHrDangerOutlineActionButton(string $label, string $icon = 'la la-times', bool $disabled = false, string $size = 'sm'): string
    {
        return renderHrActionButton([
            'type' => 'submit',
            'variant' => 'outline-danger',
            'label' => $label,
            'icon' => $icon,
            'disabled' => $disabled,
            'size' => $size,
        ]);
    }
}
