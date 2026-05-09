<?php
declare(strict_types=1);

if (!function_exists('hrBadgeEscape')) {
    function hrBadgeEscape(?string $valore): string
    {
        return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('hrStatusBadgeClass')) {
    function hrStatusBadgeClass(string $codice): string
    {
        return match (strtoupper(trim($codice))) {
            'APPROVATA', 'APPROVATO', 'ATTIVA', 'ATTIVO', 'LETTA', 'OK', 'NO' => 'status-ok',
            'RIFIUTATA', 'RIFIUTATO', 'RESPINTA', 'RESPINTO', 'KO', 'ERRORE' => 'status-ko',
            'IN_ATTESA', 'PENDENTE', 'PENDENTI', 'OBBLIGATORIO', 'DA_LEGGERE' => 'status-wait',
            'ANNULLATA', 'ANNULLATO', 'CHIUSA', 'CHIUSO', 'DISATTIVA', 'DISATTIVO' => 'status-neutral',
            default => 'status-neutral',
        };
    }
}

if (!function_exists('hrStatusBadgeLabel')) {
    function hrStatusBadgeLabel(string $codice): string
    {
        return match (strtoupper(trim($codice))) {
            'IN_ATTESA' => 'In attesa',
            'APPROVATA' => 'Approvata',
            'APPROVATO' => 'Approvato',
            'RIFIUTATA' => 'Rifiutata',
            'RIFIUTATO' => 'Rifiutato',
            'ANNULLATA' => 'Annullata',
            'ANNULLATO' => 'Annullato',
            'BOZZA' => 'Bozza',
            'SCADUTA' => 'Scaduta',
            'SCADUTO' => 'Scaduto',
            'ATTIVA' => 'Attiva',
            'ATTIVO' => 'Attivo',
            'DISATTIVA' => 'Disattiva',
            'DISATTIVO' => 'Disattivo',
            'CHIUSA' => 'Chiusa',
            'CHIUSO' => 'Chiuso',
            'LETTA' => 'Letta',
            'DA_LEGGERE' => 'Da leggere',
            'OBBLIGATORIO' => 'Obbligatorio',
            'NO' => 'No',
            default => trim($codice) !== '' ? trim($codice) : '—',
        };
    }
}

if (!function_exists('renderHrStatusBadge')) {
    /**
     * Rende un badge stato standard coerente con il design system del sito.
     *
     * Opzioni supportate:
     * - class: classi CSS aggiuntive
     * - style: stile inline opzionale per casi puntuali già esistenti
     */
    function renderHrStatusBadge(string $codice, ?string $label = null, array $opzioni = []): string
    {
        $classi = trim('status-badge ' . hrStatusBadgeClass($codice) . ' ' . (string)($opzioni['class'] ?? ''));
        $style = trim((string)($opzioni['style'] ?? ''));
        $styleAttr = $style !== '' ? ' style="' . hrBadgeEscape($style) . '"' : '';
        $testo = $label !== null && trim($label) !== '' ? $label : hrStatusBadgeLabel($codice);

        return '<span class="' . hrBadgeEscape($classi) . '"' . $styleAttr . '>' . hrBadgeEscape($testo) . '</span>';
    }
}

if (!function_exists('renderHrBooleanBadge')) {
    function renderHrBooleanBadge(bool $valore, string $labelSi, string $labelNo = '—'): string
    {
        if ($valore) {
            return renderHrStatusBadge('OK', $labelSi);
        }

        return $labelNo === '—'
            ? '<span class="muted">-</span>'
            : renderHrStatusBadge('NEUTRAL', $labelNo);
    }
}
