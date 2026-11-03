# Certificados públicos para la consulta del BCV

`bcv-sectigo.pem` contiene únicamente las autoridades de certificación públicas de la cadena utilizada por `www.bcv.org.ve`, verificada el 3 de noviembre de 2026:

- **Sectigo Public Server Authentication CA DV R36**, válida hasta el 21 de marzo de 2036.
- **Sectigo Public Server Authentication Root R46**, válida hasta el 21 de marzo de 2046.

Se obtuvo la cadena mediante una conexión que verificó el nombre del servidor y su confianza con el almacén de Windows; se verifican además firmas y huellas en `test/bcv-certificates.php`. No contiene el certificado final del sitio, claves privadas, contraseñas ni tasas de cambio.

Sectigo documenta ambas autoridades en su [jerarquía oficial](https://support.sectigo.com/Com_KnowledgeDetailPage?Id=kA0Uj0000004IrB) y publica el [intermedio DV R36](http://crt.sectigo.com/SectigoPublicServerAuthenticationCADVR36.crt) y la [raíz R46](http://crt.sectigo.com/SectigoPublicServerAuthenticationRootR46.crt).

PHP/cURL utiliza este archivo solamente para la consulta HTTPS al BCV. Continúa verificando la firma, vigencia y nombre del servidor; no cambia el almacén global. Windows conserva su consulta nativa con Schannel salvo que se configure explícitamente `NEXO_BCV_CA_FILE`.

Si el BCV cambia de autoridad certificadora, revisar la nueva cadena contra una fuente confiable y actualizar el bundle y sus pruebas. Una renovación del certificado del sitio bajo estas mismas autoridades no requiere guardar el certificado final nuevo.
