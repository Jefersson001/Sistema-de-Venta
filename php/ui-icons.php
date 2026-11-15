<?php
declare(strict_types=1);

function kontaIcon(string $name,string $class=''): string {
    $paths=[
        'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="11" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="18" width="7" height="3" rx="1"/>',
        'contactos'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a5 5 0 0 1 10 0v3m3-16a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5"/>',
        'inventario'=>'<path d="m12 3 9 5v10l-9 5-9-5V8l9-5Zm-9 5 9 5 9-5M12 13v10M7.5 5.5l9 5v4"/>',
        'compras'=>'<path d="M3 3h2l3 13h11l3-10H6m2 6h12"/><circle cx="9" cy="21" r="1"/><circle cx="18" cy="21" r="1"/>',
        'ventas'=>'<path d="M4 10v11h16V10M3 4h18l1 6a3 3 0 0 1-5 2 3 3 0 0 1-5 0 3 3 0 0 1-5 0 3 3 0 0 1-5-2l1-6Zm6 17v-6h6v6"/>',
        'venta_directa'=>'<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 5h4m-2 4-3 4h6l-3 4m-1 2h2"/>',
        'contabilidad'=>'<path d="M12 3v18M3 7h18M6 7l-4 8h8L6 7Zm12 0-4 8h8l-4-8ZM8 21h8"/>',
        'restaurante'=>'<path d="M3 20h18M4 16a8 8 0 0 1 16 0H4Zm8-12V2m-2 2h4m-2 0v4"/>',
        'ajustes'=>'<path d="m9 3-1 3-3 1-2 3 2 2-1 4 3 2 3-1 3 3 3-2 1-3 4-1v-4l-3-2-1-3-4 1-3-2-1 2Z"/><circle cx="12" cy="12" r="3"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'external'=>'<path d="M7 17 17 7M7 7h10v10"/>',
        'bolt'=>'<path d="m13 2-9 12h7l-1 8 10-12h-7l1-8Z"/>',
        'closing'=>'<rect x="5" y="3" width="14" height="19" rx="2"/><path d="M9 7h6M9 11h1m4 0h1m-6 4h1m4 0h1m-6 4h6"/>',
        'moon'=>'<path d="M21 13a9 9 0 0 1-10-10A9 9 0 1 0 21 13Z"/>',
        'shield'=>'<path d="m12 2 8 4v6c0 5-8 10-8 10S4 17 4 12V6l8-4Z"/><path d="m8 12 3 3 5-6"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'save'=>'<path d="M5 3h12l4 4v14H3V3h2Zm2 0v6h10V3M7 21v-8h10v8"/>',
        'back'=>'<path d="M19 12H5m6-6-6 6 6 6"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'edit'=>'<path d="m16 3 5 5-12 12-6 1 1-6L16 3Zm-3 3 5 5"/>',
        'filter'=>'<path d="M4 5h16l-6 7v7l-4 2v-9L4 5Z"/>',
        'refresh'=>'<path d="M20 7v5h-5M4 17v-5h5m-4-4a8 8 0 0 1 13-3l2 3M4 16l2 3a8 8 0 0 0 13-3"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'upload'=>'<path d="M12 16V4m-5 5 5-5 5 5M4 16v5h16v-5"/>',
        'empty'=>'<path d="M4 5h16v14H4V5Zm0 8h5l2 3h2l2-3h5M8 9h8"/>',
    ];
    $class=htmlspecialchars($class,ENT_QUOTES,'UTF-8');
    return '<svg class="'.$class.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['dashboard']).'</svg>';
}
