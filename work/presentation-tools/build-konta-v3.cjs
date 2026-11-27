const fs=require('fs'),path=require('path');
const PptxGenJS=require('./node_modules/pptxgenjs');
const pptx=new PptxGenJS();pptx.layout='LAYOUT_WIDE';pptx.author='Konta';pptx.subject='Presentación comercial del sistema de gestión empresarial';pptx.title='Konta | El control marca la diferencia';pptx.company='Konta';pptx.lang='es-VE';pptx.theme={headFontFace:'Arial',bodyFontFace:'Arial',lang:'es-VE'};
const W=13.333333,H=7.5,S=pptx.ShapeType;
const C={ink:'142338',muted:'617389',green:'00AD78',deep:'008763',pale:'EAF9F3',bg:'F5F8FB',line:'DCE6EE',white:'FFFFFF',navy:'101E32',blue:'3478F6',amber:'D68B17',coral:'E05C76'};
const previews=[],out=path.resolve(__dirname,'../../docs/Konta_Presentacion_Comercial_3.0.pptx');let page;
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
function mark(x,y,dark=false,scale=1){box(x,y,.43*scale,.43*scale,C.green,C.green);text('K',x,y+.035*scale,.43*scale,.35*scale,20*scale,C.white,true,{align:'center'});text('Konta',x+.55*scale,y+.02*scale,1.3*scale,.4*scale,22*scale,dark?C.white:C.ink,true);shape(S.ellipse,x+1.48*scale,y+.105*scale,.075*scale,.075*scale,C.green);}
function start(kicker,title,subtitle='',dark=false){const slide=pptx.addSlide();slide.background={color:dark?C.navy:C.bg};page={slide,items:[],dark};previews.push(page);if(kicker)text(kicker.toUpperCase(),.62,.42,11,.25,10,dark?'6CE5BB':C.deep,true,{charSpacing:2});if(title)text(title,.62,.94,12.05,.83,30,dark?C.white:C.ink,true);if(subtitle)text(subtitle,.64,1.85,11.9,.57,15,dark?'BCD1D6':C.muted);return slide;}
function footer(){line(.62,7.04,12.72,7.04,page.dark?'2C3B4C':C.line,.6);text('KONTA  /  GESTIÓN EMPRESARIAL',.64,7.16,8,.2,8.5,page.dark?'A3BBC4':C.muted);text(String(previews.length).padStart(2,'0'),12.15,7.13,.55,.25,10,page.dark?'A3BBC4':C.muted,false,{align:'right'});}
function note(t){page.slide.addNotes(t);}
function bullets(items,x,y,w,size=17,gap=.58,color=C.ink){items.forEach((t,i)=>{shape(S.ellipse,x,y+i*gap+.09,.075,.075,C.green);text(t,x+.22,y+i*gap,w-.22,gap-.08,size,color);});}
function moduleLabel(t,x,y,w=3){text(t.toUpperCase(),x,y,w,.25,10,C.deep,true,{charSpacing:1.4});}
function benefit(t,x,y,w){box(x,y,w,.55,C.pale,C.pale);text(t,x+.16,y+.13,w-.32,.32,13,C.deep,true);}
function image(file,x,y,w,h,crop=false){page.slide.addImage({path:file,x,y,w,h,...(crop?{sizing:{type:'cover',w,h}}:{})});const extension=path.extname(file).slice(1);page.items.push({kind:'image',x,y,w,h,crop,data:'data:image/'+extension+';base64,'+fs.readFileSync(file).toString('base64')});}
function number(n,x,y){shape(S.ellipse,x,y,.42,.42,C.green);text(String(n),x,y+.075,.42,.3,14,C.white,true,{align:'center'});}

