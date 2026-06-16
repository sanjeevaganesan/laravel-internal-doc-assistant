# Engineering Runbook

This runbook covers deployment procedures, incident response, on-call protocols,
and infrastructure management for the engineering team. All engineers are expected
to be familiar with these procedures before being added to the on-call rotation.

---

## Deployment Process

### Pre-Deployment Checklist

Before initiating any deployment to production, the following steps must be
completed and verified:

1. **Code Review Approval**: The pull request must have at least two approvals
   from senior engineers (L4 or above). No exceptions for hotfixes — get approval
   from any two engineers available.

2. **CI/CD Checks Passing**: All GitHub Actions workflows must be green. This includes
   unit tests, integration tests, static analysis (PHPStan level 8), and code style
   checks (Laravel Pint). A single failing check blocks deployment.

3. **Database Migration Review**: If the deployment includes database migrations,
   a DBA or senior backend engineer must review them separately. Migrations that
   add NOT NULL columns without a default to tables with >1M rows require a
   maintenance window.

4. **Staging Deployment Verified**: The exact same commit must have been deployed
   to the staging environment and verified by the feature owner within the last 24
   hours. Stale staging deployments invalidate the verification.

5. **Feature Flag Check**: New features must be behind a feature flag if they
   affect >10% of users. The flag must be set to 0% in production before deployment
   and ramped up post-deployment.

6. **Rollback Plan Documented**: For any deployment that modifies critical paths
   (auth, payments, data storage), a written rollback plan must exist in the
   deployment ticket before pushing to production.

### Deployment Steps

**Standard Deployment (automated):**
```bash
# 1. Trigger deployment via GitHub Actions workflow dispatch
# Go to: Actions → Deploy to Production → Run Workflow
# Select branch: main (only main branch can be deployed)

# 2. Monitor deployment progress in Slack: #deployments channel

# 3. Verify health check endpoint post-deployment
curl https://api.yourcompany.com/up
# Expected: HTTP 200 with body "OK"

# 4. Run smoke tests
php artisan smoke-test:run --env=production

# 5. Check error rates in Grafana: dashboards/api-error-rates
# Acceptable: <0.1% error rate in first 5 minutes post-deploy
```

**Hotfix Deployment:**
A hotfix is a deployment to fix a P0 or P1 production incident. Requirements:
- On-call engineer approves the hotfix
- One senior engineer reviews (can be async if P0 is ongoing)
- Deployment is tagged as "hotfix" in GitHub Actions
- Post-mortem is filed within 48 hours

### Rollback Procedure

If a deployment causes a P0 or P1 incident, initiate rollback immediately:

```bash
# Option 1: GitHub Actions rollback (preferred)
# Actions → Rollback Production → Run Workflow
# Enter the previous commit SHA

# Option 2: Manual rollback via CLI
./deploy.sh rollback --to=<previous-commit-sha> --env=production

# Option 3: Emergency database rollback (if migration was applied)
php artisan migrate:rollback --step=1 --env=production
# WARNING: Only use if the migration is reversible and on-call DBA approves
```

**After rollback:**
1. Post in #incidents: "Rollback initiated at HH:MM UTC, deployed <sha>"
2. Set an incident commander from the on-call team
3. Open a post-mortem document immediately

---

## On-Call Responsibilities

### Rotation Schedule

The on-call rotation operates 24/7. Each engineer is primary on-call for one week,
with a secondary engineer as backup. The schedule is maintained in PagerDuty and
published to the #engineering-oncall Slack channel every Monday.

**Primary on-call responsibilities:**
- Acknowledge PagerDuty alerts within 5 minutes (SLA)
- Investigate and resolve P0/P1 incidents
- Escalate to secondary if not resolved within 15 minutes
- Coordinate with product and customer success for user-facing incidents

**Secondary on-call responsibilities:**
- Available as backup if primary doesn't acknowledge within 5 minutes
- Assists primary during complex incidents
- Takes over primary duties if primary is incapacitated

### Severity Levels

| Level | Definition                                           | Response Time |
|-------|------------------------------------------------------|---------------|
| P0    | Full outage: no users can access core functionality  | 5 minutes     |
| P1    | Partial outage: >10% of users affected               | 15 minutes    |
| P2    | Degraded performance: no data loss, usable but slow  | 1 hour        |
| P3    | Minor issue: <1% of users affected, no data loss     | Next business day |

