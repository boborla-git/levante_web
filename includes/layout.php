<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function layoutHeader(string $titoloPagina, string $titoloApplicazione = 'Levante'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $utenteLoggato = isset($_SESSION['utente_id']) && (int)$_SESSION['utente_id'] > 0;
    $nome = (string)($_SESSION['nome'] ?? '');
    $username = (string)($_SESSION['username'] ?? '');
    $ruolo = (string)($_SESSION['ruolo'] ?? '');
    $paginaCorrente = basename($_SERVER['PHP_SELF'] ?? '');

    $puoLeggereUtenti = $utenteLoggato && haPermessoLettura('utenti');
    $puoScrivereUtenti = $utenteLoggato && haPermessoScrittura('utenti');
    $puoLeggereOrdini = $utenteLoggato && haPermessoLettura('ordini_fornitori_aperti');
    $puoLeggereWorkflow = $utenteLoggato && haPermessoLettura('workflow');

    $menu = [];

    if ($utenteLoggato) {
        $menu[] = ['label' => 'Dashboard', 'href' => 'index.php', 'match' => ['index.php']];

        if ($puoLeggereOrdini) {
            $menu[] = ['label' => 'Ordini fornitori', 'href' => 'ordini_fornitori_aperti.php', 'match' => ['ordini_fornitori_aperti.php']];
        }

        if ($puoLeggereWorkflow) {
            $menu[] = ['label' => 'Workflow', 'href' => 'workflow.php', 'match' => ['workflow.php']];
        }

        if ($puoLeggereUtenti) {
            $adminChildren = [
                ['label' => 'Utenti', 'href' => 'utenti.php', 'match' => ['utenti.php', 'utente_nuovo.php', 'utente_forza_password.php']],
            ];

            if ($puoScrivereUtenti) {
                $adminChildren[] = ['label' => 'Ruoli utenti', 'href' => 'ruoli_utenti.php', 'match' => ['ruoli_utenti.php']];
                $adminChildren[] = ['label' => 'Permessi ruoli', 'href' => 'permessi_ruoli.php', 'match' => ['permessi_ruoli.php', 'permessi.php']];
            }

            $adminAttivo = false;
            foreach ($adminChildren as $figlia) {
                if (in_array($paginaCorrente, $figlia['match'], true)) {
                    $adminAttivo = true;
                    break;
                }
            }

            $menu[] = [
                'label' => 'Gestione utenti',
                'active' => $adminAttivo,
                'children' => $adminChildren,
            ];
        }

        $menu[] = ['label' => 'Cambia password', 'href' => 'cambia_password.php', 'match' => ['cambia_password.php']];
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
                <a class="brand" href="/index.php" aria-label="<?= htmlspecialchars($titoloApplicazione) ?>">
                    <img src="/assets/img/logo-ravioli.png" alt="Ravioli S.p.A.">
                </a>

                <?php if ($utenteLoggato && count($menu) > 0): ?>
                    <nav class="topnav" aria-label="Navigazione principale">
                        <?php foreach ($menu as $voce): ?>
                            <?php
                            $attiva = isset($voce['active'])
                                ? (bool)$voce['active']
                                : in_array($paginaCorrente, $voce['match'], true);
                            $haFiglie = isset($voce['children']) && is_array($voce['children']) && count($voce['children']) > 0;
                            ?>
                            <?php if ($haFiglie): ?>
                                <div class="topnav-dropdown <?= $attiva ? 'active' : '' ?>">
                                    <button type="button" class="topnav-link topnav-parent <?= $attiva ? 'active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                                        <?= htmlspecialchars($voce['label']) ?>
                                    </button>
                                    <div class="topnav-dropdown-menu">
                                        <?php foreach ($voce['children'] as $figlia): ?>
                                            <?php $figliaAttiva = in_array($paginaCorrente, $figlia['match'], true); ?>
                                            <a href="/<?= htmlspecialchars($figlia['href']) ?>" class="<?= $figliaAttiva ? 'active' : '' ?>">
                                                <?= htmlspecialchars($figlia['label']) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="/<?= htmlspecialchars($voce['href']) ?>" class="topnav-link <?= $attiva ? 'active' : '' ?>">
                                    <?= htmlspecialchars($voce['label']) ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
            </div>

            <?php if ($utenteLoggato): ?>
                <div class="topbar-right">
                    <div class="topbar-user">
                        <div class="topbar-user-name"><strong><?= htmlspecialchars($username) ?></strong></div>
                        <div class="topbar-user-meta">
                            <?= htmlspecialchars($nome) ?>
                            <?php if ($ruolo !== ''): ?>
                                · <?= htmlspecialchars($ruolo) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="btn btn-light" href="/logout.php">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

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

    </body>
    </html>
    <?php
}
