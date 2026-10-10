INSERT INTO contact_types (name, system_key)
SELECT 'Proveedor', 'supplier'
WHERE NOT EXISTS (SELECT 1 FROM contact_types WHERE name = 'Proveedor' OR system_key = 'supplier');

UPDATE contact_types target LEFT JOIN contact_types assigned ON assigned.system_key = 'supplier'
SET target.system_key = 'supplier'
WHERE target.name = 'Proveedor' AND target.system_key IS NULL AND assigned.id IS NULL;
