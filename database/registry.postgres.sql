CREATE SCHEMA registry;
CREATE SCHEMA registryTransaction;

SET search_path TO registry, registryTransaction, public;

CREATE TABLE registry.domain_tld (
     "id" serial8,
     "tld"   varchar(32) NOT NULL,
     "idn_table"   varchar(255) NOT NULL,
     "secure"   SMALLINT NOT NULL,
     primary key ("id"),
     unique ("tld") 
);

CREATE TABLE registry.domain_price (
     "id" serial8,
     "tldid" int CHECK ("tldid" >= 0) NOT NULL,
     "command" varchar CHECK ("command" IN ( 'create','renew','transfer' )) NOT NULL default 'create',
     "m0"   decimal(10,2) NOT NULL default '0.00',
     "m12"   decimal(10,2) NOT NULL default '0.00',
     "m24"   decimal(10,2) NOT NULL default '0.00',
     "m36"   decimal(10,2) NOT NULL default '0.00',
     "m48"   decimal(10,2) NOT NULL default '0.00',
     "m60"   decimal(10,2) NOT NULL default '0.00',
     "m72"   decimal(10,2) NOT NULL default '0.00',
     "m84"   decimal(10,2) NOT NULL default '0.00',
     "m96"   decimal(10,2) NOT NULL default '0.00',
     "m108"   decimal(10,2) NOT NULL default '0.00',
     "m120"   decimal(10,2) NOT NULL default '0.00',
     primary key ("id"),
     unique ("tldid", "command") 
);

CREATE TABLE registry.domain_restore_price (
     "id" serial8 ,
     "tldid" int CHECK ("tldid" >= 0) NOT NULL,
     "price"   decimal(10,2) NOT NULL default '0.00',
     primary key ("id"),
     unique ("tldid") 
);

CREATE TABLE registry.error_log (
    "id" INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    "registrar_id" INT(11) NOT NULL,
    "log" TEXT NOT NULL,
    "date" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE registry.reserved_domain_names (
     "id" serial8 ,
     "name"   varchar(68) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'reserved','restricted' )) NOT NULL default 'reserved',
     primary key ("id"),
     unique ("name") 
);

CREATE TABLE registry.registrar (
     "id" serial8,
     "name"   varchar(255) NOT NULL,
     "iana_id"   int DEFAULT NULL,
     "clid"   varchar(16) NOT NULL,
     "pw"   varchar(256) NOT NULL,
     "prefix"   char(2) NOT NULL,
     "email"   varchar(255) NOT NULL,
     "whois_server"   varchar(255) NOT NULL,
     "rdap_server"   varchar(255) NOT NULL,
     "url"   varchar(255) NOT NULL,
     "abuse_email"   varchar(255) NOT NULL,
     "abuse_phone"   varchar(255) NOT NULL,
     "accountbalance"   decimal(8,2) NOT NULL default '0.00',
     "creditlimit"   decimal(8,2) NOT NULL default '0.00',
     "creditthreshold"   decimal(8,2) NOT NULL default '0.00',
     "thresholdtype" varchar CHECK ("thresholdtype" IN ( 'fixed','percent' )) NOT NULL default 'fixed',
     "currency"   varchar(5) NOT NULL,
     "crdate"   timestamp without time zone NOT NULL,
     "update"   TIMESTAMP ,
     primary key ("id"),
     unique ("clid") ,
     unique ("prefix") ,
     unique ("email") 
);

 CREATE OR REPLACE FUNCTION update_registrar() RETURNS trigger AS '
BEGIN
    NEW.update := CURRENT_TIMESTAMP;
    RETURN NEW;
END;
' LANGUAGE 'plpgsql';

-- before INSERT is handled by 'default CURRENT_TIMESTAMP'
CREATE TRIGGER add_current_date_to_registrar BEFORE UPDATE ON registry.registrar FOR EACH ROW EXECUTE PROCEDURE
update_registrar();

CREATE TABLE registry.registrar_whitelist (
     "id" serial8,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "addr"   varchar(45) NOT NULL,
     primary key ("id"),
     unique ("registrar_id", "addr") 
);

