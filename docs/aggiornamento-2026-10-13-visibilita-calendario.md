# Mostra nel calendario di

1. Eseguire in phpMyAdmin `sql/2026-10-13_visibilita_calendario.sql`. Lo script aggiunge soltanto il tipo di relazione; è ripetibile e non assegna utenti o permessi.
2. Caricare in `includes/` i file completi `hr_organizzazione.php` e `hr_calendario.php`.
3. Caricare nella cartella principale del sito `relazioni_organizzative.php`, `calendario_assenze.php` e `calendario_personale_ics.php`.
4. Ricaricare le pagine aperte. Documentazione e test restano su GitHub, senza necessità di caricarli sul sito.

In Relazioni organizzative → Nuova relazione:

- Utente: A, la persona da mostrare.
- Relazione: mostra nel calendario di.
- Responsabile/referente: B, la persona che deve vederla.
- Data inizio e, se serve, data fine.

Salvare. Il collegamento può essere chiuso dall'archivio relazioni. B deve avere accesso alla pagina Calendario assenze. L'ICS di B usa automaticamente lo stesso collegamento, senza modificarne l'URL.

La relazione aggiunge solo visibilità da A verso il calendario di B: non attribuisce approvazioni, inserimenti delegati, visibilità inversa o transitiva. Le altre relazioni e i gruppi continuano a concedere la propria visibilità. Le assenze personali sono Assente; i dettagli degli impegni di lavoro seguono i permessi già definiti. La relazione non aggiunge visibilità sulle richieste pendenti.
