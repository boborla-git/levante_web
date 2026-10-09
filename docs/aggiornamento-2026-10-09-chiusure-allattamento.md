# Aggiornamento del 9 ottobre 2026

## Ordine di pubblicazione

1. Da GitHub aprire `sql/2026-10-09_chiusure_aziendali_allattamento.sql` ed eseguirlo nel database di LEVANTE WEB tramite phpMyAdmin. È ripetibile. Lo script SQL non deve essere caricato nella cartella pubblica del sito.
2. Caricare prima `includes/hr_regole_assenze.php` nella cartella `includes/` del sito.
3. Caricare nella cartella principale del sito, insieme a `index.php`, i file completi `assenze.php`, `approvazioni_assenze.php`, `benefici_hr.php`, `configurazione_assenze.php`, `chiusure_aziendali.php`. Sostituire quelli esistenti; `chiusure_aziendali.php` è nuovo.
4. Ricaricare le pagine già aperte prima di utilizzare i moduli. Se la nuova voce non appare nel menu, uscire e rientrare.

Le cartelle `tests/` e `docs/` restano su GitHub per verifica/documentazione: non occorre caricarle sul sito. Non sono richieste modifiche a CSS, email o calendario ICS.

## Utilizzo

- HR e admin aprono **Configurazione assenze → Chiusure aziendali**, oppure la nuova voce del menu, e indicano descrizione, dal e al. Entrambi i giorni sono compresi. Si possono modificare o disattivare le chiusure senza cancellarne la registrazione.
- Tutte le causali sono bloccate negli intervalli di chiusura, anche per HR/admin. Una richiesta di più giorni che attraversa una chiusura deve essere divisa in periodi che non la comprendono.
- Le richieste già presenti non vengono cancellate: la pagina segnala quanti conflitti verificare. Da Assenze HR può gestirli. Le approvazioni sono bloccate durante una chiusura; annullamento e rifiuto restano disponibili.
- In **Benefici e diritti**, HR/admin selezionano dipendente e Allattamento, indicano dal e al e salvano. Per questo beneficio la data finale è obbligatoria.
- L'utente abilitato vede Allattamento e il periodo assegnato in Assenze. Può inserire richieste a ore, per una singola giornata, fino a **2 ore totali al giorno**, anche in richieste separate. Le richieste in attesa consumano il limite; quelle rifiutate o annullate lo liberano.
- Le assegnazioni già presenti senza data finale vanno completate: fino ad allora non consentono nuove richieste Allattamento. Le richieste precedenti rimangono registrate.

## Verifica

Test automatici in `tests/chiusure-allattamento/`: esecuzione dei PHP reali con PDO/sessione/notifiche sostituiti, più migration ripetuta e query reali su MariaDB 10.11 in un database isolato. Controlli PHP 8.3 e JavaScript eseguiti. Nessuna email inviata e nessuna operazione sul database del sito.

Il limite concorrenziale è protetto dal lock transazionale comune, acquisito prima delle letture. La suite verifica l'ordine dei controlli e degli aggiornamenti; non usa connessioni concorrenti al database del sito.
