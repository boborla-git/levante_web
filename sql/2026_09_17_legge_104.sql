SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS hr_benefici_utenti (
    id_beneficio_utente BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_utente INT UNSIGNED NOT NULL,
    codice_beneficio VARCHAR(50) NOT NULL,
    data_inizio DATE NOT NULL,
    data_fine DATE DEFAULT NULL,
    consente_giorni TINYINT(1) NOT NULL DEFAULT 1,
    consente_ore TINYINT(1) NOT NULL DEFAULT 1,
    plafond_giorni_mese DECIMAL(6,2) NOT NULL,
    plafond_minuti_mese INT UNSIGNED NOT NULL,
    minuti_giornata_equivalenza INT UNSIGNED NOT NULL,
    note_hr VARCHAR(500) DEFAULT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    aggiornato_da INT UNSIGNED DEFAULT NULL,
    data_aggiornamento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_beneficio_utente),
    UNIQUE KEY uk_hr_benefici_utente_codice (id_utente, codice_beneficio),
    KEY ix_hr_benefici_codice_attivo (codice_beneficio, attivo),
    KEY ix_hr_benefici_validita (data_inizio, data_fine),
    CONSTRAINT fk_hr_benefici_utente FOREIGN KEY (id_utente) REFERENCES aut_utenti (id_utente),
    CONSTRAINT fk_hr_benefici_aggiornato_da FOREIGN KEY (aggiornato_da) REFERENCES aut_utenti (id_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO hr_tipologie_evento (codice,descrizione,descrizione_calendario,richiede_approvazione,approvazione_obbligatoria,consente_giorni,consente_ore,consente_multi_periodo,visibile_calendario,visibile_ai_colleghi,mostra_dettaglio_colleghi,mostra_dettaglio_responsabili,mostra_dettaglio_hr,id_stato_presenza,disturbabile,colore_calendario,ordinamento,attivo)
SELECT 'LEGGE_104','Permesso Legge 104','Permesso Legge 104',0,0,1,1,0,1,1,0,0,1,sp.id_stato_presenza,0,'#6c757d',45,1
FROM hr_stati_presenza sp
WHERE sp.codice='ASSENTE' AND NOT EXISTS (SELECT 1 FROM hr_tipologie_evento WHERE codice='LEGGE_104');

UPDATE hr_tipologie_evento te JOIN hr_stati_presenza sp ON sp.codice='ASSENTE'
SET te.descrizione='Permesso Legge 104',te.descrizione_calendario='Permesso Legge 104',te.richiede_approvazione=0,te.approvazione_obbligatoria=0,te.consente_giorni=1,te.consente_ore=1,te.consente_multi_periodo=0,te.visibile_calendario=1,te.visibile_ai_colleghi=1,te.mostra_dettaglio_colleghi=0,te.mostra_dettaglio_responsabili=0,te.mostra_dettaglio_hr=1,te.id_stato_presenza=sp.id_stato_presenza,te.disturbabile=0,te.ordinamento=45,te.attivo=1
WHERE te.codice='LEGGE_104';

INSERT INTO aut_risorse (codice_risorsa,descrizione,tipo_risorsa,id_risorsa_padre,percorso,icona,visibile_menu,ordinamento,attivo)
SELECT 'pagina.permessi_104','Permessi tutelati','pagina',p.id_risorsa,'/permessi_104.php','la-user-shield',1,26,1 FROM aut_risorse p WHERE p.codice_risorsa='menu.hr' AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa='pagina.permessi_104');
UPDATE aut_risorse r JOIN aut_risorse p ON p.codice_risorsa='menu.hr' SET r.id_risorsa_padre=p.id_risorsa,r.descrizione='Permessi tutelati',r.percorso='/permessi_104.php',r.icona='la-user-shield',r.visibile_menu=1,r.ordinamento=26,r.attivo=1 WHERE r.codice_risorsa='pagina.permessi_104';

INSERT INTO aut_risorse (codice_risorsa,descrizione,tipo_risorsa,id_risorsa_padre,percorso,icona,visibile_menu,ordinamento,attivo)
SELECT 'pagina.benefici_hr','Benefici e diritti','pagina',p.id_risorsa,'/benefici_hr.php','la-id-card',1,47,1 FROM aut_risorse p WHERE p.codice_risorsa='pagina.configurazione_assenze' AND NOT EXISTS (SELECT 1 FROM aut_risorse WHERE codice_risorsa='pagina.benefici_hr');
UPDATE aut_risorse r JOIN aut_risorse p ON p.codice_risorsa='pagina.configurazione_assenze' SET r.id_risorsa_padre=p.id_risorsa,r.descrizione='Benefici e diritti',r.percorso='/benefici_hr.php',r.icona='la-id-card',r.visibile_menu=1,r.ordinamento=47,r.attivo=1 WHERE r.codice_risorsa='pagina.benefici_hr';

INSERT INTO aut_ruoli_permessi (id_ruolo,id_risorsa,permesso,consentito)
SELECT DISTINCT src.id_ruolo,dest.id_risorsa,src.permesso,src.consentito FROM aut_ruoli_permessi src JOIN aut_risorse origine ON origine.id_risorsa=src.id_risorsa JOIN aut_risorse dest ON dest.codice_risorsa='pagina.permessi_104' WHERE origine.codice_risorsa='pagina.assenze' AND NOT EXISTS (SELECT 1 FROM aut_ruoli_permessi x WHERE x.id_ruolo=src.id_ruolo AND x.id_risorsa=dest.id_risorsa AND x.permesso=src.permesso);
INSERT INTO aut_ruoli_permessi (id_ruolo,id_risorsa,permesso,consentito)
SELECT DISTINCT src.id_ruolo,dest.id_risorsa,src.permesso,src.consentito FROM aut_ruoli_permessi src JOIN aut_risorse origine ON origine.id_risorsa=src.id_risorsa JOIN aut_risorse dest ON dest.codice_risorsa='pagina.benefici_hr' WHERE origine.codice_risorsa='pagina.configurazione_assenze' AND NOT EXISTS (SELECT 1 FROM aut_ruoli_permessi x WHERE x.id_ruolo=src.id_ruolo AND x.id_risorsa=dest.id_risorsa AND x.permesso=src.permesso);

