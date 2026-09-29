-- Run as the Oracle user that the weighbridge will connect with (or a DBA, then GRANT).
-- Tested syntax: Oracle 11g and later.

CREATE TABLE WEIGHBRIDGE_TICKETS (
    TICKET_NO         VARCHAR2(30)   NOT NULL,
    VEHICLE_NO        VARCHAR2(30)   NOT NULL,
    PARTY             VARCHAR2(200),
    MATERIAL          VARCHAR2(200),
    DIRECTION         VARCHAR2(10),
    DRIVER            VARCHAR2(100),
    CHALLAN_NO        VARCHAR2(60),
    REMARKS           VARCHAR2(500),
    GROSS_KG          NUMBER(12,2),
    TARE_KG           NUMBER(12,2),
    NET_KG            NUMBER(12,2),
    FIRST_WEIGHT_AT   TIMESTAMP,
    SECOND_WEIGHT_AT  TIMESTAMP,
    STATUS            VARCHAR2(12),
    OPERATOR          VARCHAR2(50),
    LOCAL_ID          NUMBER(12),
    SYNCED_AT         TIMESTAMP DEFAULT SYSTIMESTAMP,
    CONSTRAINT PK_WB_TICKETS PRIMARY KEY (TICKET_NO)
);

CREATE INDEX IX_WB_TICKETS_VEH  ON WEIGHBRIDGE_TICKETS (VEHICLE_NO);
CREATE INDEX IX_WB_TICKETS_DATE ON WEIGHBRIDGE_TICKETS (SECOND_WEIGHT_AT);

-- Optional: dedicated least-privilege user (run as DBA)
-- CREATE USER wb_sync IDENTIFIED BY "ChangeMe#123" DEFAULT TABLESPACE users QUOTA 500M ON users;
-- GRANT CREATE SESSION TO wb_sync;
-- Then create the table above while connected as wb_sync.
-- The app only needs SELECT/INSERT/UPDATE on WEIGHBRIDGE_TICKETS and SELECT on V$VERSION (for the Test button; optional).