CREATE TABLE registry.registrar_contact (
     "id" serial8,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'owner','admin','billing','tech','abuse' )) NOT NULL default 'admin',
     "title"   varchar(255) NOT NULL,
     "first_name"   varchar(255) NOT NULL,
     "middle_name"   varchar(255) NOT NULL,
     "last_name"   varchar(255) NOT NULL,
     "org"   varchar(255) default NULL,
     "street1"   varchar(255) default NULL,
     "street2"   varchar(255) default NULL,
     "street3"   varchar(255) default NULL,
     "city"   varchar(255) NOT NULL,
     "sp"   varchar(255) default NULL,
     "pc"   varchar(16) default NULL,
     "cc"   char(2) NOT NULL,
     "voice"   varchar(17) default NULL,
     "fax"   varchar(17) default NULL,
     "email"   varchar(255) NOT NULL,
     primary key ("id"),
     unique ("registrar_id", "type") 
);

CREATE TABLE registry.poll (
     "id" serial8,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "qdate"   timestamp without time zone NOT NULL,
     "msg"   text default NULL,
     "msg_type" varchar CHECK ("msg_type" IN ( 'lowBalance','domainTransfer','contactTransfer' )) default NULL,
     "obj_name_or_id"   varchar(68),
     "obj_trstatus" varchar CHECK ("obj_trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "obj_reid"   varchar(255),
     "obj_redate"   timestamp without time zone,
     "obj_acid"   varchar(255),
     "obj_acdate"   timestamp without time zone,
     "obj_exdate"   timestamp without time zone default NULL,
     "registrarname"   varchar(255),
     "creditlimit"   decimal(8,2) default '0.00',
     "creditthreshold"   decimal(8,2) default '0.00',
     "creditthresholdtype" varchar CHECK ("creditthresholdtype" IN ( 'FIXED','PERCENT' )),
     "availablecredit"   decimal(8,2) default '0.00',
     primary key ("id")
);

CREATE TABLE registry.payment_history (
     "id" serial8,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "date"   timestamp without time zone NOT NULL,
     "description"   text NOT NULL,
     "amount"   decimal(8,2) NOT NULL,
     primary key ("id")
);

CREATE TABLE registry.statement (
     "id" serial8,
     "registrar_id" int CHECK ("registrar_id" >= 0) NOT NULL,
     "date"   timestamp without time zone NOT NULL,
     "command" varchar CHECK ("command" IN ( 'create','renew','transfer','restore','autoRenew' )) NOT NULL default 'create',
     "domain_name"   varchar(68) NOT NULL,
     "length_in_months"  smallint CHECK ("length_in_months" >= 0) NOT NULL,
     "from"   timestamp without time zone NOT NULL,
     "to"   timestamp without time zone NOT NULL,
     "amount"   decimal(8,2) NOT NULL,
     primary key ("id")
);

CREATE TABLE registry.contact (
     "id" serial8,
     "identifier"   varchar(255) NOT NULL,
     "voice"   varchar(17) default NULL,
     "voice_x"   int default NULL,
     "fax"   varchar(17) default NULL,
     "fax_x"   int default NULL,
     "email"   varchar(255) NOT NULL,
     "nin"   varchar(255) default NULL,
     "nin_type" varchar CHECK ("nin_type" IN ( 'personal','business' )) default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "crdate"   timestamp without time zone NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "update"   timestamp without time zone default NULL,
     "trdate"   timestamp without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp without time zone default NULL,
     "disclose_voice" varchar CHECK ("disclose_voice" IN ( '0','1' )) NOT NULL default '1',
     "disclose_fax" varchar CHECK ("disclose_fax" IN ( '0','1' )) NOT NULL default '1',
     "disclose_email" varchar CHECK ("disclose_email" IN ( '0','1' )) NOT NULL default '1',
     primary key ("id"),
     unique ("identifier") 
);

CREATE TABLE registry.contact_postalinfo (
     "id" serial8,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'int','loc' )) NOT NULL default 'int',
     "name"   varchar(255) NOT NULL,
     "org"   varchar(255) default NULL,
     "street1"   varchar(255) default NULL,
     "street2"   varchar(255) default NULL,
     "street3"   varchar(255) default NULL,
     "city"   varchar(255) NOT NULL,
     "sp"   varchar(255) default NULL,
     "pc"   varchar(16) default NULL,
     "cc"   char(2) NOT NULL,
     "disclose_name_int" varchar CHECK ("disclose_name_int" IN ( '0','1' )) NOT NULL default '1',
     "disclose_name_loc" varchar CHECK ("disclose_name_loc" IN ( '0','1' )) NOT NULL default '1',
     "disclose_org_int" varchar CHECK ("disclose_org_int" IN ( '0','1' )) NOT NULL default '1',
     "disclose_org_loc" varchar CHECK ("disclose_org_loc" IN ( '0','1' )) NOT NULL default '1',
     "disclose_addr_int" varchar CHECK ("disclose_addr_int" IN ( '0','1' )) NOT NULL default '1',
     "disclose_addr_loc" varchar CHECK ("disclose_addr_loc" IN ( '0','1' )) NOT NULL default '1',
     primary key ("id"),
     unique ("contact_id", "type") 
);