DROP TRIGGER IF EXISTS trg_hr_104_periodo_bi;
DELIMITER $$
CREATE TRIGGER trg_hr_104_periodo_bi BEFORE INSERT ON hr_richieste_periodi FOR EACH ROW
BEGIN
 DECLARE v_codice VARCHAR(50) DEFAULT ''; DECLARE v_id_utente INT UNSIGNED DEFAULT 0; DECLARE v_beneficio INT DEFAULT 0; DECLARE v_consente_giorni TINYINT DEFAULT 0; DECLARE v_consente_ore TINYINT DEFAULT 0; DECLARE v_plafond_giorni DECIMAL(6,2) DEFAULT 0; DECLARE v_plafond_minuti INT UNSIGNED DEFAULT 0; DECLARE v_minuti_giornata INT UNSIGNED DEFAULT 0; DECLARE v_usati_minuti DECIMAL(14,2) DEFAULT 0; DECLARE v_richiesti_minuti DECIMAL(14,2) DEFAULT 0; DECLARE v_totale_minuti DECIMAL(14,2) DEFAULT 0; DECLARE v_totale_giorni DECIMAL(14,4) DEFAULT 0;
 SELECT te.codice,r.id_utente_richiedente INTO v_codice,v_id_utente FROM hr_richieste r JOIN hr_tipologie_evento te ON te.id_tipologia_evento=r.id_tipologia_evento WHERE r.id_richiesta=NEW.id_richiesta LIMIT 1;
 IF v_codice='LEGGE_104' THEN
  SELECT COUNT(*),COALESCE(MAX(b.consente_giorni),0),COALESCE(MAX(b.consente_ore),0),COALESCE(MAX(b.plafond_giorni_mese),0),COALESCE(MAX(b.plafond_minuti_mese),0),COALESCE(MAX(b.minuti_giornata_equivalenza),0) INTO v_beneficio,v_consente_giorni,v_consente_ore,v_plafond_giorni,v_plafond_minuti,v_minuti_giornata FROM hr_benefici_utenti b WHERE b.id_utente=v_id_utente AND b.codice_beneficio='LEGGE_104' AND b.attivo=1 AND b.data_inizio<=NEW.data_da AND (b.data_fine IS NULL OR b.data_fine>=NEW.data_da);
  IF v_beneficio=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Permesso Legge 104 non abilitato da HR per questo dipendente.'; END IF;
  IF NEW.data_da<>NEW.data_a THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Per la Legge 104 inserire una singola giornata per richiesta.'; END IF;
  IF UPPER(NEW.tipo_periodo)='GIORNI' THEN IF v_consente_giorni<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La fruizione a giorni non e abilitata da HR.'; END IF; SET v_richiesti_minuti=v_minuti_giornata;
  ELSEIF UPPER(NEW.tipo_periodo)='ORE' THEN IF v_consente_ore<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La fruizione a ore non e abilitata da HR.'; END IF; SET v_richiesti_minuti=COALESCE(NEW.minuti_totali,TIME_TO_SEC(TIMEDIFF(NEW.ora_a,NEW.ora_da))/60);
  ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Modalita Legge 104 non valida.'; END IF;
  IF v_minuti_giornata=0 OR v_richiesti_minuti<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Configurazione plafond Legge 104 non valida.'; END IF;
  SELECT COALESCE(SUM(CASE WHEN UPPER(p.tipo_periodo)='GIORNI' THEN v_minuti_giornata ELSE COALESCE(p.minuti_totali,TIME_TO_SEC(TIMEDIFF(p.ora_a,p.ora_da))/60) END),0) INTO v_usati_minuti FROM hr_richieste r JOIN hr_tipologie_evento te ON te.id_tipologia_evento=r.id_tipologia_evento AND te.codice='LEGGE_104' JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta=r.id_stato_richiesta AND sr.codice IN ('APPROVATA','IN_ATTESA') JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_utente_richiedente=v_id_utente AND r.id_richiesta<>NEW.id_richiesta AND YEAR(p.data_da)=YEAR(NEW.data_da) AND MONTH(p.data_da)=MONTH(NEW.data_da);
  SET v_totale_minuti=v_usati_minuti+v_richiesti_minuti; SET v_totale_giorni=v_totale_minuti/v_minuti_giornata;
  IF v_plafond_minuti>0 AND v_totale_minuti>v_plafond_minuti THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Plafond mensile Legge 104 in ore superato.'; END IF;
  IF v_plafond_giorni>0 AND v_totale_giorni>v_plafond_giorni THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Plafond mensile Legge 104 in giorni superato.'; END IF;
 END IF;
END$$
DELIMITER ;

SELECT te.codice,te.descrizione,te.richiede_approvazione,te.consente_giorni,te.consente_ore,te.mostra_dettaglio_colleghi,te.mostra_dettaglio_responsabili,te.mostra_dettaglio_hr FROM hr_tipologie_evento te WHERE te.codice='LEGGE_104';
SELECT codice_risorsa,descrizione,percorso,visibile_menu,attivo FROM aut_risorse WHERE codice_risorsa IN ('pagina.permessi_104','pagina.benefici_hr') ORDER BY codice_risorsa;