// Commercial 3.0: source material supplied by the owner, with implemented scope retained.
const lime='C3F58C',night='102638',soft='EAF3F0',sources='Fuentes de contenido: Konta_Comercial_2.0.pptx y Konta_Evaluacion_Interna.pptx, aportados por el propietario; alcance contrastado con el código y la presentación previa del sistema.';
function open(kicker,dark=false){start('', '', '',dark);shape(S.rect,.62,.47,.37,.055,C.green);text(kicker.toUpperCase(),1.12,.4,10,.25,10,dark?'92DDC2':C.deep,true,{charSpacing:1.2});}
function head(title,sub='',dark=page.dark){text(title,.65,1.04,12.02,1.3,35,dark?C.white:C.ink,true);if(sub)text(sub,.68,2.32,11.8,.54,16,dark?'B8CDD6':C.muted);}
const speakerNotes=[
 'Konta conecta ventas, compras, inventario y control financiero operativo. Comenzar preguntando cómo sigue el cliente una venta después de confirmarla. La pantalla corresponde al sistema con datos de demostración. Precio desde USD30/mes, con alta única según el plan; ver el detalle de la oferta.',
 'Preguntar: ¿dónde registras el pedido, cómo confirmas la salida de mercancía y dónde revisas lo pendiente por cobrar? Escuchar el proceso del cliente antes de presentar la solución. No cuantificar ahorros sin medir su operación.',
 'Recorrer los diez módulos. Los procesos normales y los accesos directos usan documentos relacionados. En la aplicación, el control de tesorería está dentro de Contabilidad. Dashboard supervisa; Ajustes organiza empresas y accesos.',
 'Ejemplo ilustrativo: el catálogo agrupa un producto y sus variantes concretas de venta. Los 32 artículos del ejemplo se distribuyen entre variantes y ubicaciones. Explicar existencias, reservas, transferencias y seguimiento opcional por lote o serie. Adaptar la demostración al producto real del prospecto.',
 'Compras: orden, recepción y factura del proveedor. Ventas: orden, entrega y factura del cliente. Mostrar cómo se consulta un documento relacionado. Las comisiones acumulan lo generado y descuentan lo pagado; los pagos pueden ser generales o parciales sin superar el saldo neto disponible.',
 'Representación ilustrativa del flujo móvil. Al confirmar una venta directa se generan orden, entrega y factura. El cobro se registra por separado y está sujeto a validación según permisos. Las solicitudes de mercancía requieren aprobación y entrega física. La confirmación de operaciones requiere conexión.',
 'Compras Directas agiliza una compra cuando la mercancía se recibe: genera los documentos relacionados, dejando el pago para el flujo financiero. Contactos reúne clientes, proveedores, teléfonos, correos, direcciones y adjuntos. Mostrar cómo estos datos se reutilizan en la operación.',
 'Ejemplo ilustrativo: factura por USD100, cobro confirmado de USD40 y saldo de USD60. Diferenciar dinero confirmado de pagos todavía en revisión. Presentar cajas, bancos, cuentas pendientes, comprobantes y notas de crédito como control financiero operativo.',
 'Ejemplo de una factura en USD: tasa original 40 VES/USD; abono de USD40 a 42 VES/USD; se reciben Bs1680. Valor histórico de ese abono Bs1600 y diferencial Bs80. Deuda restante USD60. Son cifras ilustrativas, no tasas BCV actuales. Cada abono conserva su fecha y tasa. Existe historial BCV y alternativa manual.',
 'El mapa es ilustrativo. Mostrar el recorrido mesa, productos, observaciones y cobro. La cuenta puede dividirse por persona, importe o productos y pagarse con varios medios. El comprobante y la factura quedan relacionados con la operación.',
 'Consultar la organización del cliente: quién vende, compra, cobra y supervisa. Configurar los permisos de cada función y las empresas autorizadas. Multiempresa corresponde a entidades de una organización; no equivale a varios almacenes. Dashboard concentra alertas y pendientes.',
 'Conectar el sistema con el sector del prospecto y escoger un recorrido de demostración. Tiendas: variantes y disponibilidad. Distribución: venta móvil, mercancía y comisiones. Restaurantes: mesa, pedido y cobro. Definir cualquier necesidad especializada en la cotización.',
 'Tarifas propuestas: Esencial USD30/mes, alta USD180, 1 empresa/3 usuarios/2 horas de capacitación; Crecimiento USD55, alta USD300, 2 empresas/6 usuarios/4 horas; Empresarial USD95, alta USD500, 3 empresas/12 usuarios/8 horas; Corporativo desde USD150, alta desde USD850, hasta5 empresas/25 usuarios/12 horas. Usuarios totales de la organización, no por empresa. Aprobación, impuestos y dimensionamiento se acuerdan en la cotización. Los almacenes a configurar inicialmente se proponen según plan:1,3,6 o a medida.',
 'Explicar las cuatro etapas de implementación. El servicio propuesto incluye alojamiento administrado, actualizaciones generales, respaldos programados y soporte remoto según condiciones contratadas. Servidor dedicado, migraciones, desarrollos, horarios de soporte y almacenamiento se dimensionan en la cotización. Adicionales sugeridos: usuario USD5/mes, empresa USD15/mes y capacitación USD20/h. Alta50% al contratar y50% antes de producción; mensualidad anticipada. Documentos operativos; no se afirma homologación fiscal ni contabilidad fiscal integral.',
 'Cerrar con una demostración personalizada: qué vende el cliente, cuántos usuarios necesita y cuántas empresas desea manejar. Solicitar estos datos y preparar una cotización con alcance y condiciones claros.'
];
function finish(notes){note(speakerNotes[previews.length-1]);footer();}
function dot(t,x,y,w,size=17,color=C.ink){shape(S.ellipse,x,y+.09,.07,.07,C.green);text(t,x+.2,y,w-.2,.56,size,color);}
function smallLabel(t,x,y,w=4,color=C.deep){text(t.toUpperCase(),x,y,w,.24,10,color,true,{charSpacing:1});}
function rowMetric(label,value,x,y,w=4.7,color=C.ink){text(label,x,y,w*.65,.31,14,C.muted);text(value,x+w*.65,y,w*.35,.33,16,color,true,{align:'right'});}
function chevron(x,y,color=C.green){text('→',x,y,.35,.4,20,color,true,{align:'center'});}
function badge(t,x,y,w=1.3,fill=C.pale,color=C.deep){pill(t,x,y,w,fill,color);}
function phone(x,y,w,h){box(x,y,w,h,'203344','203344');box(x+.09,y+.1,w-.18,h-.2,C.white,C.white);box(x+w*.32,y+.1,w*.36,.1,'203344','203344');}
function glyph(kind,x,y,size=0.5,color=C.green){
 const d=size;const lw=1.6;
 if(kind==='chart'){
  line(x,y+d,x+d,y+d,color,lw);line(x,y+d,x,y,color,lw);
  [0.25,0.53,0.83].forEach((v,i)=>shape(S.rect,x+.1*d+i*.27*d,y+d-v*d,.16*d,v*d,color));
 }else if(kind==='box'){
  box(x,y,d,d,'E6F7F0',color);line(x,y+d*.3,x+d,y+d*.3,color,lw);line(x+d*.5,y+d*.3,x+d*.5,y+d,color,lw);
 }else if(kind==='phone'){
  box(x+.1*d,y,.7*d,d,'E6F7F0',color);line(x+.3*d,y+.78*d,x+.6*d,y+.78*d,color,lw);
 }else if(kind==='table'){
  box(x+d*.15,y+d*.25,d*.7,d*.5,'E6F7F0',color);line(x,y+d*.3,x,y+d*.7,color,lw);line(x+d,y+d*.3,x+d,y+d*.7,color,lw);
 }else if(kind==='people'){
  shape(S.ellipse,x+d*.34,y,d*.32,d*.32,color);box(x+d*.12,y+d*.45,d*.76,d*.45,'E6F7F0',color);
 }else{
  box(x,y+.12*d,d,.72*d,'E6F7F0',color);line(x,y+.36*d,x+d,y+.36*d,color,lw);shape(S.ellipse,x+.7*d,y+.55*d,d*.08,d*.08,color);
 }
}