CREATE TABLE registry.contact_authinfo (
     "id" serial8,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "authtype" varchar CHECK ("authtype" IN ( 'pw','ext' )) NOT NULL default 'pw',
     "authinfo"   varchar(64) NOT NULL,
     primary key ("id"),
     unique ("contact_id") 
);

CREATE TABLE registry.contact_status (
     "id" serial8,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "status" varchar CHECK ("status" IN ( 'clientDeleteProhibited','clientTransferProhibited','clientUpdateProhibited','linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverTransferProhibited','serverUpdateProhibited' )) NOT NULL default 'ok',
     primary key ("id"),
     unique ("contact_id", "status") 
);

CREATE TABLE registry.domain (
     "id" serial8,
     "name"   varchar(68) NOT NULL,
     "tldid" int CHECK ("tldid" >= 0) NOT NULL,
     "registrant" int CHECK ("registrant" >= 0) default NULL,
     "crdate"   timestamp without time zone NOT NULL,
     "exdate"   timestamp without time zone NOT NULL,
     "update"   timestamp without time zone default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "trdate"   timestamp without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp without time zone default NULL,
     "transfer_exdate"   timestamp without time zone default NULL,
     "idnlang"   varchar(16) default NULL,
     "deltime"   timestamp without time zone default NULL,
     "restime"   timestamp without time zone default NULL,
     "rgpstatus" varchar CHECK ("rgpstatus" IN ( 'addPeriod','autoRenewPeriod','renewPeriod','transferPeriod','pendingDelete','pendingRestore','redemptionPeriod' )) default NULL,
     "rgppostdata"   text default NULL,
     "rgpdeltime"   timestamp without time zone default NULL,
     "rgprestime"   timestamp without time zone default NULL,
     "rgpresreason"   text default NULL,
     "rgpstatement1"   text default NULL,
     "rgpstatement2"   text default NULL,
     "rgpother"   text default NULL,
     "addperiod"  smallint CHECK ("addperiod" >= 0) default NULL,
     "autorenewperiod"  smallint CHECK ("autorenewperiod" >= 0) default NULL,
     "renewperiod"  smallint CHECK ("renewperiod" >= 0) default NULL,
     "transferperiod"  smallint CHECK ("transferperiod" >= 0) default NULL,
     "reneweddate"   timestamp without time zone default NULL,
     primary key ("id"),
     unique ("name") 
);

CREATE TABLE registry.domain_contact_map (
     "id" serial8,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "contact_id" int CHECK ("contact_id" >= 0) NOT NULL,
     "type" varchar CHECK ("type" IN ( 'admin','billing','tech' )) NOT NULL default 'admin',
     primary key ("id"),
     unique ("domain_id", "contact_id", "type") 
);

CREATE TABLE registry.domain_authinfo (
     "id" serial8,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "authtype" varchar CHECK ("authtype" IN ( 'pw','ext' )) NOT NULL default 'pw',
     "authinfo"   varchar(64) NOT NULL,
     primary key ("id"),
     unique ("domain_id") 
);

CREATE TABLE registry.domain_status (
     "id" serial8,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "status" varchar CHECK ("status" IN ( 'clientDeleteProhibited','clientHold','clientRenewProhibited','clientTransferProhibited','clientUpdateProhibited','inactive','ok','pendingCreate','pendingDelete','pendingRenew','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverHold','serverRenewProhibited','serverTransferProhibited','serverUpdateProhibited' )) NOT NULL default 'ok',
     primary key ("id"),
     unique ("domain_id", "status") 
);

