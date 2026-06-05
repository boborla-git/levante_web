SET NAMES utf8mb4;

ALTER TABLE hr_timbrature
    ADD COLUMN causale VARCHAR(50) NULL AFTER tipo;

CREATE INDEX idx_hr_timbrature_causale ON hr_timbrature (causale);

UPDATE hr_timbrature
SET causale = CASE
    WHEN note LIKE 'Motivo: Cliente%' THEN 'CLIENTE'
    WHEN note LIKE 'Motivo: Fornitore%' THEN 'FORNITORE'
    WHEN note LIKE 'Motivo: Trasferta%' THEN 'TRASFERTA'
    WHEN note LIKE 'Motivo: Formazione%' THEN 'FORMAZIONE'
    WHEN note LIKE 'Motivo: Consegna / ritiro materiale%' THEN 'CONSEGNA_RITIRO'
    WHEN note LIKE 'Motivo: Permesso personale%' THEN 'PERMESSO_PERSONALE'
    WHEN note LIKE 'Motivo: Altro%' THEN 'ALTRO'
    ELSE causale
END
WHERE tipo = 'FUORI_SEDE'
  AND causale IS NULL;

SELECT 'hr_timbrature' AS tabella, COUNT(*) AS righe_presenti FROM hr_timbrature;
