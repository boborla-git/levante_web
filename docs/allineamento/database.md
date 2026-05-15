# Database reale - baseline da dump

Dump analizzato: `Sql1931055_1.sql` creato il 2026-05-15 alle 07:51. Server MySQL 8.0.45-36.

## Tabelle/vista presenti

- `aut_log_accessi`
- `aut_risorse`
- `aut_ruoli`
- `aut_ruoli_permessi`
- `aut_tipi_utente`
- `aut_utenti`
- `aut_utenti_ruoli`
- `fornitori_contatti`
- `hr_canali_notifica`
- `hr_configurazioni`
- `hr_gruppi_lavoro`
- `hr_gruppi_utenti`
- `hr_notifiche`
- `hr_notifiche_destinatari`
- `hr_recapiti_utenti`
- `hr_relazioni_organizzative`
- `hr_richieste`
- `hr_richieste_approvazioni`
- `hr_richieste_periodi`
- `hr_richieste_storico`
- `hr_stati_presenza`
- `hr_stati_richiesta`
- `hr_tipi_recapito`
- `hr_tipi_relazione_organizzativa`
- `hr_tipologie_evento`
- `ordini_fornitori_aperti`
- `ordini_fornitori_note`
- `ordini_fornitori_note_storico`
- `sync_stato`
- `v_hr_email_log`

## Struttura colonne per tabella

### `aut_log_accessi`

- `id_log_accesso` bigint UNSIGNED NOT NULL
- `id_utente` int UNSIGNED DEFAULT NULL
- `username` varchar(100) DEFAULT NULL
- `codice_risorsa` varchar(150) DEFAULT NULL
- `azione` varchar(30) NOT NULL
- `esito` varchar(30) NOT NULL
- `indirizzo_ip` varchar(45) DEFAULT NULL
- `user_agent` varchar(255) DEFAULT NULL
- `dettagli` varchar(255) DEFAULT NULL
- `data_evento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP

### `aut_risorse`

- `id_risorsa` int UNSIGNED NOT NULL
- `codice_risorsa` varchar(150) NOT NULL
- `descrizione` varchar(150) NOT NULL
- `tipo_risorsa` varchar(30) NOT NULL
- `id_risorsa_padre` int UNSIGNED DEFAULT NULL
- `percorso` varchar(255) DEFAULT NULL
- `icona` varchar(100) DEFAULT NULL
- `visibile_menu` tinyint(1) NOT NULL DEFAULT '0'
- `ordinamento` int NOT NULL DEFAULT '0'
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `aut_ruoli`

- `id_ruolo` int UNSIGNED NOT NULL
- `codice_ruolo` varchar(100) NOT NULL
- `descrizione` varchar(150) NOT NULL
- `id_ruolo_padre` int UNSIGNED DEFAULT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'
- `ordinamento` int NOT NULL DEFAULT '0'

### `aut_ruoli_permessi`

- `id_ruolo_permesso` int UNSIGNED NOT NULL
- `id_ruolo` int UNSIGNED NOT NULL
- `id_risorsa` int UNSIGNED NOT NULL
- `permesso` varchar(30) NOT NULL
- `consentito` tinyint(1) NOT NULL DEFAULT '1'

### `aut_tipi_utente`

- `id_tipo_utente` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `descrizione` varchar(100) NOT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `aut_utenti`

- `id_utente` int UNSIGNED NOT NULL
- `username` varchar(100) NOT NULL
- `password_hash` varchar(255) NOT NULL
- `email` varchar(150) DEFAULT NULL
- `telefono` varchar(30) DEFAULT NULL
- `nome` varchar(100) NOT NULL
- `cognome` varchar(100) NOT NULL
- `id_tipo_utente` int UNSIGNED NOT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'
- `deve_cambiare_password` tinyint(1) NOT NULL DEFAULT '0'
- `lingua_preferita` varchar(10) NOT NULL DEFAULT 'it'
- `locale_preferito` varchar(20) NOT NULL DEFAULT 'it-IT'
- `fuso_orario` varchar(50) NOT NULL DEFAULT 'Europe/Rome'
- `data_creazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `data_aggiornamento` datetime DEFAULT NULL
- `data_ultima_login` datetime DEFAULT NULL
- `note` varchar(255) DEFAULT NULL
- `email_notifiche` tinyint(1) NOT NULL DEFAULT '1'
- `sms_notifiche` tinyint(1) NOT NULL DEFAULT '0'
- `email_verificata` tinyint(1) NOT NULL DEFAULT '0'
- `telefono_verificato` tinyint(1) NOT NULL DEFAULT '0'

### `aut_utenti_ruoli`

- `id_utente_ruolo` int UNSIGNED NOT NULL
- `id_utente` int UNSIGNED NOT NULL
- `id_ruolo` int UNSIGNED NOT NULL
- `data_inizio` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `data_fine` datetime DEFAULT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `fornitori_contatti`

- `Fornitore` varchar(100) NOT NULL
- `Telefono` varchar(50) DEFAULT NULL
- `Email` varchar(400) DEFAULT NULL
- `Indirizzo` varchar(500) DEFAULT NULL

