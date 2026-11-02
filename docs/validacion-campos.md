# Validación de campos de captura

Las reglas se aplican al escribir y pegar, y se comprueban nuevamente en el servidor al guardar. No modifican automáticamente los registros existentes.

## Documentos

En Contactos, la letra del tipo de documento se selecciona por separado. Los tipos V y E admiten únicamente dígitos en el número. Los tipos J, G y C admiten dígitos y un guion opcional antes del dígito final. Los pasaportes y los tipos personalizados permiten caracteres alfanuméricos y guiones entre grupos. Se conservan los ceros iniciales.

Pegar un documento con el prefijo correspondiente, por ejemplo V-00123456, separa la letra del número. Un prefijo diferente se rechaza para tipos numéricos. Las letras que forman parte de un documento alfanumérico se conservan. Estas reglas verifican el formato de captura; no consultan registros de identidad ni calculan dígitos verificadores.

En Empresa, Documento / RIF admite un número, una letra inicial opcional y un guion opcional antes del dígito final.

## Teléfono y móvil

Se permiten de 7 a 15 dígitos, un signo + opcional al principio, espacios, guiones y paréntesis equilibrados. No se admiten letras, signos + intermedios o repetidos ni otros símbolos. El campo sigue siendo opcional cuando ya lo era. Se conserva el formato escrito, incluidos los ceros iniciales.

Ejemplos: `0212-5550101`, `0412-5550202`, `+58 (412) 555-0202`.

## Cantidades, importes y otros números

Los campos de captura numérica y los marcados con teclado decimal/numeral bloquean letras y notación exponencial. Los importes, precios, cantidades, porcentajes, pesos, dimensiones y tasas admiten hasta dos decimales con coma o punto, sin separadores de miles. Las coordenadas conservan su signo y hasta seis decimales. Los campos enteros, como días, capacidades o cantidad de mesas, no admiten fracciones.

Los límites, valores positivos, rangos y reglas de negocio de cada módulo se siguen verificando en el servidor. Por ejemplo, una serie representa una unidad y una salida no puede superar las existencias disponibles. Se reforzó además el rechazo de estructuras no válidas enviadas como costo o porcentaje, para que no se conviertan silenciosamente en cero.

El pegado inválido se rechaza completo: `1e3` no se transforma en `13` y `1.000,00` no se interpreta como un importe diferente. Las líneas añadidas dinámicamente utilizan las mismas reglas.

Los nombres, direcciones, códigos postales, referencias, códigos de barras, lotes y números de serie conservan su formato de texto o alfanumérico según su función; no se les aplica una restricción numérica general.

## Validación

`php test/input-validation.php` ejecuta validaciones de formato, peticiones HTTP que intentan guardar datos incorrectos y una revisión en navegador. Comprueba escritura y pegado, prefijos, pasaportes, teléfonos, ceros iniciales, guardado/recarga, decimales con coma, líneas dinámicas, códigos de barras, coordenadas y anchura móvil. Utiliza una base y usuarios temporales.
