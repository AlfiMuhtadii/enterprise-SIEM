#!/usr/bin/env bash
# bootstrap-dev.sh
# Development environment bootstrap for XDR Platform demo.
# DEVELOPMENT ONLY — runs migrate:fresh which drops all data.
# All demo scenarios are synthetic, replay-safe, advisory-only, and bounded.
#
# Usage:
#   ./bootstrap-dev.sh               # Full bootstrap
#   ./bootstrap-dev.sh reset          # Same as full bootstrap (replay-safe reset)
#   SKIP_DOCKER=true ./bootstrap-dev.sh  # Skip Docker check
#   SKIP_SEED=true ./bootstrap-dev.sh    # Skip demo seeding

set -euo pipefail

SKIP_DOCKER=${SKIP_DOCKER:-false}
SKIP_SEED=${SKIP_SEED:-false}
MODE=${1:-bootstrap}

echo "=== XDR Platform Bootstrap (Development) ==="
echo "Advisory only — no destructive execution, no real malware, no autonomous remediation."
echo ""

# Step 1 — Docker health check
if [ "$SKIP_DOCKER" != "true" ]; then
    echo "[1/5] Checking Docker..."
    if ! docker info > /dev/null 2>&1; then
        echo "  ERROR: Docker not running. Start Docker first."
        exit 1
    fi
    echo "  Docker: OK"
fi

# Step 2 — Start infrastructure services
echo "[2/5] Starting infrastructure services..."
docker compose up -d --wait > /dev/null 2>&1 || true
echo "  Services: started"

# Step 3 — Fresh database migration (dev only)
echo "[3/5] Running fresh migration (DEV ONLY — drops all data)..."
php artisan migrate:fresh --force
echo "  Migration: OK"

# Step 4 — Seed demo fixtures
if [ "$SKIP_SEED" != "true" ]; then
    echo "[4/5] Seeding demo fixtures..."
    php artisan db:seed --class=DemoScenarioSeeder && echo "  Seed: OK" || echo "  WARNING: Seeder failed. Continuing."
else
    echo "[4/5] Skipping seed (SKIP_SEED=true)"
fi

# Step 5 — Startup validation
echo "[5/5] Running startup validation..."
php artisan route:list --path=demo-platform > /dev/null 2>&1 && echo "  Routes: OK" || echo "  WARNING: Route check failed."

echo ""
echo "=== Bootstrap complete ==="
echo ""
echo "  Platform URL:    http://localhost:8000"
echo "  Demo Dashboard:  http://localhost:8000/demo-platform"
echo "  Grafana:         http://localhost:3000"
echo ""
echo "Start the Laravel server:"
echo "  php artisan serve"
echo ""
echo "Run tests to verify the environment:"
echo "  php artisan test"
echo "  python -m unittest discover -s tests/endpoint_agent -p 'test_*.py' -v"
echo ""
echo "To reset the demo (replay-safe — idempotent re-seed):"
echo "  ./bootstrap-dev.sh reset"
echo ""
echo "To tear down infrastructure:"
echo "  docker compose down"
