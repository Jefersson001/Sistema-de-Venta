const fs=require('fs'),path=require('path');
const PptxGenJS=require('./node_modules/pptxgenjs');
const pptx=new PptxGenJS();pptx.layout='LAYOUT_WIDE';pptx.author='Konta';pptx.subject='Presentación comercial del sistema de gestión empresarial';pptx.title='Konta | Tu negocio conectado';pptx.company='Konta';pptx.lang='es-VE';pptx.theme={headFontFace:'Arial',bodyFontFace:'Arial',lang:'es-VE'};
const W=13.333333,H=7.5,S=pptx.ShapeType;
const C={ink:'142338',muted:'617389',green:'00AD78',deep:'008763',pale:'EAF9F3',bg:'F5F8FB',line:'DCE6EE',white:'FFFFFF',navy:'101E32',blue:'3478F6',amber:'D68B17',coral:'E05C76'};
const previews=[],out=path.resolve(__dirname,'../../docs/Presentacion_Comercial_Konta.pptx');let page;
const esc=s=>String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
function shape(type,x,y,w,h,fill,line='none',opts={}){
 const spec={x,y,w,h,fill:{color:fill},line:line==='none'?{color:fill,transparency:100}:{color:line,width:0.75},...opts};
 page.slide.addShape(type,spec);page.items.push({kind:'shape',type,x,y,w,h,fill,line,opts});
}
function box(x,y,w,h,fill=C.white,line=C.line){shape(S.roundRect,x,y,w,h,fill,line,{rectRadius:.13,radius:.13});}
function text(t,x,y,w,h,size=18,color=C.ink,bold=false,opts={}){
 page.slide.addText(t,{x,y,w,h,fontFace:'Arial',fontSize:size,color,bold,margin:0,vertAnchor:'top',valign:'top',breakLine:false,fit:'shrink',paraSpaceAfterPt:0,...opts});
 page.items.push({kind:'text',t,x,y,w,h,size,color,bold,opts});
}
function line(x1,y1,x2,y2,color=C.line,width=1){page.slide.addShape(S.line,{x:x1,y:y1,w:x2-x1,h:y2-y1,line:{color,width}});page.items.push({kind:'line',x:x1,y:y1,w:x2-x1,h:y2-y1,color,width});}
function pill(t,x,y,w,fill=C.pale,color=C.deep){box(x,y,w,.36,fill,fill);text(t,x+.12,y+.065,w-.24,.24,10,color,true,{align:'center'});}
function mark(x,y,dark=false,scale=1){box(x,y,.43*scale,.43*scale,C.green,C.green);text('K',x,y+.035*scale,.43*scale,.35*scale,20*scale,C.white,true,{align:'center'});text('Konta',x+.55*scale,y+.02*scale,1.3*scale,.4*scale,22*scale,dark?C.white:C.ink,true);shape(S.ellipse,x+1.75*scale,y+.105*scale,.075*scale,.075*scale,C.green);}
function start(kicker,title,subtitle='',dark=false){const slide=pptx.addSlide();slide.background={color:dark?C.navy:C.bg};page={slide,items:[],dark};previews.push(page);if(kicker)text(kicker.toUpperCase(),.62,.42,11,.25,10,dark?'6CE5BB':C.deep,true,{charSpacing:2});if(title)text(title,.62,.94,12.05,.83,30,dark?C.white:C.ink,true);if(subtitle)text(subtitle,.64,1.85,11.9,.57,15,dark?'BCD1D6':C.muted);return slide;}
function footer(){line(.62,7.04,12.72,7.04,page.dark?'2C3B4C':C.line,.6);text('KONTA  /  GESTIÓN EMPRESARIAL',.64,7.16,8,.2,8.5,page.dark?'A3BBC4':C.muted);text(String(previews.length).padStart(2,'0'),12.15,7.13,.55,.25,10,page.dark?'A3BBC4':C.muted,false,{align:'right'});}
function note(t){page.slide.addNotes(t);}
function bullets(items,x,y,w,size=17,gap=.58,color=C.ink){items.forEach((t,i)=>{shape(S.ellipse,x,y+i*gap+.09,.075,.075,C.green);text(t,x+.22,y+i*gap,w-.22,gap-.08,size,color);});}
function moduleLabel(t,x,y,w=3){text(t.toUpperCase(),x,y,w,.25,10,C.deep,true,{charSpacing:1.4});}
function benefit(t,x,y,w){box(x,y,w,.55,C.pale,C.pale);text(t,x+.16,y+.13,w-.32,.32,13,C.deep,true);}
function image(file,x,y,w,h,crop=false){page.slide.addImage({path:file,x,y,w,h,...(crop?{sizing:{type:'cover',w,h}}:{})});const extension=path.extname(file).slice(1);page.items.push({kind:'image',x,y,w,h,crop,data:'data:image/'+extension+';base64,'+fs.readFileSync(file).toString('base64')});}
function number(n,x,y){shape(S.ellipse,x,y,.42,.42,C.green);text(String(n),x,y+.075,.42,.3,14,C.white,true,{align:'center'});}

