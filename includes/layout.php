<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function layoutLoadMenuResources(): array
{
    $pdo = db();
    $stmt = $pdo->query("
        SELECT
            id_risorsa,
            codice_risorsa,
            descrizione,
            tipo_risorsa,
            id_risorsa_padre,
            percorso,
            icona,
            visibile_menu,
            ordinamento,
            attivo
        FROM aut_risorse
        WHERE attivo = 1
          AND tipo_risorsa IN ('menu', 'pagina')
        ORDER BY ordinamento, codice_risorsa
    ");

    return $stmt->fetchAll();
}

function layoutIconClass(array $node): string
{
    $icon = trim((string)($node['icona'] ?? ''));
    if ($icon === '') {
        return '';
    }

    $icon = preg_replace('/[^a-zA-Z0-9_\\- ]/', '', $icon) ?? '';
    $icon = trim($icon);
    if ($icon === '') {
        return '';
    }

    if (strpos($icon, 'la ') === 0 || strpos($icon, 'lab ') === 0 || strpos($icon, 'lar ') === 0 || strpos($icon, 'las ') === 0) {
        return $icon;
    }

    return 'la ' . $icon;
}

function layoutRenderIcon(array $node): void
{
    $iconClass = layoutIconClass($node);
    if ($iconClass === '') {
        return;
    }
    ?>
    <i class="<?= htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') ?> menu-icon" aria-hidden="true"></i>
    <?php
}

function layoutRenderLabel(array $node): void
{
    layoutRenderIcon($node);
    $codiceNodo = (string)($node['codice_risorsa'] ?? '');
    $labelNodo = (string)($node['descrizione'] ?? '');

    if ($codiceNodo === 'menu.profilo') {
        $nomeUtenteMenu = trim((string)($GLOBALS['layout_nome_utente'] ?? ''));
        if ($nomeUtenteMenu !== '') {
            $labelNodo = $nomeUtenteMenu;
        }
    }
    ?>
    <span class="menu-text"><?= htmlspecialchars($labelNodo, ENT_QUOTES, 'UTF-8') ?></span>
    <?php
    if (in_array((string)($node['codice_risorsa'] ?? ''), ['pagina.notifiche', 'menu.profilo'], true)) {
        $numeroNotifiche = isset($GLOBALS['layout_notifiche_non_lette']) ? (int)$GLOBALS['layout_notifiche_non_lette'] : 0;
        layoutRenderNotificationBadge($numeroNotifiche);
    }
}

function layoutUnreadNotificationsCount(int $idUtente): int
{
    if ($idUtente <= 0) {
        return 0;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM hr_notifiche_destinatari nd
             INNER JOIN hr_notifiche n ON n.id_notifica = nd.id_notifica
             WHERE nd.id_utente = :id_utente
               AND nd.letta = 0"
        );
        $stmt->execute(['id_utente' => $idUtente]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function layoutRenderNotificationBadge(int $numero): void
{
    if ($numero <= 0) {
        return;
    }

    $label = $numero > 99 ? '99+' : (string)$numero;
    ?>
    <span class="notification-badge" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> notifiche non lette"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
    <?php
}

function layoutUserDisplayName(int $idUtente): string
{
    if ($idUtente <= 0) {
        return '';
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT nome, cognome, username
             FROM aut_utenti
             WHERE id_utente = :id_utente
             LIMIT 1"
        );
        $stmt->execute(['id_utente' => $idUtente]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return '';
        }

        $nome = trim((string)($row['nome'] ?? ''));
        $cognome = trim((string)($row['cognome'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        $nominativo = trim($nome . ' ' . $cognome);

        if ($nominativo !== '') {
            return $nominativo;
        }

        return $username;
    } catch (Throwable $e) {
        return '';
    }
}

function layoutNodeCanOpen(array $node): bool
{
    $percorso = trim((string)($node['percorso'] ?? ''));
    if ($percorso === '') {
        return false;
    }

    $codice = trim((string)($node['codice_risorsa'] ?? ''));
    if ($codice === '') {
        return false;
    }

    return haPermesso($codice, 'read');
}

function layoutBuildChildrenMap(array $rows): array
{
    $children = [];

    foreach ($rows as $row) {
        $parentId = null;
        if (isset($row['id_risorsa_padre']) && $row['id_risorsa_padre'] !== null) {
            $parentId = (int)$row['id_risorsa_padre'];
            if ($parentId === 0) {
                $parentId = null;
            }
        }

        $children[$parentId][] = $row;
    }

    foreach ($children as $parentId => $nodes) {
        usort($children[$parentId], static function (array $a, array $b): int {
            $ordA = (int)($a['ordinamento'] ?? 0);
            $ordB = (int)($b['ordinamento'] ?? 0);

            if ($ordA === $ordB) {
                return strcmp((string)($a['codice_risorsa'] ?? ''), (string)($b['codice_risorsa'] ?? ''));
            }

            return $ordA <=> $ordB;
        });
    }

    return $children;
}

function layoutNodeFile(array $node): string
{
    $path = trim((string)($node['percorso'] ?? ''));
    if ($path === '') {
        return '';
    }

    return basename($path);
}

function layoutIsActiveNode(array $node, array $childrenMap, string $currentPage): bool
{
    if (layoutNodeFile($node) === $currentPage) {
        return true;
    }

    $nodeId = (int)($node['id_risorsa'] ?? 0);
    foreach ($childrenMap[$nodeId] ?? [] as $child) {
        if (layoutIsActiveNode($child, $childrenMap, $currentPage)) {
            return true;
        }
    }

    return false;
}

function layoutNodeVisibleInMenu(array $node): bool
{
    return (int)($node['visibile_menu'] ?? 0) === 1;
}

function layoutFilterMenuTree(array $nodes, array $childrenMap): array
{
    $output = [];

    foreach ($nodes as $node) {
        $nodeId = (int)$node['id_risorsa'];
        $children = layoutFilterMenuTree($childrenMap[$nodeId] ?? [], $childrenMap);
        $canOpen = layoutNodeCanOpen($node);
        $visibleInMenu = layoutNodeVisibleInMenu($node);
        $type = (string)($node['tipo_risorsa'] ?? '');

        if (!$visibleInMenu) {
            if ($type === 'menu') {
                foreach ($children as $child) {
                    $output[] = $child;
                }
            }
            continue;
        }

        if ($type === 'pagina' && !$canOpen) {
            continue;
        }

        if ($type === 'menu' && count($children) === 0 && !$canOpen) {
            continue;
        }

        $node['children'] = $children;
        $node['can_open'] = $canOpen;
        $output[] = $node;
    }

    return $output;
}

function layoutRenderDesktopDropdownItems(array $nodes, array $childrenMap, string $currentPage, int $level = 0): void
{
    foreach ($nodes as $node) {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $hasChildren = count($children) > 0;
        $isActive = layoutIsActiveNode($node, $childrenMap, $currentPage);
        $canOpen = (bool)($node['can_open'] ?? false);
        $href = ltrim((string)($node['percorso'] ?? ''), '/');
        ?>
        <div class="topnav-menu-item level-<?= $level ?>">
            <?php if ($canOpen): ?>
                <a href="/<?= htmlspecialchars($href) ?>" class="<?= $isActive ? 'active' : '' ?>">
                    <?php layoutRenderLabel($node); ?>
                </a>
            <?php else: ?>
                <div class="topnav-menu-label <?= $isActive ? 'active' : '' ?>">
                    <?php layoutRenderLabel($node); ?>
                </div>
            <?php endif; ?>

            <?php if ($hasChildren): ?>
                <div class="topnav-subtree level-<?= $level + 1 ?>">
                    <?php layoutRenderDesktopDropdownItems($children, $childrenMap, $currentPage, $level + 1); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

function layoutRenderDesktopMenu(array $tree, array $childrenMap, string $currentPage): void
{
    foreach ($tree as $root) {
        $children = is_array($root['children'] ?? null) ? $root['children'] : [];
        $isActive = layoutIsActiveNode($root, $childrenMap, $currentPage);
        $canOpen = (bool)($root['can_open'] ?? false);
        $label = (string)($root['descrizione'] ?? '');
        $href = ltrim((string)($root['percorso'] ?? ''), '/');
        $simplified = false;

        if (!$canOpen && count($children) === 1) {
            $child = $children[0];
            $childCanOpen = (bool)($child['can_open'] ?? false);
            $childLabel = (string)($child['descrizione'] ?? '');

            if ($childCanOpen && $childLabel === $label) {
                $simplified = true;
                $canOpen = true;
                $href = ltrim((string)($child['percorso'] ?? ''), '/');
            }
        }

        if (($canOpen && count($children) === 0) || $simplified) {
            ?>
            <a href="/<?= htmlspecialchars($href) ?>" class="topnav-link <?= $isActive ? 'active' : '' ?>">
                    <?php layoutRenderLabel($root); ?>
            </a>
            <?php
            continue;
        }
        ?>
        <div class="topnav-dropdown <?= $isActive ? 'active' : '' ?>">
            <?php if ($canOpen): ?>
                <a href="/<?= htmlspecialchars($href) ?>" class="topnav-link topnav-parent <?= $isActive ? 'active' : '' ?>">
                    <?php layoutRenderLabel($root); ?>
                </a>
            <?php else: ?>
                <button type="button" class="topnav-link topnav-parent <?= $isActive ? 'active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                    <?php layoutRenderLabel($root); ?>
                </button>
            <?php endif; ?>

            <?php if (count($children) > 0): ?>
                <div class="topnav-dropdown-menu">
                    <?php layoutRenderDesktopDropdownItems($children, $childrenMap, $currentPage, 0); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

function layoutRenderMobileTree(array $nodes, array $childrenMap, string $currentPage, int $level = 0): void
{
    foreach ($nodes as $node) {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $hasChildren = count($children) > 0;
        $isActive = layoutIsActiveNode($node, $childrenMap, $currentPage);
        $canOpen = (bool)($node['can_open'] ?? false);
        $label = (string)($node['descrizione'] ?? '');
        $href = ltrim((string)($node['percorso'] ?? ''), '/');
        $classes = 'drawer-item level-' . $level . ($isActive ? ' active' : '');

        if ($hasChildren) {
            ?>
            <details class="drawer-group level-<?= $level ?>" <?= $isActive ? 'open' : '' ?>>
                <summary class="<?= htmlspecialchars($classes) ?>">
                    <?php layoutRenderLabel($node); ?>
                </summary>
                <div class="drawer-children">
                    <?php if ($canOpen): ?>
                        <a href="/<?= htmlspecialchars($href) ?>" class="drawer-direct-link">
                            <i class="la la-external-link-alt menu-icon" aria-hidden="true"></i><span>Apri <?= htmlspecialchars($label) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php layoutRenderMobileTree($children, $childrenMap, $currentPage, $level + 1); ?>
                </div>
            </details>
            <?php
            continue;
        }

        if ($canOpen) {
            ?>
            <a href="/<?= htmlspecialchars($href) ?>" class="<?= htmlspecialchars($classes) ?>">
                    <?php layoutRenderLabel($node); ?>
            </a>
            <?php
            continue;
        }
        ?>
        <div class="<?= htmlspecialchars($classes) ?>">
                    <?php layoutRenderLabel($node); ?>
        </div>
        <?php
    }
}

function layoutHeader(string $titoloPagina, string $titoloApplicazione = 'Levante'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $utenteLoggato = isset($_SESSION['utente_id']) && (int)$_SESSION['utente_id'] > 0;
    $idUtenteLoggato = $utenteLoggato ? (int)$_SESSION['utente_id'] : 0;
    $notificheNonLette = $utenteLoggato ? layoutUnreadNotificationsCount($idUtenteLoggato) : 0;
    $GLOBALS['layout_notifiche_non_lette'] = $notificheNonLette;
    $GLOBALS['layout_nome_utente'] = $utenteLoggato ? layoutUserDisplayName($idUtenteLoggato) : '';
    $paginaCorrente = basename($_SERVER['PHP_SELF'] ?? '');
    $menuTree = [];
    $menuChildrenMap = [];

    if ($utenteLoggato) {
        $menuRows = layoutLoadMenuResources();
        $menuChildrenMap = layoutBuildChildrenMap($menuRows);
        $menuTree = layoutFilterMenuTree($menuChildrenMap[null] ?? [], $menuChildrenMap);
    }
    ?>
    <!doctype html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($titoloPagina) ?> - <?= htmlspecialchars($titoloApplicazione) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/assets/vendor/line-awesome/css/line-awesome.min.css">
        <link rel="stylesheet" href="/assets/style.css">
        <link rel="stylesheet" href="/assets/design-system.css">
        <link rel="icon" type="image/png" href="/assets/favicon.png">
        <link rel="shortcut icon" href="/assets/favicon.png">
        <link rel="apple-touch-icon" href="/assets/favicon.png">

        <style>
            :root { --ravioli-blue: #0068c9; --ravioli-blue-hover: #0068c9; --ravioli-yellow: #ffd400; --ravioli-yellow-hover: #ffd400; }
            .page-content .btn:not(.btn-light):not(.btn-danger):not(.hr-icon-btn),
            .page-content button:not(.topnav-parent):not(.nav-drawer-close):not(.nav-drawer-toggle):not(.btn-light):not(.btn-danger):not(.hr-icon-btn),
            .page-content input[type="submit"] { background: var(--ravioli-blue); color: var(--ravioli-yellow); border-color: var(--ravioli-blue); }
            .page-content .btn:not(.btn-light):not(.btn-danger):not(.hr-icon-btn):hover,
            .page-content button:not(.topnav-parent):not(.nav-drawer-close):not(.nav-drawer-toggle):not(.btn-light):not(.btn-danger):not(.hr-icon-btn):hover,
            .page-content input[type="submit"]:hover { background: var(--ravioli-blue-hover); color: var(--ravioli-yellow-hover); border-color: var(--ravioli-blue-hover); }
            .btn-ravioli-primary { background: var(--ravioli-blue) !important; color: var(--ravioli-yellow) !important; border-color: var(--ravioli-blue) !important; }
            .btn-ravioli-primary:hover, .btn-ravioli-primary:focus-visible { background: var(--ravioli-blue) !important; color: var(--ravioli-yellow) !important; border-color: var(--ravioli-yellow) !important; box-shadow: 0 0 0 2px rgba(255, 212, 0, 0.55) !important; outline: none !important; }
            .btn-ravioli-secondary { background: var(--ravioli-yellow) !important; color: var(--ravioli-blue) !important; border-color: var(--ravioli-yellow) !important; }
            .btn-ravioli-secondary:hover, .btn-ravioli-secondary:focus-visible { background: var(--ravioli-yellow) !important; color: var(--ravioli-blue) !important; border-color: var(--ravioli-blue) !important; box-shadow: 0 0 0 2px rgba(0, 104, 201, 0.28) !important; outline: none !important; }
        </style>
    </head>
    <body>
    <header class="topbar">
        <div class="container topbar-inner">
            <div class="topbar-left">
                <button type="button" class="nav-drawer-toggle btn-ravioli-primary" aria-expanded="false" aria-controls="mobile-nav-drawer" aria-label="Apri menu" title="Menu">
                    <i class="la la-bars" aria-hidden="true"></i>
                </button>
                <a class="brand" href="/index.php" aria-label="<?= htmlspecialchars($titoloApplicazione) ?>">
                    <img src="/assets/img/logo-ravioli.png" alt="Ravioli S.p.A.">
                </a>

                <?php if ($utenteLoggato && count($menuTree) > 0): ?>
                    <nav class="topnav" aria-label="Navigazione principale">
                        <?php layoutRenderDesktopMenu($menuTree, $menuChildrenMap, $paginaCorrente); ?>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($utenteLoggato && count($menuTree) > 0): ?>
        <div class="nav-drawer-backdrop"></div>
        <aside class="nav-drawer" id="mobile-nav-drawer" aria-label="Menu mobile">
            <div class="nav-drawer-head">
                <div class="nav-drawer-title">Menu</div>
                <button type="button" class="nav-drawer-close" aria-label="Chiudi menu">×</button>
            </div>
            <div class="nav-drawer-body">
                <?php layoutRenderMobileTree($menuTree, $menuChildrenMap, $paginaCorrente, 0); ?>
            </div>
        </aside>
    <?php endif; ?>

    <main class="page-content">
        <div class="container">
    <?php
}

function layoutHrAssenzeTipologieRules(): array
{
    try {
        $pdo = db();
        $stmt = $pdo->query(
            "SELECT id_tipologia_evento,
                    codice,
                    descrizione,
                    consente_giorni,
                    consente_ore,
                    motivazione_obbligatoria,
                    avviso_richiedente
             FROM hr_tipologie_evento
             WHERE attivo = 1"
        );
        $rules = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['id_tipologia_evento'];
            $rules[$id] = [
                'codice' => (string)$row['codice'],
                'descrizione' => (string)$row['descrizione'],
                'consente_giorni' => (int)$row['consente_giorni'] === 1,
                'consente_ore' => (int)$row['consente_ore'] === 1,
                'motivazione_obbligatoria' => (int)$row['motivazione_obbligatoria'] === 1,
                'avviso_richiedente' => trim((string)($row['avviso_richiedente'] ?? '')),
            ];
        }
        return $rules;
    } catch (Throwable $e) {
        return [];
    }
}

function layoutRenderAssenzeRegoleScript(): void
{
    if (basename($_SERVER['PHP_SELF'] ?? '') !== 'assenze.php') {
        return;
    }

    $rules = layoutHrAssenzeTipologieRules();
    if (count($rules) === 0) {
        return;
    }
    ?>
    <script>
    window.hrTipologieAssenzeRegole = <?= json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    (function () {
        const rules = window.hrTipologieAssenzeRegole || {};
        const tipologia = document.getElementById('id_tipologia_evento');
        const modalita = document.getElementById('modalita');
        const note = document.getElementById('note_richiedente');
        const form = document.getElementById('form-richiesta-assenza');
        if (!tipologia || !modalita) return;

        const modalitaOriginali = Array.from(modalita.options).map(function (option) {
            return { value: option.value, text: option.textContent };
        });

        function ensureAvvisoBox() {
            let box = document.getElementById('hr-tipologia-avviso');
            if (!box) {
                box = document.createElement('div');
                box.id = 'hr-tipologia-avviso';
                box.className = 'info-box';
                box.style.marginTop = '12px';
                box.style.display = 'none';
                const layout = document.querySelector('.hr-request-layout');
                if (layout) {
                    layout.parentNode.insertBefore(box, layout.nextSibling);
                }
            }
            return box;
        }

        function optionLabel(value) {
            return value === 'ore' ? 'Ore' : 'Giorni';
        }

        function aggiornaModalita(rule) {
            const precedente = modalita.value;
            modalita.innerHTML = '';
            const consentite = [];
            if (!rule || rule.consente_giorni) consentite.push('giorni');
            if (!rule || rule.consente_ore) consentite.push('ore');
            if (consentite.length === 0) consentite.push('giorni');

            consentite.forEach(function (value) {
                const opt = document.createElement('option');
                opt.value = value;
                const originale = modalitaOriginali.find(function (item) { return item.value === value; });
                opt.textContent = originale ? originale.text : optionLabel(value);
                modalita.appendChild(opt);
            });

            if (consentite.indexOf(precedente) !== -1) {
                modalita.value = precedente;
            } else {
                modalita.value = consentite[0];
            }
            modalita.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function aggiornaAvviso(rule) {
            const box = ensureAvvisoBox();
            const testo = rule && rule.avviso_richiedente ? String(rule.avviso_richiedente).trim() : '';
            if (testo !== '') {
                box.textContent = testo;
                box.style.display = '';
            } else {
                box.textContent = '';
                box.style.display = 'none';
            }
        }

        function aggiornaNotaObbligatoria(rule) {
            if (!note) return;
            const required = !!(rule && rule.motivazione_obbligatoria);
            note.required = required;
            const label = document.querySelector('label[for="note_richiedente"]');
            if (label) {
                label.textContent = required ? 'Note del richiedente *' : 'Note del richiedente';
            }
        }

        function applicaRegole() {
            const id = parseInt(tipologia.value || '0', 10);
            const rule = rules[id] || null;
            aggiornaModalita(rule);
            aggiornaAvviso(rule);
            aggiornaNotaObbligatoria(rule);
        }

        tipologia.addEventListener('change', applicaRegole);
        if (form) {
            form.addEventListener('submit', function (event) {
                const id = parseInt(tipologia.value || '0', 10);
                const rule = rules[id] || null;
                if (rule && rule.motivazione_obbligatoria && note && note.value.trim() === '') {
                    event.preventDefault();
                    note.focus();
                    alert('Compila le note del richiedente per questa tipologia.');
                }
            });
        }
        applicaRegole();
    })();
    </script>
    <?php
}

function layoutFooter(): void
{
    ?>
        </div>
    </main>

    <footer class="footer-note">
        <div class="container">Levante - Area riservata</div>
    </footer>

    <script>
        (function () {
            var html = document.documentElement;
            var drawer = document.querySelector('.nav-drawer');
            var backdrop = document.querySelector('.nav-drawer-backdrop');
            var toggle = document.querySelector('.nav-drawer-toggle');
            var closeBtn = document.querySelector('.nav-drawer-close');

            function openDrawer() {
                if (!drawer) return;
                html.classList.add('drawer-open');
                if (toggle) toggle.setAttribute('aria-expanded', 'true');
            }

            function closeDrawer() {
                html.classList.remove('drawer-open');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }

            if (toggle) toggle.addEventListener('click', openDrawer);
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
            if (backdrop) backdrop.addEventListener('click', closeDrawer);

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeDrawer();
                    document.querySelectorAll('.topnav-dropdown.is-open').forEach(function (item) {
                        item.classList.remove('is-open');
                        var button = item.querySelector('.topnav-parent');
                        if (button) button.setAttribute('aria-expanded', 'false');
                    });
                }
            });

            document.querySelectorAll('.nav-drawer details.drawer-group').forEach(function (group) {
                group.addEventListener('toggle', function () {
                    if (!group.open) return;

                    document.querySelectorAll('.nav-drawer details.drawer-group[open]').forEach(function (other) {
                        if (other !== group && !group.contains(other)) {
                            other.open = false;
                        }
                    });
                });
            });

            var desktopParents = document.querySelectorAll('.topnav-dropdown > .topnav-parent');
            desktopParents.forEach(function (btn) {
                btn.addEventListener('click', function (event) {
                    if (window.innerWidth <= 1100) return;

                    event.preventDefault();
                    var dropdown = btn.closest('.topnav-dropdown');
                    var isOpen = dropdown.classList.contains('is-open');

                    document.querySelectorAll('.topnav-dropdown.is-open').forEach(function (item) {
                        if (item !== dropdown) {
                            item.classList.remove('is-open');
                            var other = item.querySelector('.topnav-parent');
                            if (other) other.setAttribute('aria-expanded', 'false');
                        }
                    });

                    dropdown.classList.toggle('is-open', !isOpen);
                    btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                });
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('.topnav')) {
                    document.querySelectorAll('.topnav-dropdown.is-open').forEach(function (item) {
                        item.classList.remove('is-open');
                        var button = item.querySelector('.topnav-parent');
                        if (button) button.setAttribute('aria-expanded', 'false');
                    });
                }
            });

            function normalizzaFiltroRapido(valore) {
                return (valore || '')
                    .toString()
                    .toLowerCase()
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '');
            }

            function applicaFiltroRapido(input, table) {
                var filtro = normalizzaFiltroRapido(input.value);
                var righe = table.querySelectorAll('tbody tr');
                var visibili = 0;

                righe.forEach(function (row) {
                    var testo = normalizzaFiltroRapido(row.textContent);
                    var mostra = testo.indexOf(filtro) !== -1;
                    row.style.display = mostra ? '' : 'none';
                    if (mostra) visibili += 1;
                });

                var empty = document.querySelector('[data-quick-filter-empty="' + table.id + '"]');
                if (empty) {
                    empty.style.display = visibili === 0 ? '' : 'none';
                }
            }

            document.querySelectorAll('[data-table-filter], [data-quick-filter]').forEach(function (input) {
                var tableId = input.getAttribute('data-table-filter') || input.getAttribute('data-quick-filter');
                if (!tableId) return;

                var table = document.getElementById(tableId);
                if (!table) return;

                applicaFiltroRapido(input, table);
                input.addEventListener('input', function () {
                    applicaFiltroRapido(input, table);
                });
            });

            window.addEventListener('resize', function () {
                if (window.innerWidth > 1100) {
                    closeDrawer();
                }
            });
        })();
    </script>
    <?php layoutRenderAssenzeRegoleScript(); ?>
    </body>
    </html>
    <?php
}
