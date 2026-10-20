-- PostgreSQL illustrative migration and query, NOT executed by the local eval.
-- In real migrations examine constraints, lock duration and existing data.
CREATE TABLE inventory_items (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  tenant_id bigint NOT NULL,
  available bigint NOT NULL DEFAULT 0 CHECK (available >= 0)
);
CREATE INDEX inventory_items_tenant_id_id_idx ON inventory_items(tenant_id, id);

-- Bind tenant id from verified principal; id from validated route; quantity > 0.
-- Statement is atomic; avoids read-check-write overselling.
UPDATE inventory_items
SET available = available - :quantity
WHERE tenant_id = :tenant_id
  AND id = :id
  AND available >= :quantity
RETURNING id, available;

-- Controller handles 0 rows as not found/insufficient stock using an authorized
-- lookup; all list/count/aggregate dashboards must also filter by tenant_id.
-- Investigate N+1 with measured query count then eager load only needed data.