// 01 / A question opens the sale; the product is revealed visually.
open('Gestión empresarial para Venezuela',true);mark(.7,.94,true,1.4);
text('Vender es fácil.\nTener el control\nmarca la diferencia.',.67,2.05,6.35,2.43,38,C.white,true);
text('Ventas, inventario, compras y dinero.\nConectados en un solo lugar.',.71,4.85,5.7,.87,20,'BED4DC');
badge('PLANES DESDE US$30 / MES',.71,6.13,3.3,'274339','C3F58C');
box(7.03,1.55,5.7,4.72,'203B4C','355568');image(path.join(__dirname,'home-demo.png'),7.18,1.77,5.39,3.64);
text('Tu operación, conectada.',7.46,5.69,4.86,.36,19,'C3F58C',true,{align:'center'});
finish('Abrir con la diferencia entre vender y controlar. Precio de entrada actualizado al material 2.0: USD30/mes más alta única según plan; la propuesta comercial se explica en la diapositiva 13. Captura real del sistema con identidad de demostración. No se cuantifican resultados ni ahorros.');

// 02 / Pain becomes a concrete question.
open('El reto diario');text('Una sola venta.\n¿Cuántas veces\nla registras?',.65,1.1,6.3,2.55,42,C.ink,true);
text('Cuando cada paso vive por separado,\nseguir el negocio exige más esfuerzo.',.7,4.1,5.5,.95,20,C.muted);
const fragments=[['Pedido','¿Qué pidió el cliente?',7.13,1.24],['Inventario','¿Se descontó la mercancía?',7.58,2.36],['Factura','¿Dónde está el documento?',7.13,3.48],['Cobro','¿Cuánto queda pendiente?',7.58,4.6]];
fragments.forEach(([t,b,x,y],i)=>{box(x,y,4.67,.88,C.white,C.line);text(String(i+1).padStart(2,'0'),x+.18,y+.17,.47,.4,20,C.green,true);text(t,x+.84,y+.12,3.55,.28,17,C.ink,true);text(b,x+.84,y+.5,3.55,.24,12.5,C.muted);});
box(.66,6.01,12.03,.65,C.pale,C.pale);text('La oportunidad: conectar el proceso y dejar de perseguir la información.',.93,6.19,11.49,.34,19,C.deep,true,{align:'center'});
finish('Conectar con una necesidad reconocible, sin afirmar que todos los prospectos trabajan así. El problema comercial es la información dispersa y el trabajo de seguimiento.');

