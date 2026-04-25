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
            ordinamento,
            attivo
        FROM aut_risorse
        WHERE attivo = 1
          AND tipo_risorsa IN ('menu', 'pagina')
        ORDER BY ordinamento, codice_risorsa
    ");

    return $stmt->fetchAll();
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

function layoutFilterMenuTree(array $nodes, array $childrenMap): array
{
    $output = [];

    foreach ($nodes as $node) {
        $nodeId = (int)$node['id_risorsa'];
        $children = layoutFilterMenuTree($childrenMap[$nodeId] ?? [], $childrenMap);
        $canOpen = layoutNodeCanOpen($node);

        if (!$canOpen && count($children) === 0) {
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
        $label = (string)($node['descrizione'] ?? '');
        $href = ltrim((string)($node['percorso'] ?? ''), '/');
        ?>
        <div class="topnav-menu-item level-<?= $level ?>">
            <?php if ($canOpen): ?>
                <a href="/<?= htmlspecialchars($href) ?>" class="<?= $isActive ? 'active' : '' ?>">
                    <?= htmlspecialchars($label) ?>
                </a>
            <?php else: ?>
                <div class="topnav-menu-label <?= $isActive ? 'active' : '' ?>">
                    <?= htmlspecialchars($label) ?>
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
                <?= htmlspecialchars($label) ?>
            </a>
            <?php
            continue;
        }
        ?>
        <div class="topnav-dropdown <?= $isActive ? 'active' : '' ?>">
            <?php if ($canOpen): ?>
                <a href="/<?= htmlspecialchars($href) ?>" class="topnav-link topnav-parent <?= $isActive ? 'active' : '' ?>">
                    <?= htmlspecialchars($label) ?>
                </a>
            <?php else: ?>
                <button type="button" class="topnav-link topnav-parent <?= $isActive ? 'active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                    <?= htmlspecialchars($label) ?>
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
                    <span><?= htmlspecialchars($label) ?></span>
                </summary>
                <div class="drawer-children">
                    <?php if ($canOpen): ?>
                        <a href="/<?= htmlspecialchars($href) ?>" class="drawer-direct-link">
                            Apri <?= htmlspecialchars($label) ?>
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
                <?= htmlspecialchars($label) ?>
            </a>
            <?php
            continue;
        }
        ?>
        <div class="<?= htmlspecialchars($classes) ?>">
            <?= htmlspecialchars($label) ?>
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
        <link rel="stylesheet" href="/assets/style.css">
        <link rel="icon" type="image/png" href="/assets/favicon.png">
        <link rel="shortcut icon" href="/assets/favicon.png">
        <link rel="apple-touch-icon" href="/assets/favicon.png">
    </head>
    <body>
    <header class="topbar">
        <div class="container topbar-inner">
            <div class="topbar-left">
                <button type="button" class="nav-drawer-toggle" aria-expanded="false" aria-controls="mobile-nav-drawer" aria-label="Apri menu" title="Menu" style="background:#005baa;color:#f6c500;border-color:#005baa;display:inline-flex;align-items:center;justify-content:center;gap:0;width:54px;height:38px;padding:0;">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" style="display:block;stroke:currentColor;stroke-width:2.5;fill:none;stroke-linecap:round;">
                        <path d="M4 7h16M4 12h16M4 17h16"></path>
                    </svg>
                </button>
                <a class="brand" href="/index.php" aria-label="<?= htmlspecialchars($titoloApplicazione) ?>">
                    <img src="/assets/img/logo-ravioli.png" alt="Ravioli S.p.A.">
                </a>

                <?php if ($utenteLoggato && count($menuTree) > 0): ?>
                    <nav class="topnav" aria-label="Navigazione principale">
                        <?php layoutRenderDesktopMenu($menuTree, $menuChildrenMap, $paginaCorrente); ?>

                        <div class="topnav-dropdown <?= $paginaCorrente === 'cambia_password.php' ? 'active' : '' ?>">
                            <button type="button" class="topnav-link topnav-parent <?= $paginaCorrente === 'cambia_password.php' ? 'active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                                Utente
                            </button>
                            <div class="topnav-dropdown-menu">
                                <a href="/cambia_password.php" class="<?= $paginaCorrente === 'cambia_password.php' ? 'active' : '' ?>">Cambia password</a>
                                <a href="/logout.php">Logout</a>
                            </div>
                        </div>
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
                <div class="nav-drawer-sep"></div>
                <div class="drawer-section-title">Utente</div>
                <a href="/cambia_password.php" class="drawer-item <?= $paginaCorrente === 'cambia_password.php' ? 'active' : '' ?>">Cambia password</a>
                <a href="/logout.php" class="drawer-item">Logout</a>
            </div>
        </aside>
    <?php endif; ?>

    <main class="page-content">
        <div class="container">
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

            window.addEventListener('resize', function () {
                if (window.innerWidth > 1100) {
                    closeDrawer();
                }
            });
        })();
    </script>
    </body>
    </html>
    <?php
}