CREATE TABLE registry.secdns (
     "id" serial8,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "maxsiglife" int CHECK ("maxsiglife" >= 0) default '604800',
     "interface" varchar CHECK ("interface" IN ( 'dsData','keyData' )) NOT NULL default 'dsData',
     "keytag" smallint CHECK ("keytag" >= 0) NOT NULL,
     "alg"  smallint CHECK ("alg" >= 0) NOT NULL default '5',
     "digesttype"  smallint CHECK ("digesttype" >= 0) NOT NULL default '1',
     "digest"   varchar(64) NOT NULL,
     "flags" smallint CHECK ("flags" >= 0) default NULL,
     "protocol" smallint CHECK ("protocol" >= 0) default NULL,
     "keydata_alg"  smallint CHECK ("keydata_alg" >= 0) default NULL,
     "pubkey"   varchar(255) default NULL,
     primary key ("id"),
     unique ("domain_id", "digest") 
);

CREATE TABLE registry.host (
     "id" serial8,
     "name"   varchar(255) NOT NULL,
     "domain_id" int CHECK ("domain_id" >= 0) default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "crdate"   timestamp without time zone NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "update"   timestamp without time zone default NULL,
     "trdate"   timestamp without time zone default NULL,
     primary key ("id"),
     unique ("name") 
);

CREATE TABLE registry.domain_host_map (
     "id" serial8,
     "domain_id" int CHECK ("domain_id" >= 0) NOT NULL,
     "host_id" int CHECK ("host_id" >= 0) NOT NULL,
     primary key ("id"),
     unique ("domain_id", "host_id") 
);

CREATE TABLE registry.host_addr (
     "id" serial8,
     "host_id" int CHECK ("host_id" >= 0) NOT NULL,
     "addr"   varchar(45) NOT NULL,
     "ip" varchar CHECK ("ip" IN ( 'v4','v6' )) NOT NULL default 'v4',
     primary key ("id"),
     unique ("host_id", "addr", "ip") 
);

CREATE TABLE registry.host_status (
     "id" serial8,
     "host_id" int CHECK ("host_id" >= 0) NOT NULL,
     "status" varchar CHECK ("status" IN ( 'clientDeleteProhibited','clientUpdateProhibited','linked','ok','pendingCreate','pendingDelete','pendingTransfer','pendingUpdate','serverDeleteProhibited','serverUpdateProhibited' )) NOT NULL default 'ok',
     primary key ("id"),
     unique ("host_id", "status") 
);

CREATE TABLE registry.domain_auto_approve_transfer (
     "id" serial8,
     "name"   varchar(68) NOT NULL,
     "registrant" int CHECK ("registrant" >= 0) default NULL,
     "crdate"   timestamp without time zone NOT NULL,
     "exdate"   timestamp without time zone NOT NULL,
     "update"   timestamp without time zone default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "trdate"   timestamp without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp without time zone default NULL,
     "transfer_exdate"   timestamp without time zone default NULL,
     primary key ("id")
);

CREATE TABLE registry.contact_auto_approve_transfer (
     "id" serial8,
     "identifier"   varchar(255) NOT NULL,
     "voice"   varchar(17) default NULL,
     "voice_x"   int default NULL,
     "fax"   varchar(17) default NULL,
     "fax_x"   int default NULL,
     "email"   varchar(255) NOT NULL,
     "nin"   varchar(255) default NULL,
     "nin_type" varchar CHECK ("nin_type" IN ( 'personal','business' )) default NULL,
     "clid" int CHECK ("clid" >= 0) NOT NULL,
     "crid" int CHECK ("crid" >= 0) NOT NULL,
     "crdate"   timestamp without time zone NOT NULL,
     "upid" int CHECK ("upid" >= 0) default NULL,
     "update"   timestamp without time zone default NULL,
     "trdate"   timestamp without time zone default NULL,
     "trstatus" varchar CHECK ("trstatus" IN ( 'clientApproved','clientCancelled','clientRejected','pending','serverApproved','serverCancelled' )) default NULL,
     "reid" int CHECK ("reid" >= 0) default NULL,
     "redate"   timestamp without time zone default NULL,
     "acid" int CHECK ("acid" >= 0) default NULL,
     "acdate"   timestamp without time zone default NULL,
     "disclose_voice" varchar CHECK ("disclose_voice" IN ( '0','1' )) NOT NULL default '1',
     "disclose_fax" varchar CHECK ("disclose_fax" IN ( '0','1' )) NOT NULL default '1',
     "disclose_email" varchar CHECK ("disclose_email" IN ( '0','1' )) NOT NULL default '1',
     primary key ("id")
);