// 03 / Product reveal, with all ten modules represented.
open('La solución',true);head('De operar por partes\na ver el negocio completo.','Diez módulos conectados. Una misma operación.',true);
const flow=[['Contactos','Clientes y proveedores','people'],['Compras','Orden y recepción','box'],['Inventario','Existencias y trazabilidad','box'],['Ventas','Pedido y entrega','chart'],['Contabilidad','Facturas y pagos','wallet']];
flow.forEach(([t,b,k],i)=>{const x=.66+i*2.47;box(x,3.18,2.22,1.67,'203B4C','385063');glyph(k,x+.17,3.39,.39,'56D9AA');text(t,x+.17,4.0,1.91,.3,16,C.white,true);text(b,x+.17,4.46,1.91,.23,10,'B7CDD4');if(i<4)chevron(x+2.2,3.85,'C3F58C');});
[['Ventas Directas',2.65],['Compras Directas',2.65],['Restaurante',2.15],['Dashboard',1.95],['Ajustes',1.65]].reduce((x,[t,w])=>{badge(t,x,5.46,w,'284A45','C3F58C');return x+w+.16;},.66);
text('Flujos rápidos para operar. Información conectada para supervisar.',.72,6.34,11.8,.38,19,'D3E6E9',false,{align:'center'});
finish('Corresponde a los diez módulos vigentes. En el material recibido se emplea Tesorería como nombre comercial del control financiero; en la aplicación ese conjunto se encuentra en Contabilidad. No se presenta una contabilidad fiscal integral.');

// 04 / Inventory as a visual catalogue, not a list of features.
open('Inventario');head('No vendas a ciegas.','Conoce qué tienes, dónde está y qué está disponible.');
smallLabel('Lo que puedes controlar',.72,3.23,5);
dot('Variantes por talla, color o presentación.',.73,3.72,5.57,19);
dot('Almacenes, ubicaciones y reservas.',.73,4.45,5.57,19);
dot('Transferencias y movimientos relacionados.',.73,5.18,5.57,19);
dot('Lotes o series y vencimiento opcional.',.73,5.91,5.57,19);
box(6.72,3.05,5.97,3.61,C.white,C.line);smallLabel('Ejemplo ilustrativo de variantes',7.0,3.31,5);
text('Zapato urbano',7.0,3.78,4.6,.46,26,C.ink,true);badge('32 UNIDADES',10.3,3.8,1.99);
[['Variante','Central','Tienda','Total'],['Negro / 38','12','4','16'],['Negro / 39','8','3','11'],['Blanco / 38','4','1','5']].forEach((r,i)=>{const y=4.52+i*.45;if(i===0)box(6.98,y-.06,5.43,.39,C.pale,C.pale);[0,2.56,3.55,4.55].forEach((dx,j)=>text(r[j],7.09+dx,y, j===0?2.45:.8,.26,i===0?11:14,i===0?C.deep:C.ink,i===0||j===3,{align:j===0?'left':'center'}));if(i>0&&i<3)line(7.05,y+.32,12.27,y+.32,C.line,.5);});
finish('Ejemplo de datos inventados únicamente para explicar variantes y existencias; no son cifras de clientes ni indicadores de desempeño. El producto principal agrupa variantes y las variantes concretas se compran y venden. Lotes y series son modalidades alternativas, con vencimientos opcionales. No prometer producción, recetas ni inventario offline.');

// 05 / Formal sales and purchases, plus commissions.
open('Ventas + Compras');head('La operación conserva su historia.','Documentos relacionados para seguir cada etapa y resolver lo pendiente.');
[['Compras','Proveedor',['Orden','Recepción','Factura']],['Ventas','Cliente',['Orden','Entrega','Factura']]].forEach(([t,who,steps],i)=>{const y=3.15+i*1.31;box(.66,y,12.03,1.08);text(t,.91,y+.23,2.55,.34,23,C.ink,true);text(who,.93,y+.69,2.5,.22,12,C.muted);steps.forEach((step,j)=>{const x=4.04+j*2.76;box(x,y+.24,2.23,.6,C.pale,C.pale);text(step,x+.1,y+.42,2.03,.3,17,C.deep,true,{align:'center'});if(j<2)chevron(x+2.28,y+.35);});});
box(.66,6.03,12.03,.69,C.navy,C.navy);text('Comisiones claras: lo generado, lo pagado y el saldo acumulado por vendedor.',.94,6.23,11.48,.33,18,C.white,true,{align:'center'});
finish('Ventas y Compras nativas: pedidos, entregas/recepciones y facturas relacionados; las entregas parciales y devoluciones conservan seguimiento. Comisiones por vendedor: anticipos y pagos parciales dentro del neto generado menos lo ya pagado, con salida en tesorería y distribución por factura. Las comisiones no equivalen a un pago de nómina.');

