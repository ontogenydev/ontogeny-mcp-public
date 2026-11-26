# Local integration / v0.1.1

These tests use a disposable WordPress and MariaDB database, never a hosted deployment. The environment publishes no host ports and uses an internal network. All attempted mail is intercepted; fixture credentials and addresses are generated for the lab only. No production secrets or configuration are required.

From the repository root, with Docker Compose available:

```sh
docker compose -p ontogeny-mcp-lab -f tests/integration/compose.yaml up -d --wait --wait-timeout 180
docker compose -p ontogeny-mcp-lab -f tests/integration/compose.yaml exec -T wordpress php /opt/ontogeny/tests/integration/a03.php install
docker compose -p ontogeny-mcp-lab -f tests/integration/compose.yaml exec -T wordpress php /opt/ontogeny/tests/integration/a03.php run
docker compose -p ontogeny-mcp-lab -f tests/integration/compose.yaml exec -T wordpress php /opt/ontogeny/tests/integration/a04.php candidate
```

Stop at any nonzero exit. The scripts verify exact runtime blob hashes and the lab environment before destructive fixture operations. They exercise real InnoDB replay and quota boundaries, SQL failures, mail-failure handling, revocation, overlapping credential administration, orphan recovery, stale cleanup/cache and administrative error reporting. The image references are the digests reported by the operator of the earlier validated lab; availability of a fresh pull must be checked. Do not substitute mutable tags silently. These are scoped integration tests, not proof of all concurrency schedules or equivalence to a hosted database.

Cleanup deletes only the named disposable project's containers and volumes:

```sh
docker compose -p ontogeny-mcp-lab -f tests/integration/compose.yaml down -v
```
