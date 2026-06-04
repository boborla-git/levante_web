SET NAMES utf8mb4;

INSERT INTO aut_risorse (codice_risorsa, descrizione, tipo_risorsa, id_risorsa_padre, percorso, icona, visibile_menu, ordinamento, attivo)
SELECT 'pagina.report_assenze', 'Report assenze', 'pagina', p.id_risorsa, '/report_assenze.php', 'la-file-excel', 1, 25, 1
FROM aut_risorse p
WHERE p.codice_risorsa = 'menu.hr'
  AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa = 'pagina.report_assenze');

UPDATE aut_risorse r
JOIN aut_risorse p ON p.codice_risorsa = 'menu.hr'
SET r.descrizione = 'Report assenze',
    r.tipo_risorsa = 'pagina',
    r.id_risorsa_padre = p.id_risorsa,
    r.percorso = '/report_assenze.php',
    r.icona = 'la-file-excel',
    r.visibile_menu = 1,
    r.ordinamento = 25,
    r.attivo = 1
WHERE r.codice_risorsa = 'pagina.report_assenze';