// 06 / The mobile flow, with an editable illustrative phone.
open('Ventas Directas',true);text('Tu mostrador\ntambién cabe\nen un teléfono.',.67,1.15,6.48,2.6,41,C.white,true);
text('Para quien vende en la calle\ny para quien necesita ir más rápido.',.72,4.2,5.95,.94,20,'BFD5DC');
[['Cliente + carrito + confirmar',5.44],['Abonos y comprobantes',5.98],['Solicitud de mercancía',6.52]].forEach(([t,y])=>{shape(S.ellipse,.77,y+.08,.07,.07,lime);text(t,1.0,y,5.4,.32,16,'DBE9ED');});
phone(8.03,1.17,3.31,5.56);smallLabel('Ventas Directas',8.32,1.57,2.65);text('Nueva venta',8.32,1.99,2.65,.38,20,C.ink,true);
box(8.27,2.55,2.81,.63,C.bg,C.line);text('Cliente seleccionado',8.43,2.74,2.49,.3,12,C.muted);
[['Producto A','1 × US$10'],['Producto B','2 × US$5']].forEach(([t,b],i)=>{const y=3.43+i*.79;box(8.28,y,2.79,.66,C.white,C.line);glyph('box',8.44,y+.15,.32);text(t,8.95,y+.12,1.95,.27,13,C.ink,true);text(b,8.95,y+.42,1.95,.19,10,C.muted);});
text('Total',8.36,5.15,1.1,.3,15,C.muted);text('US$20',9.45,5.13,1.52,.36,20,C.ink,true,{align:'right'});box(8.29,5.79,2.78,.52,C.green,C.green);text('Confirmar venta',8.42,5.96,2.52,.26,13,C.white,true,{align:'center'});
text('Flujo ilustrativo',8.28,6.8,2.82,.2,9,'9BBBC6',false,{align:'center'});
finish('El teléfono es una representación editable del flujo, no una captura literal. Confirmar genera orden, entrega y factura nativas. Un cobro se registra por separado y queda sujeto a validación. Las solicitudes necesitan aprobación y entrega física. Se requiere conexión al confirmar; no se ofrece venta offline. La existencia de un carrito no reserva stock.');

// 07 / Purchases Direct, complemented by the information needed to serve.
open('Compras Directas + Contactos');head('Repón con agilidad.\nAtiende con información.','Menos pasos para recibir mercancía; mejores datos para cada relación comercial.');
box(.66,3.09,7.42,3.5,C.white,C.line);glyph('box',.96,3.4,.47);smallLabel('Compras Directas',1.65,3.45,5);
text('Proveedor + productos + recepción.',.97,4.21,6.79,.75,26,C.ink,true);
text('Genera orden, recepción y factura del proveedor\nen un flujo conectado con el inventario.',.98,5.2,6.75,.84,18,C.muted);
box(8.32,3.09,4.37,3.5,C.pale,C.pale);smallLabel('Contactos',8.6,3.47,3.7);
['Teléfono y correo','Direcciones y ubicación','Fotos y documentos','Clientes y proveedores'].forEach((t,i)=>text(t,8.63,4.15+i*.51,3.72,.33,17,C.ink,i===0));
finish('Compras Directas se confirma cuando se recibe la mercancía: no confirma pagos y deja la factura pendiente de pago. Contactos centraliza datos y adjuntos, con direcciones y geolocalización opcional. No se promete un embudo CRM, campañas o automatización de marketing.');

// 08 / Finance, with an intentionally simple example.
open('Contabilidad y Tesorería');text('Vender no es lo mismo\nque haber cobrado.',.65,1.08,12.0,1.6,39,C.ink,true);
text('Controla las facturas, los abonos y el saldo que todavía falta por confirmar.',.7,2.77,11.8,.55,19,C.muted);
const metrics=[['Venta facturada','US$100',C.ink],['Cobro confirmado','US$40',C.green],['Saldo pendiente','US$60',C.coral]];
metrics.forEach(([t,v,color],i)=>{const x=.66+i*4.1;box(x,3.73,3.83,1.65);smallLabel(t,x+.22,4.0,3.4,C.muted);text(v,x+.22,4.48,3.4,.64,36,color,true);});
text('Ejemplo ilustrativo',.73,5.54,11.8,.23,10,C.muted);
[['Cuentas por cobrar y pagar',.7],['Cajas, bancos y transferencias',4.8],['Comprobantes y notas de crédito',8.9]].forEach(([t,x])=>text(t,x,6.13,3.7,.57,17,C.ink,true));
finish('Cifras de ejemplo, no métricas reales. Los cobros en revisión se muestran por separado y no descuentan la deuda confirmada hasta su validación. Módulo Contabilidad: control financiero operativo, tesorería y documentos; no se promete contabilidad fiscal completa ni libros legales.');