// 01 — Introducción.
start('', '', '',true);mark(.65,.6,true,1.24);
pill('GESTIÓN PARA EMPRESAS EN VENEZUELA',.65,1.48,4.6,'223D43','84F0C7');
text('Tu negocio\nconectado.',.65,2.12,5.55,1.7,43,C.white,true);
text('Vende, controla tu inventario y cobra\ncon toda la operación en un solo sistema.',.68,4.03,5.45,1.04,19,'C9D9E4');
text('Desde una tienda hasta una empresa\ncon varios equipos y almacenes.',.68,5.4,5.25,.8,15,'A3BBC4');
box(6.45,1.48,6.22,4.97,'F8FBFA','2D4652');
image(path.join(__dirname,'home-demo.png'),6.58,1.68,5.96,4.02);
text('Computadora + teléfono  ·  USD + bolívares',6.76,5.93,5.55,.3,14,C.deep,true,{align:'center'});
note('Presentación comercial resumida. Marca actual: Konta. La captura pertenece al inicio real del sistema, en una base aislada y con identidad de demostración. No se prometen resultados económicos cuantificados. Público: empresas que comercializan productos, incluyendo tiendas, panaderías, distribuidores y restaurantes.');footer();

// 02 — Necesidad.
start('La necesidad','Vender es solo una parte del negocio.','El reto está en mantener conectados los productos, las personas y el dinero.');
const pains=[['01','Información dispersa','Clientes, pedidos y documentos\nen varios lugares.'],['02','Inventario poco claro','Dudas sobre existencias,\nvariantes y disponibilidad.'],['03','Cobros pendientes','Facturas, abonos y vencimientos\ndifíciles de seguir.'],['04','Tasas que cambian','Operaciones en dólares y\npagos en bolívares en días distintos.']];
pains.forEach(([n,t,b],i)=>{const x=.62+i*3.08;box(x,2.65,2.88,2.75);text(n,x+.2,2.88,1,.46,26,C.green,true);text(t,x+.2,3.52,2.48,.65,19,C.ink,true);text(b,x+.2,4.34,2.48,.68,14,C.muted);});
box(.62,5.83,12.1,.75,C.navy,C.navy);text('Konta reúne la operación para trabajar con una misma información.',.9,6.04,11.55,.4,21,C.white,true,{align:'center'});
note('Abrir la conversación con necesidades reconocibles. No se afirma que todos los clientes tengan estos problemas ni se inventan porcentajes de ahorro. La conexión entre documentos y módulos sí existe en el sistema.');footer();