### Escalation Path

```
Alert fires → Primary on-call (5 min SLA)
           ↓ if no ack in 5 min
           Secondary on-call (5 more min)
           ↓ if no ack in 5 min
           Engineering Manager (paged via PagerDuty)
           ↓ for P0 incidents lasting >30 min
           VP Engineering + CTO (manual call)
```

---

## Incident Response

### Incident Commander Role

Every P0 and P1 incident must have a designated Incident Commander (IC).
The IC is the on-call primary engineer by default. The IC's responsibilities:

1. **Declare the incident**: Post to #incidents with severity, impact, and ETA
2. **Coordinate communication**: Update #incidents every 15 minutes during P0/P1
3. **Drive resolution**: Assign specific investigation tasks to available engineers
4. **Manage customer communication**: Coordinate with customer success on updates
5. **Close the incident**: Post resolution message with root cause summary
6. **File post-mortem**: Within 48 hours of incident resolution

### Common Runbooks (Quick Reference)

**Database connection pool exhaustion:**
```bash
# Check current connections
psql -h prod-db.internal -c "SELECT count(*) FROM pg_stat_activity;"
# If > 90% of max_connections:
# 1. Identify top queries: SELECT query, count(*) FROM pg_stat_activity GROUP BY query
# 2. Kill long-running queries if safe
# 3. Restart PHP-FPM workers if connection leak suspected: sudo systemctl restart php-fpm
```

**Redis memory pressure:**
```bash
redis-cli -h prod-redis.internal info memory
# If used_memory > 80% of maxmemory:
# 1. Check key distribution: redis-cli --bigkeys
# 2. Flush expired keys: redis-cli -h prod-redis.internal OBJECT ENCODING <key>
# 3. Increase maxmemory in redis.conf (requires approval from SRE)
```

**High API error rate:**
```bash
# 1. Check recent exceptions in Sentry: sentry.io/your-org/api-errors/
# 2. Check Laravel logs: tail -f /var/log/laravel/laravel.log
# 3. Check nginx error log: tail -f /var/log/nginx/error.log
# 4. Scale up EC2 instances if CPU > 80%: see scaling runbook
```

---

## Infrastructure Overview

### Production Environment

| Component      | Technology              | Region         |
|----------------|-------------------------|----------------|
| Application    | Laravel 13 on EC2       | us-east-1      |
| Database       | RDS PostgreSQL 16       | us-east-1 (HA) |
| Cache          | ElastiCache Redis 7     | us-east-1      |
| Queue Worker   | SQS + EC2               | us-east-1      |
| CDN            | CloudFront              | Global         |
| Object Storage | S3                      | us-east-1      |
| Monitoring     | CloudWatch + Datadog    | Global         |
| Error Tracking | Sentry                  | Global         |
| On-Call        | PagerDuty               | Global         |

### Environment URLs

- Production API: https://api.yourcompany.com
- Staging API: https://api-staging.yourcompany.com
- Grafana: https://grafana.internal/d/overview
- Sentry: https://sentry.io/your-org/

---

## Database Management

### Migration Guidelines

**Never do these on production without a maintenance window:**
- Adding a NOT NULL column to a table with >1M rows without a default
- Dropping a column that is still referenced in code
- Rebuilding an index on a >10GB table (use CREATE INDEX CONCURRENTLY instead)
- Changing a column type (requires full table rewrite)

**Safe migration patterns:**
```php
// SAFE: Add nullable column
$table->string('new_column')->nullable();

// SAFE: Add column with default
$table->string('status')->default('active');

// SAFE: Add index concurrently (avoids table lock)
// Must be done in a separate migration:
DB::statement('CREATE INDEX CONCURRENTLY idx_name ON table_name (column_name)');

// UNSAFE on large tables: avoid in production
$table->string('required_field'); // adds NOT NULL without default
```

### Backup Policy

- Full database backup: daily at 02:00 UTC (automated via AWS RDS)
- Point-in-time recovery: enabled, 7-day retention
- Backup validation: weekly restore test to isolated environment
- Manual backup before major migrations: `aws rds create-db-snapshot`
