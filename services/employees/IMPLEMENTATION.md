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

## Organization structure

Implemented department fields:

- hierarchical parent/child relation;
- name and alias;
- nullable manager employee reference;
- nullable HR employee reference;
- derived direct employee count;
- nullable integer Yandex infrastructure ID;
- nullable LDAP/Keycloak group mapping;
- production classification flag.

The baseline organization structure is seeded from the existing internal service. Manager and HR values are deliberately not seeded because they must reference real Employees records. LDAP group values are stored as technical mapping data only; they do not yet trigger access provisioning.

The organization table uses the initial shared IRLIX design-system primitives and mirrors the compact neutral visual language of the current internal services.

## Deferred

- LDAP/Keycloak provisioning and group synchronization;
- compensation/FOT;
- permissions/scopes;
- full dismissal lifecycle;
- domain events.
