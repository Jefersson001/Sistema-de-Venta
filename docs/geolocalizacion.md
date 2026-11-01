# Geolocalización de clientes y ventas

Las ubicaciones son opcionales y se conservan con seis decimales. Los registros anteriores permanecen sin coordenadas.

## Cliente

En Contactos, la ficha muestra la ubicación de la dirección principal. Cada línea de la pestaña Direcciones tiene sus propias coordenadas. Se pueden escribir o completar con «Capturar ubicación actual». Este botón obtiene la posición del dispositivo desde el que se abre la ficha: debe usarse cuando se esté en la dirección que se desea registrar.

## Orden de venta

El bloque «Ubicaciones del cliente y de la venta» contiene dos puntos independientes:

- **Ubicación del cliente:** se propone desde la dirección de entrega seleccionada. Puede ajustarse para esa orden sin modificar la ficha del contacto.
- **Ubicación donde se realizó la venta:** el vendedor puede capturar la posición de su dispositivo al registrar la venta. Se guarda por separado con el usuario autenticado y la fecha del servidor en horario de Caracas. La fecha corresponde al registro o cambio del punto; no certifica la hora física de captura.

Al cambiar el cliente o la dirección, se actualiza solamente el punto del cliente. Una orden guardada conserva su ubicación histórica aunque después cambie la dirección del contacto. La actualización explícita de direcciones permite tomar los datos actuales. Las entregas y facturas toman la ubicación del cliente; las devoluciones y notas de crédito conservan el punto de su documento de origen. La ubicación del vendedor permanece en la orden.

Los permisos de edición y el bloqueo de órdenes confirmadas también se aplican a las coordenadas.

## Captura y consulta

La captura se solicita únicamente al pulsar el botón. Requiere permiso del navegador y un contexto seguro: HTTPS o localhost. Al abrir el sistema por una IP de red con HTTP, puede escribirse la ubicación manualmente o continuar sin ella. Las coordenadas deben completarse en pareja; «Borrar ubicación» deja ambos campos vacíos y requiere guardar el formulario.

«Ver en mapa» abre OpenStreetMap con el punto seleccionado. No se carga un mapa ni se consulta la ubicación automáticamente. El sistema registra el origen declarado de las coordenadas (dirección, manual o dispositivo); no verifica que una posición corresponda físicamente a una persona.

## Instalación y validación

La migración `php php/install-geolocation.php` crea primero un respaldo privado y añade los campos y restricciones sin fabricar ubicaciones. Puede ejecutarse nuevamente. La migración monetaria excluye las coordenadas para conservar su precisión. La importación y exportación CSV admite coordenadas con signo y seis decimales.

`php test/geolocation.php` utiliza una base temporal y verifica contactos, permisos del vendedor y de consulta, posiciones independientes y opcionales, usuario de registro, coordenadas inválidas, historial, bloqueo de confirmadas, propagación a entregas y facturas y precisión CSV. Incluye pruebas de navegador con respuestas GPS simuladas, denegación de permisos, respuestas tardías y diseño móvil. La captura real depende del navegador y del dispositivo del usuario.
