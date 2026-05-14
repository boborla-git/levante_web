# Log email HR

Questo pacchetto aggiunge una vista SQL di controllo:

```text
v_hr_email_log
```

## Obiettivo

Rendere leggibile il log degli invii email HR gia' registrati nelle tabelle:

- `hr_notifiche`
- `hr_notifiche_destinatari`
- `hr_canali_notifica`

## Cosa NON fa

- non invia email
- non modifica il workflow HR
- non modifica `assenze.php`
- non modifica `approvazioni_assenze.php`
- non cambia configurazioni

## Query utili

Ultime email HR:

```sql
SELECT *
FROM v_hr_email_log
ORDER BY data_creazione DESC, id_notifica_destinatario DESC
LIMIT 50;
```

Solo errori:

```sql
SELECT *
FROM v_hr_email_log
WHERE esito_email = 'ERRORE'
ORDER BY data_creazione DESC;
```

Email relative a una richiesta:

```sql
SELECT *
FROM v_hr_email_log
WHERE id_richiesta = 1234
ORDER BY data_creazione DESC;
```

## Nota

Con `HR_EMAIL_WORKFLOW_ATTIVO = 0` non dovrebbero comparire nuovi invii email automatici.
La vista servira' soprattutto quando attiveremo il test controllato con `HR_EMAIL_WORKFLOW_ATTIVO = 1`.