// 03 — Solución integrada y panorama de módulos.
start('La solución','Una operación conectada de principio a fin.','Cada paso conserva su relación con los documentos y movimientos del negocio.');
const stages=[['Contactos','Clientes y proveedores'],['Compras','Órdenes y recepciones'],['Inventario','Existencias y movimientos'],['Ventas','Órdenes y entregas'],['Contabilidad','Facturas, pagos y saldos']];
stages.forEach(([t,b],i)=>{const x=.62+i*2.47;box(x,2.74,2.23,1.7,i===2?C.pale:C.white,i===2?'ABE3CF':C.line);number(i+1,x+.17,2.92);text(t,x+.17,3.48,1.94,.33,17,C.ink,true);text(b,x+.17,3.91,1.94,.37,11.5,C.muted);if(i<4)text('→',x+2.23,3.35,.24,.42,17,C.green,true,{align:'center'});});
moduleLabel('Flujos especializados',.66,4.88,4);
[['Ventas Directas','Venta ágil y vendedores en calle'],['Compras Directas','Compra y recepción en un paso'],['Restaurante','Mesas, pedidos y cobros']].forEach(([t,b],i)=>{const x=.62+i*4.1;box(x,5.3,3.9,.94);text(t,x+.18,5.46,3.5,.29,15,C.ink,true);text(b,x+.18,5.87,3.5,.23,11.5,C.muted);});
text('Dashboard para supervisar  ·  Ajustes para configurar empresas, usuarios y permisos',.66,6.52,11.9,.3,14,C.deep,true);
note('Los diez módulos visibles en php/config/modules.php son Dashboard, Contactos, Inventario, Compras, Ventas, Contabilidad, Restaurante, Ventas Directas, Compras Directas y Ajustes. Los flujos rápidos generan documentos nativos; no son registros aislados.');footer();

// 04 — Dashboard y Contactos.
start('Dashboard + Contactos','Decide con contexto. Atiende con información.','Una vista para supervisar el negocio y una ficha para conocer a cada contacto.');
box(.62,2.59,5.93,3.95);moduleLabel('Dashboard',.88,2.87);text('Lo importante, a la vista.',.88,3.32,5.35,.5,23,C.ink,true);
bullets(['Resumen de ventas, compras e ingresos.','Cuentas por cobrar, por pagar y comisiones.','Alertas de inventario y tareas pendientes.'],.9,4.08,5.22,16,.57);
benefit('Prioriza lo que necesita atención.',.87,5.72,5.43);
box(6.79,2.59,5.93,3.95);moduleLabel('Contactos',7.05,2.87);text('Clientes y proveedores, organizados.',7.05,3.32,5.35,.65,20,C.ink,true);
bullets(['Datos fiscales, teléfono y correo.','Direcciones, ubicación y geolocalización.','Fotos, documentos y datos relacionados.'],7.07,4.08,5.22,16,.57);
benefit('Encuentra los datos cuando los necesitas.',7.04,5.72,5.43);
note('Dashboard: indicadores, cuentas pendientes, inventario y comisiones, con permisos. Contactos: ficha, múltiples direcciones, documentos adjuntos y geolocalización opcional. No se presenta como CRM con embudos, campañas automáticas o seguimiento comercial avanzado.');footer();

// 05 — Inventario.
start('Inventario','Conoce qué tienes, dónde está y qué puedes vender.','Un catálogo que contempla diferencias reales entre los productos.');
bullets(['Productos y variantes: talla, color o presentación.','Cantidades a la mano, reservadas y disponibles.','Almacenes, ubicaciones y transferencias internas.','Seguimiento por lotes o series y vencimiento opcional.'],.68,2.84,6.1,18,.73);
benefit('Elige la variante concreta al comprar o vender.',.64,6.05,6.02);
box(7.08,2.6,5.62,3.96);moduleLabel('Ejemplo de catálogo',7.35,2.88,4);
text('Camisa básica',7.35,3.32,4.9,.48,25,C.ink,true);text('Un producto principal. Varias opciones de venta.',7.35,3.99,4.87,.55,14,C.muted);
[['Azul / M','Talla y color'],['Azul / L','Talla y color'],['Blanco / M','Talla y color']].forEach(([t,b],i)=>{const y=4.78+i*.47;shape(S.ellipse,7.38,y+.05,.16,.16,i===2?'E1E8EE':C.blue);text(t,7.73,y,2.35,.28,15,C.ink,true);text(b,10.16,y+.025,2.17,.25,11.5,C.muted);});
note('Inventario: variantes concretas son operables; el producto principal sirve para agruparlas. Existencias por ubicación, reservas y movimientos. Modalidades alternativas de seguimiento por lote o número de serie, con vencimientos opcionales. No se promete fabricación, recetas, escaneo en ventas directas ni una solución de producción alimentaria.');footer();