// 09 / Exchange-rate value made tangible.
open('USD + bolívares',true);head('Cada fecha tiene su tasa.\nCada abono conserva su valor.','Historial BCV, tasa manual y seguimiento del diferencial cambiario.',true);
box(.68,3.22,4.39,2.56,'203B4C','365165');smallLabel('Día de la factura',.96,3.54,3.8,'9DCEBF');text('US$100',.96,4.07,3.8,.63,36,C.white,true);text('Tasa ilustrativa: Bs.40 / US$1',.98,4.96,3.8,.38,16,'BFDBDE');
chevron(5.45,4.27,lime);
box(6.15,3.22,6.52,2.56,'254D42','4E8D6F');smallLabel('Día del abono',6.45,3.54,5.85,lime);text('US$40 × Bs.42 = Bs.1.680',6.44,4.13,5.87,.6,28,C.white,true);
text('Saldo: US$60',6.46,5.04,5.84,.38,21,lime,true);
text('Ejemplo ilustrativo: el diferencial de este abono es Bs.80;\nno se recalcula como si toda la factura se hubiera pagado.',.73,6.18,11.8,.62,17,'C4DADD',false,{align:'center'});
finish('Ejemplo: factura de USD100 a 40 VES/USD, abono posterior de USD40 a 42 VES/USD. Efectivo recibido 1680 VES; valor histórico del abono 1600 VES; diferencia del abono 80 VES; deuda restante USD60. Las cifras NO son una cotización BCV vigente. No se revaloriza toda la deuda como si hubiera sido pagada. Cada abono tiene su fecha efectiva y tasa. Consulta BCV depende de la tarea y de la fuente; puede indicarse tasa manual.');

// 10 / Restaurant, with an editable table map.
open('Restaurante');head('Del primer pedido\nal último cobro.','Mesas, atención, productos y cuentas conectadas con la operación.');
box(.66,3.12,6.1,3.5,C.white,C.line);smallLabel('Salón · ejemplo ilustrativo',.94,3.42,5.4);
[['Mesa 01','Libre',C.pale,C.deep],['Mesa 02','Ocupada','FFF0F3',C.coral],['Mesa 03','Ocupada','FFF0F3',C.coral],['Mesa 04','Por limpiar','FFF6E5',C.amber]].forEach(([t,b,fill,color],i)=>{const x=.97+(i%2)*2.79,y=4.02+Math.floor(i/2)*1.05;box(x,y,2.5,.85,fill,fill);glyph('table',x+.18,y+.21,.4,color);text(t,x+.82,y+.12,1.47,.31,16,C.ink,true);text(b,x+.82,y+.52,1.47,.23,11,color);});
[['Pedidos con observaciones',3.35],['División por persona o productos',4.21],['Varios medios de pago',5.07],['Comprobante de la cuenta',5.93]].forEach(([t,y],i)=>{number(i+1,7.27,y);text(t,7.97,y+.04,4.48,.56,20,C.ink,true);});
finish('Mapa y datos ilustrativos. Funciones verificadas: áreas/mesas, pedido, observaciones, reparto por persona/importe/productos, medios combinados, cobro, estado por limpiar y comprobantes. El material interno propone cocina, recetas y mermas como oportunidades; NO se ofrecen aquí como capacidades presentes.');

// 11 / Dashboard and permission model, not vague security promises.
open('Dashboard + Ajustes');head('Tu equipo conectado.\nCada quien con su acceso.','Supervisa el negocio y configura quién puede consultar, crear o confirmar.');
box(.66,3.1,5.9,3.53,C.navy,C.navy);smallLabel('Dashboard',.96,3.42,5.2,'B6E2D5');text('Lo pendiente no debería\npasar desapercibido.',.96,4.0,5.24,.97,27,C.white,true);
['Cobros y pagos pendientes','Alertas de inventario','Comisiones por vendedor'].forEach((t,i)=>text(t,.99,5.21+i*.37,5.12,.26,14,'BFD5DC'));
box(6.82,3.1,5.87,3.53,C.white,C.line);smallLabel('Empresas y usuarios',7.11,3.42,5.25);text('Tu organización',7.11,3.91,5.15,.46,23,C.ink,true);
['Empresa 1','Empresa 2','Empresa 3'].forEach((t,i)=>badge(t,7.11+i*1.78,4.63,1.62));
text('Administración · vendedores · compras · caja',7.12,5.34,5.13,.65,17,C.ink,true);text('Correo, mensajes, monedas y parámetros por empresa.',7.12,6.08,5.13,.37,13,C.muted);
finish('Multiempresa se refiere a empresas autorizadas de una organización. No confundir esta función con aislamiento entre clientes SaaS. El documento interno recomienda instancias/bases independientes por cliente; es una recomendación de despliegue, no una capacidad comercial ya verificada por carga. Dashboard y Ajustes completan los diez módulos. Permisos por recursos/acciones y empresa, sin promesas absolutas de seguridad.');

