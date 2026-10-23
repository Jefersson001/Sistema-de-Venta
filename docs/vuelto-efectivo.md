# Efectivo recibido y vuelto

En **Contabilidad → Configuración → Medios de pago**, el check **Permitir vuelto** habilita el efectivo recibido y el vuelto para cobros entrantes a una cuenta de tipo **Caja**. El método estándar Efectivo se habilita en la primera migración y en empresas nuevas; las siguientes instalaciones respetan los cambios del usuario. Cada empresa conserva sus métodos y movimientos propios.

Desde una factura o la ficha de Pagos, al seleccionar un método habilitado y una caja aparecen **Efectivo recibido** y **Vuelto entregado**. El importe neto del pago se calcula como recibido menos vuelto. En una factura se sugiere devolver el excedente del saldo pendiente; editar el vuelto permite dejarlo expresamente en cero. En la ficha independiente de Pagos el vuelto comienza en cero. Esta versión devuelve dinero en la misma moneda y caja del cobro; no permite vuelto desde bancos ni en pagos salientes.

Ejemplo: factura de 18 USD, recibido 20 USD y vuelto 2 USD. Se conserva un pago neto de 18 USD, que es lo disponible para aplicar a la factura. Al confirmar se registran dos movimientos físicos: +20 USD y −2 USD. Ambos se guardan en la misma transacción; si falla cualquiera, ninguno persiste. Con vuelto cero solo se registra la entrada. Si se recibe 20 USD y no se devuelve vuelto contra una factura de 18 USD, los 2 USD restantes quedan disponibles para otras facturas del contacto.

El proceso existente se conserva: guardar deja el pago en borrador, registrar lo deja en proceso y confirmar genera los movimientos. Los pagos pendientes no modifican la caja. Un pago registrado conserva sus importes aunque después se desactive el check del método. Revertir un pago con vuelto compensa las dos partidas originales juntas y retira las aplicaciones de las facturas. No se eliminan los movimientos originales.

Los informes de ingresos y egresos y el desglose por método usan el importe neto del cobro. El vuelto no aparece como gasto del negocio. Las tarjetas de cajas y el detalle de su informe muestran por separado efectivo recibido, vuelto entregado y sus reversiones; el historial identifica cada movimiento físico con su propio nombre. Los saldos de caja usan siempre los movimientos físicos completos.

## Datos y migración

`php/install-cash-change.php` instala una migración repetible. Antes de ejecutarlo en una instalación existente hay que respaldar la base; en esta instalación se hizo ese respaldo antes de migrar. Agrega:

- `accounting_payment_methods.allow_change`.
- `accounting_payments.received_amount`, `change_amount` y `change_enabled` (condición capturada al guardar).
- `accounting_treasury_entries.flow_amount` (proyección del cobro neto para informes) y `cash_role` (recibido, vuelto o reversión).

`amount` del pago sigue significando el importe neto, por lo que aplicaciones, saldos a favor, cuentas por cobrar y comisiones mantienen sus cálculos. Los movimientos antiguos conservan sus importes y utilizan el cálculo anterior cuando `flow_amount` es NULL. Los índices únicos permiten una partida por pago/rol y por documento/cuenta/rol, manteniendo la protección contra duplicados.

En importaciones, `amount` es neto; `received_amount` y `change_amount` permiten indicar el recibido y el vuelto. Si se proporciona recibido, el servidor vuelve a calcular el neto. La bandera interna no se puede imponer desde el archivo: se obtiene del método, la dirección y la cuenta. Los métodos pueden importar/exportar el check.

Validación: `test/cash-change.php`, `test/cash-change-ui.js`, `test/role-workflows.php`, `test/run-authenticated.php cash-flow treasury`, `test/transfer.php`, `test/multi-company.php` y `test/dashboard-ui.js`.
