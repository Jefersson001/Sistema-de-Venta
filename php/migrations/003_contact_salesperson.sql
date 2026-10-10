UPDATE contact_types target LEFT JOIN contact_types assigned ON assigned.system_key = 'administrator'
SET target.system_key = 'administrator' WHERE target.name = 'Administrador' AND target.system_key IS NULL AND assigned.id IS NULL;
UPDATE contact_types target LEFT JOIN contact_types assigned ON assigned.system_key = 'employee'
SET target.system_key = 'employee' WHERE target.name = 'Empleado' AND target.system_key IS NULL AND assigned.id IS NULL;
UPDATE contact_types target LEFT JOIN contact_types assigned ON assigned.system_key = 'client'
SET target.system_key = 'client' WHERE target.name = 'Cliente' AND target.system_key IS NULL AND assigned.id IS NULL;
UPDATE contact_types target LEFT JOIN contact_types assigned ON assigned.system_key = 'salesperson'
SET target.system_key = 'salesperson' WHERE target.name = 'Vendedor' AND target.system_key IS NULL AND assigned.id IS NULL;
