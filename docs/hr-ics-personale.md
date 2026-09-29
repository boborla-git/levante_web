# Test feed ICS personale

## Obiettivo

Verificare l'abbonamento a un calendario personale Levante tramite URL ICS senza esportazioni manuali ripetute.

## Endpoint

`/calendario_personale_ics.php?utente=<username>&token=<token_personale>`

L'endpoint non richiede una sessione web: l'autorizzazione avviene tramite token personale nell'URL. Nel database viene conservato solo l'hash SHA-256 del token.

## Ambito del feed

Il feed contiene esclusivamente le richieste dell'utente indicato che:

- sono nello stato `APPROVATA` oppure `IN_ATTESA`;
- appartengono a tipologie attive e visibili nel calendario.

Le richieste annullate o rifiutate non vengono pubblicate e quindi scompaiono al successivo aggiornamento del calendario esterno.

Per l'utente proprietario sono esposti:

- tipologia/descrizione calendario;
- stato;
- eventuale oggetto breve;
- codice richiesta Levante.

Le note del richiedente non vengono esportate.

## Aggiornamenti

Gli UID degli eventi sono stabili e derivano da richiesta + periodo. Questo consente ai client calendario di aggiornare un evento esistente invece di crearne uno nuovo.

Le richieste `IN_ATTESA` sono pubblicate come `TENTATIVE`; le approvate come `CONFIRMED`.

Lo Smart Working viene pubblicato come evento trasparente/free; gli altri eventi come busy.

## Test iniziale

Il primo utente abilitato e' `test_MMorleo`.

Il file SQL `sql/2026-09-29_test_ics_mmorleo.sql` abilita il token per questo utente. Il token in chiaro non e' salvato nel repository.

## Revoca

Per disabilitare il feed e' sufficiente impostare `attivo=0` sulla relativa configurazione `HR_ICS_TOKEN_SHA256_USER_<id_utente>` oppure sostituire l'hash con quello di un nuovo token.
