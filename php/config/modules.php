<?php

declare(strict_types=1);

return [
    'dashboard' => [
        'name' => 'Dashboard',
        'description' => 'Consulta el resumen del negocio, pendientes y alertas.',
        'category' => 'Panel de control',
        'initials' => 'DB',
        'color' => 'violet',
        'features' => ['Indicadores del negocio', 'Acciones rápidas', 'Pendientes y alertas'],
    ],
    'contactos' => [
        'name' => 'Contactos',
        'description' => 'Centraliza clientes, proveedores y sus datos.',
        'category' => 'Relaciones',
        'initials' => 'CO',
        'color' => 'blue',
        'features' => ['Clientes y proveedores', 'Datos de contacto', 'Historial de relaciones'],
    ],
    'inventario' => [
        'name' => 'Inventario',
        'description' => 'Organiza productos, existencias y movimientos.',
        'category' => 'Operaciones',
        'initials' => 'IN',
        'color' => 'emerald',
        'features' => ['Productos y categorías', 'Existencias', 'Movimientos de almacén'],
    ],
    'compras' => [
        'name' => 'Compras',
        'description' => 'Da seguimiento a proveedores y órdenes de compra.',
        'category' => 'Operaciones',
        'initials' => 'CM',
        'color' => 'amber',
        'features' => ['Solicitudes de compra', 'Órdenes a proveedores', 'Recepción de productos'],
    ],
    'ventas' => [
        'name' => 'Ventas',
        'description' => 'Administra oportunidades, pedidos y ventas.',
        'category' => 'Operaciones',
        'initials' => 'VE',
        'color' => 'violet',
        'features' => ['Cotizaciones', 'Pedidos de clientes', 'Seguimiento de ventas'],
    ],
    'contabilidad' => [
        'name' => 'Contabilidad',
        'description' => 'Prepara el control financiero y contable del negocio.',
        'category' => 'Finanzas',
        'initials' => 'CT',
        'color' => 'rose',
        'features' => ['Ingresos y gastos', 'Cuentas por cobrar y pagar', 'Reportes financieros'],
    ],
];
