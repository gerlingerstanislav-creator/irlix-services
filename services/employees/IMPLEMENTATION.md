# Employees implementation notes

The first working business slice is implemented.

## Implemented

- Department persistence in the `employees` PostgreSQL schema;
- Employee persistence in the `employees` PostgreSQL schema;
- Laravel REST API for listing/creating/updating departments;
- Laravel REST API for listing/creating/updating employees;
- search and department filtering;
- Vue UI for the employee registry, department creation and employee create/edit forms;
- automatic database migrations during stand deployment;
- CI verification of health, department API and employee API through the stand Nginx.

## Current confirmed employee fields

- full name;
- department;
- position;
- employment status;
- work format;
- hire date.

Unknown workflow rules from the product specification remain open rather than being guessed. LDAP/Keycloak provisioning, compensation/FOT, final dictionaries, permissions/scopes, dismissal lifecycle and domain events are intentionally left for later vertical slices.