CREATE TABLE registry.statistics (
     "id" serial8,
     "date"   date NOT NULL,
     "total_domains" int CHECK ("total_domains" >= 0) NOT NULL DEFAULT '0',
     "created_domains" int CHECK ("created_domains" >= 0) NOT NULL DEFAULT '0',
     "renewed_domains" int CHECK ("renewed_domains" >= 0) NOT NULL DEFAULT '0',
     "transfered_domains" int CHECK ("transfered_domains" >= 0) NOT NULL DEFAULT '0',
     "deleted_domains" int CHECK ("deleted_domains" >= 0) NOT NULL DEFAULT '0',
     "restored_domains" int CHECK ("restored_domains" >= 0) NOT NULL DEFAULT '0',
     primary key ("id"),
unique ("date") 
);

CREATE TABLE IF NOT EXISTS registry.users (
    "id" SERIAL PRIMARY KEY CHECK ("id" >= 0),
    "email" VARCHAR(249) UNIQUE NOT NULL,
    "password" VARCHAR(255) NOT NULL,
    "username" VARCHAR(100) DEFAULT NULL,
    "status" SMALLINT NOT NULL DEFAULT '0' CHECK ("status" >= 0),
    "verified" SMALLINT NOT NULL DEFAULT '0' CHECK ("verified" >= 0),
    "resettable" SMALLINT NOT NULL DEFAULT '1' CHECK ("resettable" >= 0),
    "roles_mask" INTEGER NOT NULL DEFAULT '0' CHECK ("roles_mask" >= 0),
    "registered" INTEGER NOT NULL CHECK ("registered" >= 0),
    "last_login" INTEGER DEFAULT NULL CHECK ("last_login" >= 0),
    "force_logout" INTEGER NOT NULL DEFAULT '0' CHECK ("force_logout" >= 0)
);

CREATE TABLE IF NOT EXISTS registry.users_confirmations (
    "id" SERIAL PRIMARY KEY CHECK ("id" >= 0),
    "user_id" INTEGER NOT NULL CHECK ("user_id" >= 0),
    "email" VARCHAR(249) NOT NULL,
    "selector" VARCHAR(16) UNIQUE NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0)
);
CREATE INDEX IF NOT EXISTS "email_expires" ON registry.users_confirmations ("email", "expires");
CREATE INDEX IF NOT EXISTS "user_id" ON registry.users_confirmations ("user_id");

CREATE TABLE IF NOT EXISTS registry.users_remembered (
    "id" BIGSERIAL PRIMARY KEY CHECK ("id" >= 0),
    "user" INTEGER NOT NULL CHECK ("user" >= 0),
    "selector" VARCHAR(24) UNIQUE NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0)
);
CREATE INDEX IF NOT EXISTS "user" ON registry.users_remembered ("user");

CREATE TABLE IF NOT EXISTS registry.users_resets (
    "id" BIGSERIAL PRIMARY KEY CHECK ("id" >= 0),
    "user" INTEGER NOT NULL CHECK ("user" >= 0),
    "selector" VARCHAR(20) UNIQUE NOT NULL,
    "token" VARCHAR(255) NOT NULL,
    "expires" INTEGER NOT NULL CHECK ("expires" >= 0)
);
CREATE INDEX IF NOT EXISTS "user_expires" ON registry.users_resets ("user", "expires");

CREATE TABLE IF NOT EXISTS registry.users_throttling (
    "bucket" VARCHAR(44) PRIMARY KEY,
    "tokens" REAL NOT NULL CHECK ("tokens" >= 0),
    "replenished_at" INTEGER NOT NULL CHECK ("replenished_at" >= 0),
    "expires_at" INTEGER NOT NULL CHECK ("expires_at" >= 0)
);
CREATE INDEX IF NOT EXISTS "expires_at" ON registry.users_throttling ("expires_at");