// 12 / Sector identification and a reason to request the demo.
open('¿Dónde encaja tu negocio?');head('El producto cambia.\nLa necesidad de control se mantiene.','Konta se configura según tu catálogo, tu equipo y tu forma de trabajar.');
[['Comercio','Tiendas, zapaterías,\nferreterías y panaderías.','Variantes + disponibilidad','box'],['Distribución','Mayoristas y equipos\nde venta en calle.','Ventas móviles + comisiones','phone'],['Restaurantes','Cafeterías y atención\npor mesas.','Pedidos + cuentas + cobros','table']].forEach(([t,b,claim,k],i)=>{const x=.66+i*4.1;box(x,3.16,3.83,2.72);glyph(k,x+.25,3.46,.5);text(t,x+.25,4.22,3.32,.4,23,C.ink,true);text(b,x+.25,4.84,3.32,.69,17,C.muted);text(claim,x+.25,5.58,3.32,.25,11,C.deep,true);});
text('¿Qué parte de tu operación te gustaría ver funcionando primero?',.76,6.32,11.8,.45,23,C.deep,true,{align:'center'});
finish('Posicionamiento recomendado en la evaluación interna: gestión comercial integrada para negocios con productos, con énfasis en comercios y distribuidores. Panaderías se incluyen por venta/inventario de artículos, no por procesos de fabricación. Los sectores mostrados no implican prestaciones especializadas ajenas al alcance desarrollado.');

// 13 / Authoritative prices and capacities from the supplied commercial v2.
open('Propuesta comercial');head('Elige por empresas y usuarios.\nCrece con tu equipo.');
const plans=[['Esencial','30','180','1 empresa','3 usuarios','2 h de capacitación'],['Crecimiento','55','300','2 empresas','6 usuarios','4 h de capacitación'],['Empresarial','95','500','3 empresas','12 usuarios','8 h de capacitación'],['Corporativo','150+','850+','Hasta 5 empresas','Hasta 25 usuarios','12 h de capacitación']];
plans.forEach(([name,month,setup,companies,users,training],i)=>{const x=.66+i*3.075,featured=i===1;box(x,2.83,2.84,3.73,featured?C.navy:C.white,featured?C.navy:C.line);text(name,x+.2,3.1,2.44,.4,20,featured?C.white:C.ink,true);text('US$'+month,x+.2,3.9,2.44,.63,34,featured?lime:C.ink,true);text('al mes',x+.22,4.55,2.4,.24,12,featured?'BAD2D9':C.muted);line(x+.2,5.02,x+2.64,5.02,featured?'3B5365':C.line);text(companies+'\n'+users,x+.21,5.24,2.42,.64,16,featured?C.white:C.ink,true);text('Alta única: US$'+setup,x+.21,6.0,2.42,.26,13,featured?'BFD9DD':C.muted);text(training,x+.21,6.31,2.42,.22,10,featured?'BFD9DD':C.muted);});
text('Todos los módulos disponibles. Usuarios totales por organización, compartidos entre sus empresas.\nTarifas propuestas sujetas a aprobación y cotización. Montos en USD de referencia; impuestos aplicables aparte.',.69,6.65,11.95,.36,10,C.muted);
finish('Se sustituyen los precios de la presentación anterior (29/59/99) por el tarifario aportado por el propietario en Konta_Comercial_2.0 y Konta_Evaluacion_Interna: mensual USD30/55/95/150+, alta única USD180/300/500/850+, empresas1/2/3/hasta5, usuarios3/6/12/hasta25. Capacitación2/4/8/12horas. Es una propuesta pendiente de aprobación, no tarifas vigentes verificadas ni una garantía de capacidad de infraestructura. Los usuarios son cuentas totales habilitadas de una organización, no usuarios por empresa. Las empresas no equivalen a almacenes. Los límites son condiciones comerciales propuestas; no se afirma que exista facturación SaaS automática ni aplicación automática de cuotas. Corporativo se cotiza desde los importes indicados y según carga; no promete servidor dedicado incluido.');

