<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar conexión</title>
</head>
<body>
    <main>
        <h1>Conexión a la base de datos</h1>

        <?php if ($isConnected): ?>
            <p>Conexión exitosa a la base de datos <strong>data</strong>.</p>
        <?php else: ?>
            <p>No se pudo conectar a la base de datos <strong>data</strong>.</p>
            <p>Detalle: <?= htmlspecialchars($connectionError ?? 'No se recibió respuesta de la base de datos.', ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </main>
</body>
</html>