CREATE TABLE IF NOT EXISTS registry.registrar_users (
  registrar_id int NOT NULL,
  user_id int NOT NULL,
  PRIMARY KEY (registrar_id, user_id),
  FOREIGN KEY (registrar_id) REFERENCES registrar(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) WITH (OIDS=FALSE);
COMMENT ON TABLE registrar_users IS 'Linking Registrars with Panel Users';

CREATE TABLE registry.urs_actions (
     "id" serial8  PRIMARY KEY,
     "domain_name"   VARCHAR(255) NOT NULL,
     "urs_provider"   VARCHAR(255) NOT NULL,
     "action_date"   DATE NOT NULL,
     "status"   VARCHAR(255) NOT NULL
);

CREATE TYPE file_format_enum AS ENUM ('XML', 'CSV');
CREATE TYPE deposit_type_enum AS ENUM ('Full', 'Incremental', 'Differential');
CREATE TYPE status_enum AS ENUM ('Deposited', 'Retrieved', 'Failed');
CREATE TYPE verification_status_enum AS ENUM ('Verified', 'Failed', 'Pending');

CREATE TABLE registry.rde_escrow_deposits (
    "id" serial8 PRIMARY KEY,
    "deposit_id" VARCHAR(255) UNIQUE,  -- Unique deposit identifier
    "deposit_date" DATE NOT NULL,
    "revision" INTEGER NOT NULL DEFAULT 1,
    "file_name" VARCHAR(255) NOT NULL,
    "file_format" file_format_enum NOT NULL,  -- Format of the data file
    "file_size" BIGINT CHECK ("file_size" >= 0),
    "checksum" VARCHAR(64),
    "encryption_method" VARCHAR(255),  -- Details about how the file is encrypted
    "deposit_type" deposit_type_enum NOT NULL,
    "status" status_enum NOT NULL DEFAULT 'Deposited',
    "receiver" VARCHAR(255),  -- Escrow agent or receiver of the deposit
    "notes" TEXT,
    "verification_status" verification_status_enum DEFAULT 'Pending',
    "verification_notes" TEXT  -- Notes or remarks from the verification process
);

CREATE TYPE report_status_enum AS ENUM ('Pending', 'Submitted', 'Accepted', 'Rejected');

CREATE TABLE registry.icann_reports (
    "id" serial8 PRIMARY KEY,
    "report_date" DATE NOT NULL,
    "type" VARCHAR(255) NOT NULL,
    "file_name" VARCHAR(255),
    "submitted_date" DATE,
    "status" report_status_enum NOT NULL DEFAULT 'Pending',
    "notes" TEXT
);

CREATE TABLE registry.promotion_pricing (
    "id" serial8 PRIMARY KEY,
    "tld_id" INT CHECK ("tld_id" >= 0),
    "promo_name" VARCHAR(255) NOT NULL,
    "start_date" DATE NOT NULL,
    "end_date" DATE NOT NULL,
    "discount_percentage" DECIMAL(5,2),
    "discount_amount" DECIMAL(10,2),
    "description" TEXT,
    "conditions" TEXT,
    FOREIGN KEY ("tld_id") REFERENCES registry.domain_tld("id")
);

CREATE TABLE registry.premium_domain_pricing (
    "id" serial8 PRIMARY KEY,
    "domain_name" VARCHAR(255) NOT NULL,
    "tld_id" INT CHECK ("tld_id" >= 0) NOT NULL,
    "start_date" DATE NOT NULL,
    "end_date" DATE,
    "price" DECIMAL(10,2) NOT NULL,
    "conditions" TEXT,
    FOREIGN KEY ("tld_id") REFERENCES registry.domain_tld("id")
);

-- Create custom types for status and priority
CREATE TYPE ticket_status AS ENUM ('Open', 'In Progress', 'Resolved', 'Closed');
CREATE TYPE ticket_priority AS ENUM ('Low', 'Medium', 'High', 'Critical');

CREATE TABLE registry.ticket_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT
);

CREATE TABLE registry.support_tickets (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL, 
    category_id INTEGER NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ticket_status DEFAULT 'Open',
    priority ticket_priority DEFAULT 'Medium',
    reported_domain VARCHAR(255) DEFAULT NULL,
    nature_of_abuse TEXT DEFAULT NULL,
    evidence TEXT DEFAULT NULL,
    relevant_urls TEXT DEFAULT NULL,
    date_of_incident DATE DEFAULT NULL,
    date_created TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    last_updated TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES registry.users(id),
    FOREIGN KEY (category_id) REFERENCES registry.ticket_categories(id)
);

CREATE TABLE ticket_responses (
    id SERIAL PRIMARY KEY,
    ticket_id INTEGER NOT NULL,
    responder_id INTEGER NOT NULL,
    response TEXT NOT NULL,
    date_created TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
);

INSERT INTO registry.domain_tld VALUES('1','.COM.XX','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i');
INSERT INTO registry.domain_tld VALUES('2','.ORG.XX','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i');
INSERT INTO registry.domain_tld VALUES('3','.INFO.XX','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i');
INSERT INTO registry.domain_tld VALUES('4','.PRO.XX','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i');
INSERT INTO registry.domain_tld VALUES('5','.XX','/^(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-)(\.(?!-)(?!.*--)[A-Z0-9-]{1,63}(?<!-))*$/i');