### `hr_canali_notifica`

- `id_canale_notifica` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `descrizione` varchar(100) NOT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_configurazioni`

- `id_configurazione` int UNSIGNED NOT NULL
- `codice` varchar(100) NOT NULL
- `valore` varchar(255) DEFAULT NULL
- `descrizione` varchar(255) DEFAULT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_gruppi_lavoro`

- `id_gruppo_lavoro` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `nome` varchar(100) NOT NULL
- `descrizione` varchar(255) DEFAULT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_gruppi_utenti`

- `id_gruppo_utente` bigint UNSIGNED NOT NULL
- `id_gruppo_lavoro` int UNSIGNED NOT NULL
- `id_utente` int UNSIGNED NOT NULL
- `ruolo_nel_gruppo` varchar(50) DEFAULT NULL
- `data_inizio` date NOT NULL
- `data_fine` date DEFAULT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_notifiche`

- `id_notifica` bigint UNSIGNED NOT NULL
- `tipo_evento` varchar(50) NOT NULL
- `titolo` varchar(150) NOT NULL
- `messaggio` text NOT NULL
- `link` varchar(255) DEFAULT NULL
- `id_richiesta` bigint UNSIGNED DEFAULT NULL
- `data_creazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `creato_da` int UNSIGNED DEFAULT NULL

### `hr_notifiche_destinatari`

- `id_notifica_destinatario` bigint UNSIGNED NOT NULL
- `id_notifica` bigint UNSIGNED NOT NULL
- `id_utente` int UNSIGNED NOT NULL
- `id_canale_notifica` int UNSIGNED NOT NULL
- `inviata` tinyint(1) NOT NULL DEFAULT '0'
- `letta` tinyint(1) NOT NULL DEFAULT '0'
- `data_invio` datetime DEFAULT NULL
- `data_lettura` datetime DEFAULT NULL
- `errore_invio` varchar(255) DEFAULT NULL

### `hr_recapiti_utenti`

- `id_recapito_utente` bigint UNSIGNED NOT NULL
- `id_utente` int UNSIGNED NOT NULL
- `id_tipo_recapito` int UNSIGNED NOT NULL
- `valore` varchar(255) NOT NULL
- `principale` tinyint(1) NOT NULL DEFAULT '0'
- `verificato` tinyint(1) NOT NULL DEFAULT '0'
- `attivo` tinyint(1) NOT NULL DEFAULT '1'
- `note` varchar(255) DEFAULT NULL

### `hr_relazioni_organizzative`

- `id_relazione_organizzativa` bigint UNSIGNED NOT NULL
- `id_utente` int UNSIGNED NOT NULL
- `id_utente_collegato` int UNSIGNED NOT NULL
- `id_tipo_relazione` int UNSIGNED NOT NULL
- `data_inizio` date NOT NULL
- `data_fine` date DEFAULT NULL
- `attiva` tinyint(1) NOT NULL DEFAULT '1'
- `note` varchar(255) DEFAULT NULL

### `hr_richieste`

- `id_richiesta` bigint UNSIGNED NOT NULL
- `codice_richiesta` varchar(30) NOT NULL
- `id_utente_richiedente` int UNSIGNED NOT NULL
- `id_tipologia_evento` int UNSIGNED NOT NULL
- `id_stato_richiesta` int UNSIGNED NOT NULL
- `id_responsabile_corrente` int UNSIGNED DEFAULT NULL
- `id_gruppo_lavoro` int UNSIGNED DEFAULT NULL
- `oggetto` varchar(150) DEFAULT NULL
- `note_richiedente` text
- `note_interne` text
- `data_creazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `data_invio` datetime DEFAULT NULL
- `data_chiusura` datetime DEFAULT NULL
- `data_aggiornamento` datetime DEFAULT NULL
- `annullata_da_richiedente` tinyint(1) NOT NULL DEFAULT '0'
- `origine` varchar(30) NOT NULL DEFAULT 'web'

### `hr_richieste_approvazioni`

- `id_richiesta_approvazione` bigint UNSIGNED NOT NULL
- `id_richiesta` bigint UNSIGNED NOT NULL
- `livello_approvazione` int NOT NULL DEFAULT '1'
- `id_approvatore_assegnato` int UNSIGNED NOT NULL
- `stato_approvazione` varchar(30) NOT NULL
- `data_assegnazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `data_risposta` datetime DEFAULT NULL
- `esito` varchar(30) DEFAULT NULL
- `note_approvatore` text
- `gestita_da_hr` tinyint(1) NOT NULL DEFAULT '0'

### `hr_richieste_periodi`

- `id_richiesta_periodo` bigint UNSIGNED NOT NULL
- `id_richiesta` bigint UNSIGNED NOT NULL
- `tipo_periodo` varchar(20) NOT NULL
- `data_da` date NOT NULL
- `data_a` date NOT NULL
- `ora_da` time DEFAULT NULL
- `ora_a` time DEFAULT NULL
- `giornata_intera` tinyint(1) NOT NULL DEFAULT '0'
- `minuti_totali` int UNSIGNED DEFAULT NULL
- `ordinamento` int NOT NULL DEFAULT '0'
- `note` varchar(255) DEFAULT NULL

### `hr_richieste_storico`

- `id_richiesta_storico` bigint UNSIGNED NOT NULL
- `id_richiesta` bigint UNSIGNED NOT NULL
- `azione` varchar(50) NOT NULL
- `id_utente_azione` int UNSIGNED DEFAULT NULL
- `data_azione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `dettagli` text
- `origine` varchar(30) NOT NULL DEFAULT 'web'

