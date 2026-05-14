# Sicurezza email workflow HR

Questo pacchetto introduce un secondo interruttore per gli invii email automatici del workflow HR:

- `HR_EMAIL_WORKFLOW_ATTIVO`

## Perche serve

Nel database reale `HR_NOTIFICA_EMAIL_ATTIVA` puo essere gia attivo.
Per evitare invii automatici involontari, le funzioni email HR inviano davvero solo se:

1. `HR_NOTIFICA_EMAIL_ATTIVA = 1`
2. `HR_EMAIL_WORKFLOW_ATTIVO = 1`

Il nuovo valore viene inserito a `0`, quindi non cambia il comportamento operativo del sito.

## Prossimo passo

Quando decideremo di fare il primo test reale, porteremo `HR_EMAIL_WORKFLOW_ATTIVO` a `1` e collegheremo un solo evento controllato, ad esempio la nuova richiesta da approvare.
