# Calendario personale ICS

## Accesso e pagina personale

- Voce `Il mio calendario` sotto `menu.profilo`, risorsa `pagina.mio_calendario`.
- Pagina `mio_calendario.php`: autenticazione obbligatoria, identita' solo da sessione, controllo account attivo nel database. Disponibile anche a un account senza ruolo; non espone dati di altre persone e non modifica dati durante la consultazione.
- Link assoluto costruito esclusivamente da `HR_URL_PORTALE` (HTTPS) e username corrente dal DB, non dall'Host della richiesta o da parametri del browser.
- Campo readonly selezionabile, copia tramite Clipboard API con fallback e selezione manuale, pulsanti da almeno 44px e layout responsive.
- Pagina privata con `no-store`, `no-referrer` e `noindex`. Non carica script esterni per la copia.

## Token e inizializzazione

- Eseguire `sql/2026-09-30_ics_tutti_utenti.sql` dopo avere caricato `mio_calendario.php` e `includes/layout.php`.
- Crea un token casuale di 32 byte (64 caratteri esadecimali) per ogni account esistente. La generazione avviene sul server MySQL tramite `RANDOM_BYTES(32)`, non nel file distribuito.
- Il token recuperabile e' conservato in `hr_ics_token_utenti`: serve per mostrare nuovamente il link. Questa tabella e i backup contengono segreti e devono restare riservati; mai esportarli in GitHub o nei pacchetti di distribuzione.
- L'hash SHA-256 resta in `hr_configurazioni` con codice `HR_ICS_TOKEN_SHA256_USER_<id_utente>`, compatibile con l'endpoint esistente.
- L'inizializzazione sostituisce una sola volta eventuali precedenti token di prova dei quali si possiede solo l'hash. Il precedente abbonamento deve essere sostituito con il nuovo link della pagina personale.
- Lo script si puo' rieseguire: genera solo i token mancanti, inclusi quelli per account creati successivamente; conserva i token gia' presenti e non riattiva configurazioni revocate. Non ripara automaticamente coppie token/hash alterate manualmente.
- Le nuove assegnazioni e il menu sono transazionali; la creazione iniziale della tabella avviene prima della transazione. Precheck InnoDB e Profilo, verifiche per conteggio effettivo, COMMIT/ROLLBACK condizionale senza dipendere da ROW_COUNT.
- Nessuna modifica a password, flag, ruoli, permessi, recapiti, richieste o diritti HR.
- I token sono indipendenti dalla password: il cambio password non cambia il link.
- Lo username resta un parametro dell'endpoint: una futura rinomina richiede di aggiornare l'abbonamento, senza cambiare il token.

## Endpoint e contenuto (invariati)

`/calendario_personale_ics.php?utente=<username>&token=<token_personale>`

Accesso senza sessione con token verificato tramite `hash_equals`; l'account deve essere attivo. Feed limitato alle proprie richieste `APPROVATA` e `IN_ATTESA` di tipologie attive e visibili nel calendario. Per il proprietario sono esposti descrizione calendario/tipologia, stato, eventuale oggetto e codice richiesta. Le note del richiedente non vengono esportate.

Le richieste in attesa hanno prefisso `[In attesa]` e stato ICS `TENTATIVE`; le approvate `CONFIRMED`. Le annullate e rifiutate scompaiono al successivo aggiornamento del client. UID stabili per richiesta e periodo. Smart working trasparente/free, altri eventi busy. Gli aggiornamenti dipendono dalla frequenza del client calendario: usare un abbonamento da URL, non una singola importazione.

## Revoca

Impostare `attivo=0` sulla configurazione `HR_ICS_TOKEN_SHA256_USER_<id_utente>`. Il feed nega l'accesso e la pagina personale non mostra il link. La riesecuzione dello script conserva la revoca. Disattivare l'account nega comunque l'accesso, indipendentemente dal token. I dati gia' scaricati da un client non possono essere cancellati a distanza.

## Installazione

1. Caricare `mio_calendario.php` nella radice e sostituire `includes/layout.php` con il file completo.
2. Importare lo script SQL in phpMyAdmin nel database del portale.
3. Verificare `esito_finale = OK - calendari personali configurati` e `transazione = COMMIT`.
4. Aprire il menu con il proprio nome, poi `Il mio calendario`. Provare la copia e un abbonamento esterno. Per l'account che aveva gia' il calendario di prova, sostituire il vecchio abbonamento.

Commit summary: aggiunge una pagina personale per il link ICS, token individuali generati nel DB e migrazione idempotente del menu; conserva endpoint e regole HR esistenti.
