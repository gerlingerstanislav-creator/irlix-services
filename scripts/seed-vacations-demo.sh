#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services
if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi

echo "[vacations-seed] Reading current employees"
employees_json="$($SUDO $COMPOSE exec -T employees php -r '
$host = getenv("DB_HOST") ?: "postgres";
$port = getenv("DB_PORT") ?: "5432";
$db = getenv("DB_DATABASE") ?: "irlix_services";
$user = getenv("DB_USERNAME") ?: "employees_app";
$password = getenv("DB_PASSWORD") ?: "";
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->query("select id, department_id, full_name from employees where department_id is not null order by department_id, id");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
')"

[ -n "$employees_json" ] || { echo "[vacations-seed] Employees query returned no data" >&2; exit 1; }

echo "[vacations-seed] Seeding September 2026 absences"
printf '%s' "$employees_json" | $SUDO $COMPOSE exec -T -e VACATIONS_DEMO_SEED_MONTH=2026-09 vacations php /app/bin/seed_demo_absences.php
