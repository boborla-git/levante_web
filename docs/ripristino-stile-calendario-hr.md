# Ripristino stile calendario HR

Questo pacchetto corregge una regressione introdotta nel commit `Migliora identita utente e notifiche HR`.

## Cosa ripristina

Ripristina il blocco CSS consolidato:

```text
Rifinitura calendario HR: preserva layout consolidato dopo evoluzioni funzionali
```

## Cosa preserva

- identita utente nel menu alto
- notifiche HR parlanti
- workflow HR
- email golden master
- permessi atomici HR

## File modificato

- `assets/design-system.css`

## Nota

Questa patch e solo correttiva: non introduce nuove funzionalita.
