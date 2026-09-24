# Employees implementation notes

The first working business slice is implemented and deployed to the internal stand.

## Implemented

- Department persistence in the `employees` PostgreSQL schema;
- Employee persistence in the `employees` PostgreSQL schema;
- Laravel REST API for listing/creating/updating departments;
- Laravel REST API for listing/creating/updating employees;
- reference-data API for confirmed Employees dictionaries;
- backend validation against confirmed dictionaries;
- search plus department and status filtering;
- Vue employee registry with employee create/edit forms;
- Vue organization structure screen with hierarchical departments;
- department create/edit with parent department selection and cycle protection;
- selected department card with direct employee list;
- automatic database migrations during stand deployment;
- CI verification of health, department API and employee API through the stand Nginx.

## Confirmed employee fields currently implemented

- full name;
- department;
- position;
- employment status;
- work format;
- cooperation type;
- hire date.

## Confirmed dictionaries

Employment status:

- `Ожидает трудоустройства`;
- `Трудоустроен`;
- `Уволен`.

Work format:

- `Офис`;
- `Удалённо`.

Cooperation type:

- `Штат`;
- `ГПХ`;
- `ИП`;
- `Самозанятый`.

The frontend receives these values from the Employees API instead of maintaining a separate hardcoded copy.

## Organization structure scope in this slice

Implemented now:

- hierarchical parent/child departments;
- department name and alias;
- employee count;
- employees assigned directly to a department;
- create/edit operations;
- prevention of self/descendant parent cycles.

Confirmed but intentionally postponed until the relevant business details are clarified or implemented:

- department manager;
- HR responsible person;
- external/Yandex identifier;
- LDAP group;
- department classification.

LDAP/Keycloak provisioning, compensation/FOT, permissions/scopes, full dismissal lifecycle and domain events remain later vertical slices.
