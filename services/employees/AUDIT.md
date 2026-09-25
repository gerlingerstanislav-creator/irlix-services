# Employees audit trail

Employees keeps an application audit trail for sensitive mutations. The log is intended for operational investigation and access-control accountability; it is separate from the domain event stream.

## Access

`GET /api/audit` is available only to callers with `audit.read`. In the current access model that permission is granted to full administrators (`company-admin` and the technical `platform-admin`). HR, Finance and department managers do not receive audit access.

The Employees UI exposes **История действий** only when `audit.read` is present.

## Recorded actions

The mutation journal records successful and failed attempts for the supported mutation endpoints, including:

- `employee.created`;
- `employee.updated`;
- `employee.deleted`;
- `employee.dismissed`;
- `employee.rehired`;
- `employee.cooperation_changed`;
- `employee.salary_changed`;
- `employee.onboarding_email_sent`;
- `employee.access_changed`;
- `department.created`;
- `department.updated`.

Failed operations use the same action with `.failed` suffix. Automatic onboarding after employee creation produces a separate onboarding audit record when its result is known.

## Audit record

Each record can contain:

- timestamp and request id;
- Keycloak subject and linked employee id/name of the actor;
- action and status;
- target type/id/label;
- before and after snapshots;
- changed-field list and HTTP metadata.

Employee snapshots may contain sensitive employee and current salary data. Audit API access therefore remains restricted to full administrators.

Hard deletion does not delete historical audit rows. Actor and target ids are intentionally stored without foreign-key constraints so that an audit record remains readable after a business entity is removed.

## Querying

`GET /api/audit` supports filters:

- `employee_id`;
- `action`;
- `actor`;
- `from` / `to`;
- `limit` (maximum 200).

There is no API for changing or deleting audit rows.

## Failure semantics

Audit writing is best effort after the business request has completed. A storage problem in the audit subsystem is reported to application logs but does not roll back a successfully completed Keycloak/SMTP/business operation. Reliable cross-service propagation uses the separate transactional outbox described in `EVENTS.md`.

Retention/archival duration is not yet fixed and must be defined before production compliance policy is finalized.