// 06 — Ventas y Compras.
start('Ventas + Compras','Cada operación conserva su historia.','Gestiona el proceso completo cuando necesitas revisar y controlar cada etapa.');
[['Ventas',['Orden','Entrega','Factura'],'Cliente, vendedor, productos y plazo de pago.','Entregas parciales, devoluciones y documentos relacionados.'],['Compras',['Orden','Recepción','Factura'],'Proveedor, productos, destino y condiciones.','Recepciones y facturas conectadas con el inventario.']].forEach(([name,flow,a,b],i)=>{
 const x=.62+i*6.17;box(x,2.62,5.93,3.33);moduleLabel(name,x+.25,2.9);
 flow.forEach((t,j)=>{const px=x+.25+j*1.85;box(px,3.48,1.63,.64,C.pale,C.pale);text(t,px+.07,3.68,1.49,.27,14,C.deep,true,{align:'center'});if(j<2)text('→',px+1.63,3.65,.22,.3,16,C.green,true,{align:'center'});});
 text(a,x+.25,4.42,5.4,.5,16,C.ink,true);text(b,x+.25,5.08,5.4,.54,14,C.muted);
});
benefit('Comisiones por vendedor: consulta lo generado y registra pagos generales o parciales.',.62,6.21,12.1);
note('Ventas y Compras generan y relacionan documentos nativos. La factura puede originarse desde orden o movimiento según el flujo y la política del producto. Comisiones: pago acumulado por vendedor, antes o después del cobro al cliente, con límite neto generado menos pagos, distribución por factura y egreso de tesorería. No se paga por encima de lo generado.');footer();

// 07 — Flujos directos.
start('Ventas Directas + Compras Directas','Más agilidad para vender y reponer.','Dos accesos rápidos, conectados con los mismos módulos de la operación.');
box(.62,2.61,5.93,3.94);moduleLabel('Ventas Directas',.9,2.91);text('Cliente + carrito + confirmar.',.9,3.4,5.3,.52,23,C.ink,true);
text('Genera orden, entrega y factura en un solo flujo.',.9,4.12,5.25,.67,17,C.muted);
bullets(['Registra abonos y adjunta comprobantes.','Solicita mercancía para el vendedor.'],.92,5.04,5.13,16,.55);
box(6.79,2.61,5.93,3.94);moduleLabel('Compras Directas',7.07,2.91);text('Proveedor + productos + recibir.',7.07,3.4,5.3,.7,23,C.ink,true);
text('Genera orden, recepción y factura del proveedor.',7.07,4.12,5.25,.67,17,C.muted);
bullets(['Elige el almacén donde recibes.','Consulta el historial y los documentos.'],7.09,5.04,5.13,16,.55);
text('Diseñados para operar desde el teléfono o la computadora.',.66,6.65,11.9,.27,14,C.deep,true,{align:'center'});
note('Ventas Directas confirma orden, entrega y factura; no confirma dinero automáticamente. Los abonos quedan pendientes de validación según permisos. Solicitar mercancía requiere aprobación y entrega física antes de generar la transferencia. Compras Directas se confirma cuando la mercancía fue recibida; la factura queda pendiente de pago y los pagos se gestionan en Contabilidad. No se promete operación sin conexión.');footer();

// 08 — Control financiero.
start('Contabilidad','Sigue el dinero, desde la factura hasta el pago.','Control operativo de cobros, pagos y tesorería en cada moneda.');
const moneyCards=[['Cobrar y pagar','Facturas, vencimientos, abonos\ny saldos pendientes.'],['Cajas y bancos','Ingresos, egresos, transferencias\ny movimientos confirmados.'],['Documentar','Notas de crédito, comprobantes\ny documentos relacionados.']];
moneyCards.forEach(([t,b],i)=>{const x=.62+i*4.1;box(x,2.6,3.9,1.7);text(t,x+.22,2.91,3.46,.35,21,C.ink,true);text(b,x+.22,3.49,3.46,.56,15,C.muted);});
box(.62,4.63,12.1,1.98,C.navy,C.navy);text('Cobrar en otra fecha no debe desordenar la deuda.',.92,4.95,11.44,.57,24,C.white,true);
text('Cada abono conserva su moneda y su tasa. El sistema aplica el pago al saldo pendiente\ny registra el diferencial cambiario que corresponda.',.94,5.73,11.4,.6,17,'C4D9DE');
note('La presentación usa la etiqueta comercial de control financiero operativo. No se afirma que el módulo implemente contabilidad de doble partida completa, declaraciones fiscales o libros legales. Pagos en USD y VES, pagos parciales, cuentas, referencias y adjuntos están implementados. La fecha efectiva del pago es independiente de su fecha de registro y permite consultar la tasa histórica. Los pagos pendientes no equivalen a dinero confirmado.');footer();

