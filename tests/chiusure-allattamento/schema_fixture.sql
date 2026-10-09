-- Solo per un database di test isolato, mai sul sito.
CREATE DATABASE levante_regole_test;
USE levante_regole_test;
CREATE TABLE aut_utenti (id_utente INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO aut_utenti VALUES (1),(2);
CREATE TABLE aut_risorse (id_risorsa INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codice_risorsa VARCHAR(100) UNIQUE, descrizione VARCHAR(255), tipo_risorsa VARCHAR(30),
    id_risorsa_padre INT UNSIGNED, percorso VARCHAR(255), icona VARCHAR(100), visibile_menu TINYINT,
    ordinamento INT, attivo TINYINT) ENGINE=InnoDB;
INSERT INTO aut_risorse (codice_risorsa,attivo) VALUES
    ('pagina.configurazione_assenze',1),('pagina.assenze',1),('pagina.benefici_hr',1);
CREATE TABLE aut_ruoli (id_ruolo INT UNSIGNED PRIMARY KEY,codice_ruolo VARCHAR(100)) ENGINE=InnoDB;
INSERT INTO aut_ruoli VALUES (1,'hr_responsabile_personale'),(2,'admin_portale'),(3,'dipendente');
CREATE TABLE aut_ruoli_permessi (id_ruolo INT UNSIGNED,id_risorsa INT UNSIGNED,permesso VARCHAR(20),consentito TINYINT,
    UNIQUE KEY uk_permesso (id_ruolo,id_risorsa,permesso)) ENGINE=InnoDB;
CREATE TABLE hr_tipologie_evento (id_tipologia_evento INT UNSIGNED PRIMARY KEY,codice VARCHAR(50),consente_giorni TINYINT,consente_ore TINYINT) ENGINE=InnoDB;
INSERT INTO hr_tipologie_evento VALUES (1,'ALLATTAMENTO',1,1),(2,'FERIE',1,0);
CREATE TABLE hr_benefici_utenti (id_utente INT UNSIGNED,codice_beneficio VARCHAR(50),data_inizio DATE,data_fine DATE,
    consente_giorni TINYINT,consente_ore TINYINT,attivo TINYINT) ENGINE=InnoDB;
INSERT INTO hr_benefici_utenti VALUES (1,'ALLATTAMENTO','2099-01-01',NULL,1,1,1);
CREATE TABLE hr_stati_richiesta (id_stato_richiesta INT UNSIGNED PRIMARY KEY,codice VARCHAR(50)) ENGINE=InnoDB;
INSERT INTO hr_stati_richiesta VALUES (1,'APPROVATA'),(2,'IN_ATTESA'),(3,'ANNULLATA'),(4,'RIFIUTATA');
CREATE TABLE hr_richieste (id_richiesta INT UNSIGNED PRIMARY KEY,id_utente_richiedente INT UNSIGNED,
    id_tipologia_evento INT UNSIGNED,id_stato_richiesta INT UNSIGNED) ENGINE=InnoDB;
CREATE TABLE hr_richieste_periodi (id_richiesta INT UNSIGNED,data_da DATE,data_a DATE,tipo_periodo VARCHAR(10),ora_da TIME,ora_a TIME) ENGINE=InnoDB;
INSERT INTO hr_richieste VALUES (1,1,1,1),(2,1,1,2),(3,1,1,3),(4,1,1,4),(5,2,1,1),(6,1,2,1),(7,1,1,1),(8,1,1,1);
INSERT INTO hr_richieste_periodi VALUES
    (1,'2099-06-01','2099-06-01','ORE','08:00','09:00'),
    (2,'2099-06-01','2099-06-01','ORE','09:00','10:00'),
    (3,'2099-06-01','2099-06-01','ORE','10:00','12:00'),
    (4,'2099-06-01','2099-06-01','ORE','10:00','12:00'),
    (5,'2099-06-01','2099-06-01','ORE','10:00','12:00'),
    (6,'2099-06-01','2099-06-01','ORE','10:00','12:00'),
    (7,'2099-06-02','2099-06-02','GIORNI',NULL,NULL),
    (8,'2099-06-03','2099-06-03','ORE','10:00','12:00');
