# Standard unico delle email HR

Versione 1 ottobre 2026. Questo documento e i test in `tests/email-layout` definiscono il modello da preservare per ogni successiva modifica alle email.

## Regole comuni

- Tutti gli invii HTML passano da `hrEmailInviaHtml` in `includes/hr_email.php`.
- Corpo UTF-8, `Content-Transfer-Encoding: base64`, righe MIME non oltre 76 caratteri. Non inviare HTML su una sola riga con codifica 8bit e non applicare wordwrap al markup.
- Oggetto e nome mittente sono codificati RFC 2047 anche senza estensione mbstring.
- Cornice unica `hrEmailCorniceHtml`: sfondo grigio chiaro, scheda bianca larga al massimo 720 px, bordo tenue, intestazione `Portale HR Ravioli S.p.A.`, sottotitolo e footer uniforme.
- Arial, Helvetica, sans-serif esplicito. Intestazione 20 px; sottotitolo e messaggio 14 px; dati 13 px con interlinea 18 px; intestazioni di colonna e footer 12 px. Nessuno shorthand `font:`. Su schermi fino a 480 px, i dati usano 12 px / interlinea 17 px e padding celle 8 px / 6 px; la regola è comune a tutte le tabelle e tutti i valori.
- Testi allineati a sinistra; celle allineate in alto; nomi in grassetto, valori normali. Padding celle 9 px verticali / 10 px orizzontali. Colonne percentuali fisse e a-capo naturale anche su smartphone.
- Footer sempre `Messaggio automatico del Portale HR Ravioli S.p.A.`, nella propria cella, con codice richiesta quando presente.
- Dati dinamici escapati una sola volta con `hrEmailH`. Il trattino per un oggetto vuoto è testo UTF-8, non un frammento di markup.
- Pulsante comune `hrEmailPulsanteHtml`, con etichetta esplicita e URL escapato.

## Tipi coperti

| Tipo | Contenuto specifico |
| --- | --- |
| Richiesta inserita / ricevuta | Saluto, messaggio, dettaglio, stato, link, codice |
| Richiesta da approvare | Come sopra, con altre richieste nel periodo nel perimetro del responsabile |
| Richiesta approvata | Stato approvato e messaggio del workflow |
| Approvazione automatica / inserimento delegato | Stessa cornice e stato approvato |
| Informativa al responsabile | Messaggio che non richiede approvazione; altre richieste nel periodo |
| Rifiuto / annullamento | Stato corrispondente e contenuti già previsti dal workflow |
| Riepilogo BASE | Dipendente, periodo, oggetto; solo richieste approvate |
| Riepilogo HR | Anche motivo; richieste approvate e in attesa secondo la visibilità HR |
| Riepilogo vuoto | Messaggio uniforme di nessuna assenza prevista |
| Aggiornamento e prove manuali | Stesso renderer del riepilogo automatico |
| Verifica email | Stessa cornice, saluto, scadenza 24 ore, pulsante conferma e indicazione di contattare HR |

## Contratti preservati

Oggetti email, saluti, dettagli e messaggi del workflow, destinatari, recapiti verificati, filtri privacy, stato delle richieste, frequenza e orario del cron, copie BCC all'admin, log e deduplicazione restano quelli esistenti. HR riceve una sola versione dettagliata; gli altri una sola versione BASE. L'oggetto breve resta anche nella versione BASE. Le modifiche grafiche non devono assegnare ruoli o generare invii aggiuntivi.

## Verifica

`python3 tests/email-layout/run_tests.py` usa PHP e un recapito locale simulato: nessuna email è spedita in rete e nessun database reale è aperto. Le fixture coprono sette eventi di workflow, riepiloghi automatici e manuali, aggiornamento solo HR, verifica recapito, header UTF-8 senza mbstring, copie BCC e invii esclusi. Il MIME acquisito viene decodificato e confrontato con l'HTML generato; si verificano stili, struttura, escape e lunghezza delle righe.

Il rendering di controllo a larghezza desktop e smartphone non sostituisce il test nel client di posta reale. Dopo l'installazione usare `Invia prova ad Amministratore` nella pagina Riepilogo assenze email per confermare BASE e HR nel client effettivamente utilizzato. Le email già ricevute non vengono modificate.

Riferimenti tecnici: [RFC 2045, MIME](https://www.rfc-editor.org/rfc/rfc2045), [RFC 2047, intestazioni](https://www.rfc-editor.org/rfc/rfc2047), [PHP mail](https://www.php.net/manual/en/function.mail.php).