### `hr_stati_presenza`

- `id_stato_presenza` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `descrizione` varchar(100) NOT NULL
- `descrizione_breve` varchar(50) DEFAULT NULL
- `colore` varchar(20) DEFAULT NULL
- `disturbabile_default` tinyint(1) NOT NULL DEFAULT '0'
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_stati_richiesta`

- `id_stato_richiesta` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `descrizione` varchar(100) NOT NULL
- `colore` varchar(20) DEFAULT NULL
- `ordinamento` int NOT NULL DEFAULT '0'
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_tipi_recapito`

- `id_tipo_recapito` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `descrizione` varchar(100) NOT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_tipi_relazione_organizzativa`

- `id_tipo_relazione` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `descrizione` varchar(100) NOT NULL
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `hr_tipologie_evento`

- `id_tipologia_evento` int UNSIGNED NOT NULL
- `codice` varchar(50) NOT NULL
- `descrizione` varchar(100) NOT NULL
- `descrizione_calendario` varchar(100) DEFAULT NULL
- `richiede_approvazione` tinyint(1) NOT NULL DEFAULT '1'
- `approvazione_obbligatoria` tinyint(1) NOT NULL DEFAULT '1'
- `consente_giorni` tinyint(1) NOT NULL DEFAULT '1'
- `consente_ore` tinyint(1) NOT NULL DEFAULT '1'
- `consente_multi_periodo` tinyint(1) NOT NULL DEFAULT '0'
- `visibile_calendario` tinyint(1) NOT NULL DEFAULT '1'
- `visibile_ai_colleghi` tinyint(1) NOT NULL DEFAULT '1'
- `mostra_dettaglio_colleghi` tinyint(1) NOT NULL DEFAULT '0'
- `mostra_dettaglio_responsabili` tinyint(1) NOT NULL DEFAULT '1'
- `mostra_dettaglio_hr` tinyint(1) NOT NULL DEFAULT '1'
- `id_stato_presenza` int UNSIGNED NOT NULL
- `disturbabile` tinyint(1) NOT NULL DEFAULT '0'
- `colore_calendario` varchar(20) DEFAULT NULL
- `ordinamento` int NOT NULL DEFAULT '0'
- `attivo` tinyint(1) NOT NULL DEFAULT '1'

### `ordini_fornitori_aperti`

- `n_ordine` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL
- `data_ordine` date NOT NULL DEFAULT '1000-01-01'
- `n_riga` decimal(3,0) NOT NULL DEFAULT '0'
- `fornitore` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `data_consegna` date DEFAULT NULL
- `articolo` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `descrizione` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `q_ordinata` decimal(13,2) NOT NULL DEFAULT '0.00'
- `q_consegnata` decimal(13,2) NOT NULL DEFAULT '0.00'
- `q_residua` decimal(13,2) NOT NULL DEFAULT '0.00'
- `progr_1` int NOT NULL DEFAULT '0'
- `progr_2` int DEFAULT NULL
- `nota` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `ultimo_sollecito_mail` date DEFAULT NULL

### `ordini_fornitori_note`

- `n_ordine` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL
- `n_riga` decimal(3,0) NOT NULL
- `nota_ravioli` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `nota_fornitore` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `data_evento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `utente_modifica` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `origine` enum('import','web') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'import'
- `importante` tinyint(1) NOT NULL DEFAULT '0'

### `ordini_fornitori_note_storico`

- `id_evento` bigint NOT NULL
- `n_ordine` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL
- `n_riga` decimal(3,0) NOT NULL
- `fornitore` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `articolo` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `nota_ravioli` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `nota_fornitore` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `data_evento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
- `utente_modifica` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `importato_locale` tinyint(1) NOT NULL DEFAULT '0'
- `data_import_locale` datetime DEFAULT NULL
- `origine` enum('import','web') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'import'
- `importante` tinyint(1) NOT NULL DEFAULT '0'

### `sync_stato`

- `id` bigint NOT NULL
- `modulo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL
- `ultimo_aggiornamento` datetime DEFAULT NULL
- `righe_importate` int DEFAULT NULL
- `esito` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL
- `messaggio` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL

### `v_hr_email_log`


## Note

- Il dump conferma la presenza delle tabelle `aut_*`, `hr_*`, `ordini_fornitori_*`, `fornitori_contatti`, `sync_stato` e della vista `v_hr_email_log`.
- Questo file documenta la struttura rilevata; eventuali modifiche DB future devono essere preparate con script SQL espliciti.
