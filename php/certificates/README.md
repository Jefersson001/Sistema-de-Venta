# Certificados públicos para la consulta del BCV

`bcv-sectigo.pem` contiene únicamente autoridades de certificación públicas para `www.bcv.org.ve`, actualizado el 25 de noviembre de 2026:

- **Sectigo Public Server Authentication CA DV R36**, válida hasta el 21 de marzo de 2036.
- **Sectigo Public Server Authentication Root R46**, válida hasta el 21 de marzo de 2046.
- **Sectigo Public Server Authentication CA OV R36**, válida hasta el 21 de marzo de 2036.

Se obtuvo la cadena mediante una conexión que verificó el nombre del servidor y su confianza con el almacén de Windows; se verifican además firmas y huellas en `test/bcv-certificates.php`. No contiene el certificado final del sitio, claves privadas, contraseñas ni tasas de cambio.

Sectigo documenta ambas autoridades en su [jerarquía oficial](https://support.sectigo.com/Com_KnowledgeDetailPage?Id=kA0Uj0000004IrB) y publica el [intermedio DV R36](http://crt.sectigo.com/SectigoPublicServerAuthenticationCADVR36.crt) y la [raíz R46](http://crt.sectigo.com/SectigoPublicServerAuthenticationRootR46.crt).

PHP/cURL utiliza este archivo solamente para la consulta HTTPS al BCV. Continúa verificando la firma, vigencia y nombre del servidor; no cambia el almacén global. Windows conserva su consulta nativa con Schannel salvo que se configure explícitamente `NEXO_BCV_CA_FILE`.

Si el BCV cambia de autoridad certificadora, revisar la nueva cadena contra una fuente confiable y actualizar el bundle y sus pruebas. Una renovación del certificado del sitio bajo estas mismas autoridades no requiere guardar el certificado final nuevo.

El 25 de noviembre, el diagnóstico del VPS mostró un certificado final emitido por **OV R36**, vigente desde el 13 de noviembre de 2026, acompañado por un intermedio antiguo que no corresponde a su emisor. El bundle anterior solo contenía DV R36 y R46. Se agregó OV R36 conservando las autoridades anteriores para admitir servidores con ambas cadenas.

El certificado OV se obtuvo del [enlace oficial de Sectigo](http://crt.sectigo.com/SectigoPublicServerAuthenticationCAOVR36.crt), documentado en su [jerarquía oficial](https://www.sectigo.com/knowledge-base/detail/Sectigo-new-Public-Roots-and-Issuing-CAs-Hierarchy). Antes de incorporarlo se verificaron su nombre, condición de CA y firma criptográfica contra la raíz R46 existente, cuya huella permanece fijada en las pruebas. Huella SHA-256 de OV R36: `6542d176bed50f193c0ce297ae44ecd8a0a86bec2ede682769344059b4e78530`.

Después del Deploy, ejecutar `php /app/test/bcv-certificates.php` y `php /app/php/update-bcv.php --force` en el VPS. Mantener `NEXO_BCV_CA_FILE=/app/php/certificates/bcv-sectigo.pem` si se utiliza esa variable. No se desactiva la validación TLS y no se modifica la base de datos con esta actualización del bundle.
