-- =============================================================================
-- FlowSync PostgreSQL initialization script
-- Runs once when the postgres container is first created (data volume empty).
--
-- Creates the system database (already created by POSTGRES_DB env var) and
-- grants all privileges to the flowsync user.
-- In isolated tenancy mode, individual tenant databases are created at runtime
-- by TenantDatabaseManager::createDatabase().
-- =============================================================================

-- Ensure the system database owner is correct
ALTER DATABASE flowsync_system OWNER TO flowsync;

-- Grant all privileges on the public schema
\c flowsync_system
GRANT ALL PRIVILEGES ON SCHEMA public TO flowsync;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO flowsync;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO flowsync;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO flowsync;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO flowsync;