// 09 — Restaurante.
start('Restaurante','Del pedido en la mesa al cobro de la cuenta.','Una experiencia de atención integrada con productos, inventario y facturación.');
image(path.resolve(__dirname,'../final-design-review/03-1440-top.png'),.65,2.67,7.25,3.95,true);
box(8.17,2.62,4.55,3.97);bullets(['Áreas, mesas y estado de atención.','Productos y observaciones del pedido.','División por persona, importe o productos.','Varios medios de pago y comprobante.'],8.42,2.98,4.0,16,.71);
note('Captura real de demostración del mapa de mesas. La imagen se utiliza por la UI de mesas, que conserva este comportamiento. Funciones verificadas: carta, pedido, cuentas, mesas, división de cuenta y cobro combinado. No se presentan comandas de cocina, impresión remota de cocina, recetas, producción o comandas automáticas como funciones implementadas.');footer();

// 10 — Ajustes y operación multiempresa.
start('Ajustes','Cada empresa configurada. Cada equipo con su acceso.','Organiza la operación sin compartir más información de la necesaria.');
box(.62,2.62,5.93,3.96);moduleLabel('Empresas y usuarios',.9,2.91);
text('Una empresa o varias,\ncon permisos por función.',.9,3.39,5.25,.94,25,C.ink,true);
['Administración','Vendedores','Compras','Caja y supervisión'].forEach((t,i)=>pill(t,.94+(i%2)*2.63,4.78+Math.floor(i/2)*.56,2.35));
text('Acceso a empresas autorizadas y registros permitidos.',.94,6.05,5.07,.3,12,C.muted);
box(6.79,2.62,5.93,3.96);moduleLabel('Configuración y comunicación',7.07,2.91);
bullets(['Datos de empresa, monedas y términos de pago.','Usuarios y grupos de acceso por módulo.','Correo y mensajes configurables por empresa.','Documentos PDF, tickets y enlaces por WhatsApp.'],7.09,3.51,5.12,16,.65);
note('Multiempresa: selección y autorización de empresas, aislamiento de datos y permisos por recurso/acción. Configuración por empresa para correo y WhatsApp. Correo requiere SMTP; WhatsApp abre el chat para completar el envío y comparte archivos mediante enlaces temporales públicos configurados. No se promete envío automático confirmado por WhatsApp Business API ni integraciones bancarias automáticas. PDF y ticket corresponden a los formatos del sistema; no se afirma homologación fiscal.');footer();

// 11 — Venezuela y sectores.
start('Pensado para la operación en Venezuela','Tu sector cambia. El control se mantiene.','Una misma base para tiendas, zapaterías, panaderías, distribuidores y restaurantes.');
[['USD + Bs.','Opera con dólares y bolívares.'],['BCV + tasa manual','Consulta e historial de tasas por fecha.'],['Abonos y plazos','Contado o crédito con vencimiento.'],['Uso desde el móvil','Vendedores y equipos en movimiento.']].forEach(([t,b],i)=>{const x=.62+(i%2)*6.17,y=2.64+Math.floor(i/2)*1.32;box(x,y,5.93,1.12);text(t,x+.23,y+.19,5.4,.36,21,C.ink,true);text(b,x+.23,y+.69,5.4,.26,14,C.muted);});
box(.62,5.66,12.1,.75,C.pale,C.pale);text('Configura tus productos y tus procesos, sin perder una visión común del negocio.',.91,5.88,11.53,.4,19,C.deep,true,{align:'center'});
text('Acceso web desde navegador. Se necesita conexión para confirmar operaciones.',.66,6.66,11.95,.23,11,C.muted,false,{align:'center'});
note('La actualización BCV depende de la tarea programada y de disponibilidad de la fuente oficial; existe tasa manual e historial. No se promete actualización de una factura ya registrada con la tasa nueva. El sistema se adapta a la comercialización de productos en estos sectores; no sustituye aplicaciones sectoriales especializadas en producción, nómina o cumplimiento fiscal. La operación rápida exige conexión al confirmar.');footer();