// 14 / What the customer buys beyond access; no internal economics disclosed.
open('Implementación y acompañamiento');head('No solo acceso.\nUna puesta en marcha acompañada.','Alcance definido antes de comenzar, según el plan y la operación.');
[['1','Conocemos','Empresas, usuarios\ny procesos.'],['2','Configuramos','Catálogo, almacenes,\nmonedas y permisos.'],['3','Capacitamos','Sesiones por función\ny pruebas del equipo.'],['4','Activamos','Producción y\nacompañamiento inicial.']].forEach(([n,t,b],i)=>{const x=.66+i*3.075;number(n,x,3.24);text(t,x,3.95,2.73,.39,22,C.ink,true);text(b,x,4.62,2.73,.72,17,C.muted);if(i<3)chevron(x+2.56,3.26);});
box(.66,5.7,12.03,.57,C.pale,C.pale);text('Servicio propuesto: alojamiento administrado, actualizaciones generales, respaldos y soporte remoto.',.92,5.88,11.5,.29,14,C.deep,true,{align:'center'});
text('Adicionales: usuario US$5/mes · empresa US$15/mes · capacitación US$20/h. Migraciones y desarrollos: cotización.\nCondiciones propuestas: 50% del alta al contratar y 50% antes de producción; mensualidad anticipada.',.7,6.46,11.88,.47,11,C.muted);
finish('Complemento de la evaluación interna: separar implementación, suscripción y servicios. No se exponen costos internos, márgenes, remuneración/hora, escenarios de rentabilidad, vulnerabilidades ni hoja de ruta privada. Según las fuentes, almacenes configurados inicialmente1/3/6/a medida por plan; alojamiento compartido/ampliado/dedicado sujeto a dimensionamiento y cotización. Soporte básico/estándar/prioritario/preferencial no equivale a SLA 24/7; definir por contrato. Respaldos, retención y restauración deben implementarse y probarse antes del servicio. Adicionales y forma de pago se presentan como condiciones propuestas. Documentos del sistema son operativos: no se afirma homologación fiscal ni contabilidad fiscal integral. No se ofrece propiedad del código, integraciones nuevas ni desarrollos ilimitados.');

// 15 / Intrigue and a clear commercial next step.
open('El siguiente paso',true);mark(.7,1.02,true,1.5);
text('Tu próxima venta\npuede estar\nmejor conectada.',.68,2.09,11.7,2.52,43,C.white,true);
text('Veamos Konta con tus productos,\ntu equipo y tu forma de trabajar.',.74,5.03,8.2,.91,23,'BED8DC');
box(9.08,5.05,3.59,1.08,C.green,C.green);text('Agenda una\ndemostración.',9.3,5.27,3.15,.64,21,C.white,true,{align:'center'});
text('¿Qué vendes?   ·   ¿Cuántos usuarios?   ·   ¿Cuántas empresas?',.76,6.57,11.7,.34,16,lime);
finish('Cerrar solicitando una demostración basada en la operación del prospecto. No se inventan teléfono, correo, dominio, casos de éxito, cantidad de clientes, certificaciones ni estadísticas de resultados. Las fuentes aportadas establecen que el producto requiere validaciones operativas antes de comercializar; no presentar las oportunidades de mejora como funcionalidades implementadas.');

// Output only the .pptx as the requested deliverable; these local previews support quality review.
const px=v=>+(v*120).toFixed(2);
function htmlItem(a){
 const base=`position:absolute;left:${px(a.x)}px;top:${px(a.y)}px;width:${px(a.w)}px;height:${px(a.h)}px;`;
 if(a.kind==='text')return `<div class="text" data-box="${esc(a.t.slice(0,38))}" style="${base}font:${a.bold?'700':'400'} ${a.size*120/72}px Arial,sans-serif;line-height:1.12;color:#${a.color};text-align:${a.opts.align||'left'};white-space:pre-line;">${esc(a.t)}</div>`;
 if(a.kind==='image')return `<img style="${base}${a.crop?'object-fit:cover;':''}" src="${a.data}">`;
 if(a.kind==='line')return `<div style="${base}height:${a.width}px;background:#${a.color}"></div>`;
 return `<div style="${base}background:#${a.fill};border:${a.line==='none'?'0':'1px solid #'+a.line};border-radius:${a.type===S.ellipse?'50%':a.type===S.roundRect?'15px':'0'}"></div>`;
}
const previewDir=path.join(__dirname,'preview-v3');fs.mkdirSync(previewDir,{recursive:true});
previews.forEach((p,i)=>fs.writeFileSync(path.join(previewDir,`slide-${String(i+1).padStart(2,'0')}.html`),`<!doctype html><meta charset="utf-8"><style>*{box-sizing:border-box}body{margin:0;background:#${p.dark?C.navy:C.bg}}.slide{position:relative;width:1600px;height:900px;overflow:hidden}.text{overflow:visible}</style><div class="slide">${p.items.map(htmlItem).join('')}</div>`));
const bad=previews.flatMap((p,i)=>p.items.filter(a=>a.x<0||a.y<0||a.x+a.w>W+.015||a.y+a.h>H+.015).map(a=>({slide:i+1,kind:a.kind,text:a.t,x:a.x,y:a.y,w:a.w,h:a.h})));
if(bad.length)throw Error('Fuera de límites: '+JSON.stringify(bad));
fs.writeFileSync(path.join(__dirname,'slide-manifest-v3.json'),JSON.stringify(previews.map((p,i)=>({slide:i+1,text:p.items.filter(a=>a.kind==='text').map(a=>a.t)})),null,2));
pptx.writeFile({fileName:out,compression:true}).then(()=>console.log('PowerPoint 3.0: '+out+' | '+previews.length+' diapositivas.')).catch(e=>{console.error(e);process.exitCode=1});