INSERT INTO registry.domain_price VALUES (E'1',E'1',E'create',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'2',E'1',E'renew',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'3',E'1',E'transfer',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'4',E'2',E'create',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'5',E'2',E'renew',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'6',E'2',E'transfer',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'7',E'3',E'create',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'8',E'3',E'renew',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'9',E'3',E'transfer',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'10',E'4',E'create',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'11',E'4',E'renew',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'12',E'4',E'transfer',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'13',E'5',E'create',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'14',E'5',E'renew',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');
INSERT INTO registry.domain_price VALUES (E'15',E'5',E'transfer',E'0.00',E'5.00',E'10.00',E'15.00',E'20.00',E'25.00',E'30.00',E'35.00',E'40.00',E'45.00',E'50.00');

INSERT INTO registry.domain_restore_price VALUES (E'1',E'1',E'50.00');
INSERT INTO registry.domain_restore_price VALUES (E'2',E'2',E'50.00');
INSERT INTO registry.domain_restore_price VALUES (E'3',E'3',E'50.00');
INSERT INTO registry.domain_restore_price VALUES (E'4',E'4',E'50.00');
INSERT INTO registry.domain_restore_price VALUES (E'5',E'5',E'50.00');

INSERT INTO registry.registrar ("name", "clid", "pw", "prefix", "email", "whois_server", "rdap_server", "url", "abuse_email", "abuse_phone", "accountbalance", "creditlimit", "creditthreshold", "thresholdtype", "crdate", "update") VALUES (E'Namingo Test',E'namingo',E'{SHA}MyVYFDDrSjD546LIF11cMPu93ss=',E'XP',E'info@namingo.org',E'whois.namingo.org',E'https://rdap.namingo.org',E'http://www.namingo.org/',E'abuse@namingo.org',E'+380.123123123',E'100000.00',E'100000.00',E'500.00',E'fixed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);

INSERT INTO registry.registrar ("name", "clid", "pw", "prefix", "email", "whois_server", "rdap_server", "url", "abuse_email", "abuse_phone", "accountbalance", "creditlimit", "creditthreshold", "thresholdtype", "crdate", "update") VALUES (E'Registrar 002',E'testregistrar1',E'{SHA}ELxnUq/+JQS9a7pCUIZQpUrA3bY=',E'AA',E'info@testregistrar1.com',E'whois.namingo.org',E'https://rdap.namingo.org',E'http://www.namingo.org/',E'abuse@namingo.org',E'+380.123123123',E'100000.00',E'100000.00',E'500.00',E'fixed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);

INSERT INTO registry.registrar ("name", "clid", "pw", "prefix", "email", "whois_server", "rdap_server", "url", "abuse_email", "abuse_phone", "accountbalance", "creditlimit", "creditthreshold", "thresholdtype", "crdate", "update") VALUES (E'Registrar 003',E'testregistrar2',E'{SHA}jkkAfdvdLH5vbkCeQLGJy77LEGM=',E'BB',E'info@testregistrar2.com',E'whois.namingo.org',E'https://rdap.namingo.org',E'http://www.namingo.org/',E'abuse@namingo.org',E'+380.123123123',E'100000.00',E'100000.00',E'500.00',E'fixed',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);

INSERT INTO registry.ticket_categories (name, description) VALUES 
('Domain Transfer', 'Issues related to domain transfers between registrars'),
('Registration Errors', 'Errors or issues encountered during domain registration'),
('Billing & Payments', 'Questions or issues related to invoicing, payments, or account balances'),
('Technical Support', 'Technical problems or platform-related inquiries'),
('WHOIS Updates', 'Issues related to updating or querying WHOIS data'),
('Policy Violations', 'Reports of domains violating policies or terms of service'),
('EPP Command Errors', 'Issues related to EPP command failures or errors'),
('Abuse Notifications', 'Reports of domain abusive practices as per ICANN guidelines'),
('General Inquiry', 'General questions or feedback about services, platform or any non-specific topic'),
('Registrar Application', 'Queries or issues related to new registrar applications or onboarding'),
('RDAP Updates', 'Issues or queries related to the Registration Data Access Protocol (RDAP) updates');
 
ALTER TABLE registry.domain_price ADD FOREIGN KEY ("tldid") REFERENCES registry.domain_tld ("id");
ALTER TABLE registry.domain_restore_price ADD FOREIGN KEY ("tldid") REFERENCES registry.domain_tld ("id");
ALTER TABLE registry.registrar_whitelist ADD FOREIGN KEY ("registrar_id") REFERENCES registry.registrar ("id");
ALTER TABLE registry.registrar_contact ADD FOREIGN KEY ("registrar_id") REFERENCES registry.registrar ("id");
ALTER TABLE registry.poll ADD FOREIGN KEY ("registrar_id") REFERENCES registry.registrar ("id");
ALTER TABLE registry.payment_history ADD FOREIGN KEY ("registrar_id") REFERENCES registry.registrar ("id");
ALTER TABLE registry.statement ADD FOREIGN KEY ("registrar_id") REFERENCES registry.registrar ("id");
ALTER TABLE registry.contact ADD FOREIGN KEY ("clid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.contact ADD FOREIGN KEY ("crid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.contact ADD FOREIGN KEY ("upid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.contact_postalinfo ADD FOREIGN KEY ("contact_id") REFERENCES registry.contact ("id");
ALTER TABLE registry.contact_authinfo ADD FOREIGN KEY ("contact_id") REFERENCES registry.contact ("id");
ALTER TABLE registry.contact_status ADD FOREIGN KEY ("contact_id") REFERENCES registry.contact ("id");
ALTER TABLE registry.domain ADD FOREIGN KEY ("clid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.domain ADD FOREIGN KEY ("crid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.domain ADD FOREIGN KEY ("upid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.domain ADD FOREIGN KEY ("registrant") REFERENCES registry.contact ("id");
ALTER TABLE registry.domain ADD FOREIGN KEY ("reid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.domain ADD FOREIGN KEY ("acid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.domain ADD FOREIGN KEY ("tldid") REFERENCES registry.domain_tld ("id");
ALTER TABLE registry.domain_contact_map ADD FOREIGN KEY ("domain_id") REFERENCES registry.domain ("id");
ALTER TABLE registry.domain_contact_map ADD FOREIGN KEY ("contact_id") REFERENCES registry.contact ("id");
ALTER TABLE registry.domain_authinfo ADD FOREIGN KEY ("domain_id") REFERENCES registry.domain ("id");
ALTER TABLE registry.domain_status ADD FOREIGN KEY ("domain_id") REFERENCES registry.domain ("id");
ALTER TABLE registry.secdns ADD FOREIGN KEY ("domain_id") REFERENCES registry.domain ("id");
ALTER TABLE registry.host ADD FOREIGN KEY ("clid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.host ADD FOREIGN KEY ("crid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.host ADD FOREIGN KEY ("upid") REFERENCES registry.registrar ("id");
ALTER TABLE registry.host ADD FOREIGN KEY ("domain_id") REFERENCES registry.domain ("id");
ALTER TABLE registry.domain_host_map ADD FOREIGN KEY ("domain_id") REFERENCES registry.domain ("id");
ALTER TABLE registry.domain_host_map ADD FOREIGN KEY ("host_id") REFERENCES registry.host ("id");
ALTER TABLE registry.host_addr ADD FOREIGN KEY ("host_id") REFERENCES registry.host ("id");
ALTER TABLE registry.host_status ADD FOREIGN KEY ("host_id") REFERENCES registry.host ("id");

CREATE TABLE registryTransaction.transaction_identifier (
    id BIGSERIAL PRIMARY KEY,
    registrar_id INT NOT NULL,
    clTRID VARCHAR(64),
    clTRIDframe TEXT,
    cldate TIMESTAMP WITHOUT TIME ZONE,
    clmicrosecond INT,
    cmd VARCHAR(10) CHECK (cmd IN ('login','logout','check','info','poll','transfer','create','delete','renew','update')),
    obj_type VARCHAR(10) CHECK (obj_type IN ('domain','host','contact')),
    obj_id TEXT,
    code SMALLINT,
    msg VARCHAR(255),
    svTRID VARCHAR(64),
    svTRIDframe TEXT,
    svdate TIMESTAMP WITHOUT TIME ZONE,
    svmicrosecond INT,
    CONSTRAINT unique_clTRID UNIQUE (clTRID),
    CONSTRAINT unique_svTRID UNIQUE (svTRID),
    CONSTRAINT transaction_identifier_ibfk_1 FOREIGN KEY (registrar_id) REFERENCES registry.registrar (id) ON DELETE RESTRICT
);