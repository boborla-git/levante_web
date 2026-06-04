SET NAMES utf8mb4;

INSERT INTO aut_ruoli_permessi (id_ruolo, id_risorsa, puo_leggere, puo_scrivere)
SELECT p.id_ruolo, r2.id_risorsa, 1, 0
FROM aut_ruoli_permessi p
JOIN aut_risorse r1 ON r1.id_risorsa = p.id_risorsa
JOIN aut_risorse r2 ON r2.codice_risorsa = 'pagina.report_assenze'
LEFT JOIN aut_ruoli_permessi x ON x.id_ruolo = p.id_ruolo AND x.id_risorsa = r2.id_risorsa
WHERE r1.codice_risorsa = 'pagina.approvazioni_assenze'
  AND p.puo_leggere = 1
  AND x.id_ruolo IS NULL;

UPDATE aut_ruoli_permessi p
JOIN aut_risorse r2 ON r2.id_risorsa = p.id_risorsa
SET p.puo_leggere = 1
WHERE r2.codice_risorsa = 'pagina.report_assenze'
  AND p.id_ruolo IN (
      SELECT id_ruolo
      FROM (
          SELECT p2.id_ruolo
          FROM aut_ruoli_permessi p2
          JOIN aut_risorse r1 ON r1.id_risorsa = p2.id_risorsa
          WHERE r1.codice_risorsa = 'pagina.approvazioni_assenze'
            AND p2.puo_leggere = 1
      ) ruoli_report
  );
