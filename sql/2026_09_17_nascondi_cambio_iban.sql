SET NAMES utf8mb4;

UPDATE aut_risorse
SET visibile_menu = 0,
    attivo = 0
WHERE codice_risorsa = 'pagina.cambio_iban';

SELECT codice_risorsa, descrizione, percorso, visibile_menu, attivo
FROM aut_risorse
WHERE codice_risorsa = 'pagina.cambio_iban';