// 12 — Propuesta de precios.
start('Propuesta comercial sugerida','Un plan según tus empresas y tu equipo.','Todos los módulos. Diferentes capacidades de acceso y acompañamiento inicial.');
const plans=[{name:'Esencial',price:29,companies:'1 empresa',users:'Hasta 3 usuarios',setup:99,tag:'Para comenzar',featured:false},{name:'Crecimiento',price:59,companies:'Hasta 3 empresas',users:'Hasta 10 usuarios',setup:199,tag:'Para coordinar equipos',featured:true},{name:'Empresarial',price:99,companies:'Hasta 5 empresas',users:'Hasta 25 usuarios',setup:349,tag:'Para ampliar la operación',featured:false}];
plans.forEach((p,i)=>{const x=.62+i*4.1;box(x,2.47,3.9,3.77,p.featured?C.pale:C.white,p.featured?C.green:C.line);text(p.name,x+.25,2.75,3.4,.4,22,C.ink,true);text(p.tag,x+.25,3.29,3.4,.3,12,C.muted);text('US$ '+p.price,x+.25,3.87,3.38,.59,35,p.featured?C.deep:C.ink,true);text('por mes',x+.27,4.53,3.35,.27,13,C.muted);line(x+.25,5.01,x+3.65,5.01,p.featured?'BBE8D8':C.line);text(p.companies+'\n'+p.users,x+.25,5.22,3.39,.67,17,C.ink,true);text('Puesta en marcha única: US$ '+p.setup,x+.25,6.0,3.38,.25,12,C.muted);});
text('Usuarios totales por organización. Las empresas comparten ese cupo.\nAdicionales sugeridos: US$ 5 por usuario/mes · US$ 15 por empresa/mes.\nMontos de referencia en USD; condiciones e impuestos aplicables se detallan en la cotización.',.66,6.43,11.9,.56,10.5,C.muted);
note('ESTA ES UNA PROPUESTA DE PRECIOS, NO UNA TARIFA VIGENTE APROBADA. Modelo sugerido: suscripción web por organización. Esencial 1 empresa/3 usuarios USD29 al mes, alta USD99; Crecimiento hasta 3 empresas/10 usuarios USD59 al mes, alta USD199; Empresarial hasta 5 empresas/25 usuarios USD99 al mes, alta USD349. Los usuarios son cuentas activas habilitadas en toda la organización, no por empresa. Empresas son entidades separadas, no almacenes. Adicionales propuestos USD5/usuario y USD15/empresa al mes; sobre 5 empresas o 25 usuarios se recomienda cotización por volumen. Se propone incluir acceso web alojado, mantenimiento/actualizaciones y soporte funcional remoto básico en horario laboral; dimensionar recursos y límites de almacenamiento antes de contratar. No implica que el sistema tenga cobro automático ni cuotas automáticas implementadas. Puesta en marcha cubre configuración inicial y capacitación básica; migraciones masivas, desarrollos a medida y soporte ampliado deben presupuestarse. Montos en USD de referencia; impuestos, medio de pago y condiciones se establecen en la cotización. Referencias de mercado consultadas el 26/11/2026, no usadas como equivalencia funcional ni afirmación de superioridad: Fina publica USD35/mes + IVA, https://www.finapartner.com/precios/ ; ERP-EasyPlus publica una oferta mensual con 6 usuarios, https://www.erp-easyplus.com/ . Sus alcances, promociones y condiciones pueden diferir. Los precios de Konta son una estimación comercial propia para una solución en desarrollo con múltiples empresas y usuarios, no una tasación del código fuente.');footer();

