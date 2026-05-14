# HR Enterprise hardening - permessi atomici

Questo pacchetto introduce solo la base permessi, senza modificare PHP.

## Perché

Giorgia Bettolini e Stefano Daidone devono poter vedere tutte le assenze e le tipologie reali,
ma non devono essere messi nello stesso ruolo generale.

- Giorgia Bettolini = responsabile HR
- Stefano Daidone = direzione/proprietà

Quindi i permessi devono essere atomici e componibili.

## Risorse create

- `azione.hr.assenze.visualizza_tutte`
- `azione.hr.assenze.visualizza_tipologie`
- `azione.hr.assenze.visualizza_pendenti_globali`

## Ruoli creati

- `hr_responsabile_personale`
- `direzione_visibilita_hr`

## Assegnazioni

Lo script assegna:

- Giorgia Bettolini ufficiale + test -> `hr_responsabile_personale`
- Stefano Daidone ufficiale + test -> `direzione_visibilita_hr`

## Cosa NON fa

- non modifica calendario
- non modifica email
- non modifica workflow
- non modifica menu
- non hardcoda nomi dentro il PHP

## Prossimo passo

Dopo commit ed esecuzione SQL, si potrà aggiornare il codice per usare questi permessi nel calendario e nelle email responsabili.