// 13 — Implantación y cierre.
start('Comienza con tu operación real','Menos procesos dispersos. Más control para crecer.','Una demostración con tus productos y tu equipo es el mejor punto de partida.',true);
[['1','Conocemos tu negocio','Sector, empresas, usuarios\ny flujo de trabajo.'],['2','Configuramos','Empresa, catálogo inicial,\nalmacenes y permisos.'],['3','Capacitamos','Acompañamiento inicial\npara cada función.'],['4','Comienzas a operar','Ventas, compras, inventario\ny control de pagos.']].forEach(([n,t,b],i)=>{const x=.62+i*3.08;box(x,2.72,2.88,2.31,'1C3045','2C4255');text(n,x+.22,2.96,1,.45,28,'6CE5BB',true);text(t,x+.22,3.6,2.44,.6,19,C.white,true);text(b,x+.22,4.28,2.44,.55,13,'BED1DC');});
box(.62,5.49,12.1,.88,C.green,C.green);text('Agenda una demostración de Konta para tu empresa.',.95,5.76,11.44,.45,25,C.white,true,{align:'center'});
text('Oferta sugerida: acceso web, actualizaciones y soporte remoto básico.\nMigraciones masivas, desarrollos especiales e impuestos aplicables se detallan en la cotización.',.67,6.57,11.9,.38,10.5,'A9C4CE',false,{align:'center'});
note('Cierre comercial sin inventar teléfonos, correo o dominio de la marca. El usuario no proporcionó datos comerciales de contacto. Antes de publicar o contratar: aprobar precios, alojamiento administrado, horarios de soporte, alcance de carga de datos, impuestos y condiciones. No se promete fecha fija de implementación. La recomendación es vender una suscripción por uso y servicio, separando la puesta en marcha del precio mensual.');footer();

// Preview exact geometry in HTML; delivery is only the editable PowerPoint.
const px=v=>+(v*120).toFixed(2);
function htmlItem(a){
 const base=`position:absolute;left:${px(a.x)}px;top:${px(a.y)}px;width:${px(a.w)}px;height:${px(a.h)}px;`;
 if(a.kind==='text')return `<div class="text" data-box="${esc(a.t.slice(0,38))}" style="${base}font: ${a.bold?'700':'400'} ${a.size*120/72}px Arial,sans-serif;line-height:1.12;color:#${a.color};text-align:${a.opts.align||'left'};white-space:pre-line;">${esc(a.t)}</div>`;
 if(a.kind==='image')return `<img style="${base}${a.crop?'object-fit:cover;':''}" src="${a.data}">`;
 if(a.kind==='line')return `<div style="${base}height:${a.width}px;background:#${a.color}"></div>`;
 return `<div style="${base}background:#${a.fill};border:${a.line==='none'?'0':'1px solid #'+a.line};border-radius:${a.type===S.ellipse?'50%':a.type===S.roundRect?'15px':'0'}"></div>`;
}
fs.mkdirSync(path.join(__dirname,'preview'),{recursive:true});
previews.forEach((p,i)=>fs.writeFileSync(path.join(__dirname,'preview',`slide-${String(i+1).padStart(2,'0')}.html`),`<!doctype html><meta charset="utf-8"><style>*{box-sizing:border-box}body{margin:0;background:#${p.dark?C.navy:C.bg}}.slide{position:relative;width:1600px;height:900px;overflow:hidden}.text{overflow:visible}</style><div class="slide">${p.items.map(htmlItem).join('')}</div>`));
const bad=previews.flatMap((p,i)=>p.items.filter(a=>a.x<0||a.y<0||a.x+a.w>W+.015||a.y+a.h>H+.015).map(a=>({slide:i+1,kind:a.kind,text:a.t,x:a.x,y:a.y,w:a.w,h:a.h})));
if(bad.length)throw Error('Fuera de límites: '+JSON.stringify(bad));
fs.writeFileSync(path.join(__dirname,'slide-manifest.json'),JSON.stringify(previews.map((p,i)=>({slide:i+1,text:p.items.filter(a=>a.kind==='text').map(a=>a.t)})),null,2));
pptx.writeFile({fileName:out,compression:true}).then(()=>console.log('PowerPoint: '+out+' | '+previews.length+' diapositivas editables.')).catch(e=>{console.error(e);process.exitCode=1});
