import fs from "node:fs/promises";
import path from "node:path";
import { Presentation, PresentationFile } from "@oai/artifact-tool";

const ROOT = "C:/laragon/www/sistema-pollos";
const TMP_DIR = path.join(ROOT, "tmp/presentacion-sistema-avicola-20260824");
const RENDER_DIR = path.join(TMP_DIR, "rendered");
const LAYOUT_DIR = path.join(TMP_DIR, "layouts");
const FINAL_PPTX = path.join(ROOT, "output/presentations/Sistema_Avicola_Presentacion_Comercial_Gustavo_Noriega.pptx");

const ASSETS = {
  icon: path.join(ROOT, "public/icons/icon-512.png"),
  cover: path.join(ROOT, "public/images/youtube/miniatura-despacho-mayorista-base.png"),
  closing: path.join(ROOT, "public/images/youtube/miniatura-despacho-minorista-base.png"),
  mayorista: path.join(ROOT, "public/images/youtube/miniatura-sistema-avicola-mayorista.png"),
  minorista: path.join(ROOT, "public/images/youtube/miniatura-sistema-avicola-minorista.png"),
  despachoMayorista: path.join(ROOT, "public/images/youtube/miniatura-despacho-mayorista-peso-balanza.png"),
  despachoMinorista: path.join(ROOT, "public/images/youtube/miniatura-despacho-minorista-peso-balanza.png"),
  login: path.join(ROOT, "tmp/presentacion_sistema_avicola_capturas/00_login.png"),
  recepcion: "C:/Users/Gustavo/Downloads/WhatsApp Image 2026-08-22 at 17.00.16.jpeg",
  reporte: path.join(ROOT, "tmp/pdfs/auditoria-imagenes/reporte-2026-08-21-pagina-1.png"),
};

const C = {
  bg: "#F7F5EF",
  white: "#FFFFFF",
  ink: "#0A1E22",
  ink2: "#12363A",
  muted: "#5B6A6C",
  mutedLight: "#A9B7B8",
  panel: "#E7ECE9",
  panel2: "#DDE5E1",
  rule: "#BBC7C3",
  yellow: "#F6C344",
  yellow2: "#FFD761",
  cyan: "#18B9D4",
  green: "#32AA6B",
  red: "#D94B43",
};

const FONT = "Arial";
let elementCounter = 0;
const imageCache = new Map();

async function readArrayBuffer(filePath) {
  if (!imageCache.has(filePath)) {
    const bytes = await fs.readFile(filePath);
    imageCache.set(
      filePath,
      bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength),
    );
  }
  return imageCache.get(filePath);
}

function contentType(filePath) {
  const ext = path.extname(filePath).toLowerCase();
  if (ext === ".jpg" || ext === ".jpeg") return "image/jpeg";
  if (ext === ".webp") return "image/webp";
  return "image/png";
}

function addShape(slide, {
  x, y, w, h, fill = "none", line = "none", lineWidth = 0,
  radius = 0, geometry = "rect", name,
}) {
  const shape = slide.shapes.add({
    geometry,
    name: name || `shape-${++elementCounter}`,
    position: { left: x, top: y, width: w, height: h },
    fill,
    line: { style: "solid", fill: line, width: lineWidth },
    ...(radius ? { borderRadius: radius } : {}),
  });
  return shape;
}

function addText(slide, text, {
  x, y, w, h, size = 24, color = C.ink, bold = false,
  align = "left", valign = "top", autoFit = "shrinkText",
  inset = 0, name,
}) {
  const box = slide.shapes.add({
    geometry: "textbox",
    name: name || `text-${++elementCounter}`,
    position: { left: x, top: y, width: w, height: h },
    fill: "none",
    line: { style: "solid", fill: "none", width: 0 },
  });
  box.text = text;
  box.text.style = {
    fontSize: size,
    typeface: FONT,
    color,
    bold,
    alignment: align,
    verticalAlignment: valign,
    autoFit,
    wrap: "square",
    insets: { top: inset, right: inset, bottom: inset, left: inset },
  };
  return box;
}

function addRule(slide, x, y, w, color = C.rule, h = 2) {
  return addShape(slide, { x, y, w, h, fill: color, line: color, lineWidth: 0 });
}

function addBulletList(slide, items, {
  x, y, w, itemH = 56, color = C.ink, bulletColor = C.green,
  size = 23, gap = 8,
}) {
  items.forEach((item, index) => {
    const top = y + index * (itemH + gap);
    addShape(slide, {
      x, y: top + 7, w: 13, h: 13,
      fill: bulletColor, line: bulletColor, geometry: "ellipse",
    });
    addText(slide, item, {
      x: x + 28, y: top, w: w - 28, h: itemH,
      size, color, bold: false,
    });
  });
}

async function addImage(slide, filePath, {
  x, y, w, h, fit = "cover", radius = 0, crop,
  alt = "Imagen del Sistema Avícola", name,
}) {
  const image = slide.images.add({
    blob: await readArrayBuffer(filePath),
    contentType: contentType(filePath),
    alt,
    fit,
    position: { left: x, top: y, width: w, height: h },
    geometry: radius ? "roundRect" : "rect",
    ...(radius ? { borderRadius: radius } : {}),
    ...(crop ? { crop } : {}),
    name: name || `image-${++elementCounter}`,
  });
  return image;
}

function addFooter(slide, page, dark = false) {
  addRule(slide, 64, 674, 1152, dark ? "#365158" : C.rule, 1);
  addText(slide, "SISTEMA AVÍCOLA  •  PRESENTACIÓN COMERCIAL", {
    x: 64, y: 682, w: 520, h: 20, size: 14,
    color: dark ? C.mutedLight : C.muted, bold: true, autoFit: "none",
  });
  addText(slide, String(page).padStart(2, "0"), {
    x: 1150, y: 682, w: 66, h: 20, size: 14,
    color: dark ? C.mutedLight : C.muted, bold: true,
    align: "right", autoFit: "none",
  });
}

function addTitle(slide, title, { dark = false, size = 50, kicker } = {}) {
  if (kicker) {
    addText(slide, kicker.toUpperCase(), {
      x: 64, y: 38, w: 520, h: 26, size: 16,
      color: dark ? C.yellow : C.ink2, bold: true, autoFit: "none",
    });
  }
  addText(slide, title, {
    x: 64, y: kicker ? 66 : 44, w: 1152, h: 72, size,
    color: dark ? C.white : C.ink, bold: true, autoFit: "none",
  });
}

function addNotes(slide, sources) {
  const lines = ["[Sources]", ...sources.map((s) => `- ${s}`), "[/Sources]"];
  slide.speakerNotes.textFrame.setText(lines.join("\n"));
  slide.speakerNotes.setVisible(true);
}

function addConnector(slide, x1, y1, x2, y2, color = C.rule, thickness = 3) {
  const dx = x2 - x1;
  const dy = y2 - y1;
  const length = Math.sqrt(dx * dx + dy * dy);
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;
  const line = addShape(slide, {
    x: (x1 + x2) / 2 - length / 2,
    y: (y1 + y2) / 2 - thickness / 2,
    w: length,
    h: thickness,
    fill: color,
    line: color,
  });
  line.rotation = angle;
  return line;
}

function addMindNode(slide, { x, y, w, h, title, body, accent = C.cyan }) {
  addShape(slide, { x, y, w, h, fill: C.white, line: C.rule, lineWidth: 1, radius: 18 });
  addShape(slide, { x, y, w: 10, h, fill: accent, line: accent, radius: 10 });
  addText(slide, title, { x: x + 26, y: y + 22, w: w - 42, h: 36, size: 28, bold: true });
  addText(slide, body, { x: x + 26, y: y + 65, w: w - 42, h: h - 78, size: 21.5, color: C.muted });
}

async function slide01(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.ink;
  await addImage(slide, ASSETS.cover, {
    x: 560, y: 0, w: 720, h: 720, fit: "cover",
    crop: { left: 0.12, top: 0, right: 0, bottom: 0 },
    alt: "Operación mayorista con balanza y javas",
  });
  addShape(slide, { x: 548, y: 0, w: 12, h: 720, fill: C.yellow, line: C.yellow });
  await addImage(slide, ASSETS.icon, { x: 64, y: 50, w: 72, h: 72, fit: "contain", radius: 18, alt: "Ícono del Sistema Avícola" });
  addText(slide, "PRESENTACIÓN COMERCIAL", { x: 160, y: 60, w: 330, h: 28, size: 16, color: C.yellow, bold: true, autoFit: "none" });
  addText(slide, "SISTEMA", { x: 64, y: 176, w: 430, h: 90, size: 78, color: C.white, bold: true, autoFit: "none" });
  addText(slide, "AVÍCOLA", { x: 64, y: 254, w: 430, h: 94, size: 82, color: C.yellow, bold: true, autoFit: "none" });
  addText(slide, "Control integral desde la recepción hasta el reporte.", { x: 68, y: 374, w: 420, h: 98, size: 29, color: C.white, bold: false });
  addRule(slide, 68, 510, 130, C.cyan, 5);
  addText(slide, "Creado y desarrollado por", { x: 68, y: 546, w: 300, h: 26, size: 18, color: C.mutedLight, autoFit: "none" });
  addText(slide, "Gustavo Noriega", { x: 68, y: 578, w: 360, h: 42, size: 31, color: C.white, bold: true, autoFit: "none" });
  addText(slide, "WhatsApp  949 421 023", { x: 68, y: 628, w: 390, h: 38, size: 26, color: C.yellow, bold: true, autoFit: "none" });
  addNotes(slide, [
    `Visual asset: ${ASSETS.cover}`,
    `Brand icon: ${ASSETS.icon}`,
    "Authorship and contact supplied directly by Gustavo Noriega.",
  ]);
}

async function slide02(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Una operación. Una fuente de verdad.", { kicker: "La oportunidad" });
  const items = [
    ["PESAR", "Bruto, tara y neto"],
    ["VENDER", "Cliente, precio y ticket"],
    ["DESPACHAR", "Producto, destino y entrega"],
    ["COBRAR", "Caja, cartera y pagos"],
  ];
  addRule(slide, 102, 310, 1076, C.rule, 3);
  items.forEach(([head, body], i) => {
    const x = 64 + i * 288;
    addShape(slide, { x: x + 118, y: 296, w: 28, h: 28, fill: i % 2 ? C.cyan : C.yellow, line: C.ink, lineWidth: 3, geometry: "ellipse" });
    addText(slide, head, { x, y: 206, w: 270, h: 56, size: head === "DESPACHAR" ? 37 : 44, color: C.ink, bold: true, align: "center", autoFit: "none" });
    addText(slide, body, { x, y: 352, w: 270, h: 58, size: 22, color: C.muted, align: "center" });
  });
  addText(slide, "Una sola fuente de información reduce pasos manuales y permite seguir la operación desde el peso hasta el saldo.", {
    x: 150, y: 492, w: 980, h: 100, size: 29, color: C.ink2, bold: true, align: "center", valign: "middle",
  });
  addFooter(slide, 2);
  addNotes(slide, [
    `${ROOT}/routes/web.php`,
    `${ROOT}/config/access_modules.php`,
    "Narrative synthesis based on verified operational, dispatch, finance, and reporting modules.",
  ]);
}

async function slide03(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Cinco áreas trabajan sobre la misma operación", { kicker: "Mapa del sistema" });

  const center = { x: 520, y: 265, w: 240, h: 190 };
  const nodes = [
    { x: 70, y: 155, w: 310, h: 132, title: "Operación", body: "Recepción, pesaje\ny jornada", accent: C.green },
    { x: 900, y: 155, w: 310, h: 132, title: "Ventas", body: "Mayorista, minorista\ny precios", accent: C.yellow },
    { x: 70, y: 470, w: 310, h: 132, title: "Finanzas", body: "Compras, caja, cartera\ny pagos", accent: C.cyan },
    { x: 900, y: 470, w: 310, h: 132, title: "Logística", body: "Despacho, flota\ny destinos", accent: C.yellow },
    { x: 485, y: 520, w: 310, h: 112, title: "Control", body: "Javas, roles y reportes", accent: C.green },
  ];
  const cx = center.x + center.w / 2;
  const cy = center.y + center.h / 2;
  nodes.forEach((n) => addConnector(slide, cx, cy, n.x + n.w / 2, n.y + n.h / 2, C.rule, 4));
  addShape(slide, { x: center.x, y: center.y, w: center.w, h: center.h, fill: C.ink, line: C.ink, radius: 36 });
  addText(slide, "SISTEMA\nAVÍCOLA", { x: center.x + 20, y: center.y + 40, w: center.w - 40, h: 110, size: 38, color: C.white, bold: true, align: "center", valign: "middle" });
  nodes.forEach((n) => addMindNode(slide, n));
  addFooter(slide, 3);
  addNotes(slide, [
    `${ROOT}/routes/web.php`,
    `${ROOT}/config/access_modules.php`,
    `${ROOT}/database/seeders/DatabaseSeeder.php`,
  ]);
}

async function slide04(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Acceso seguro y módulos por función", { kicker: "Control de usuarios" });
  addText(slide, "Cada usuario ve las áreas asignadas a su trabajo.", { x: 64, y: 160, w: 520, h: 76, size: 32, color: C.ink2, bold: true });
  addBulletList(slide, [
    "Roles y accesos personalizados por módulo.",
    "Sesión protegida y administración de usuarios.",
    "Aplicación instalable en Windows como PWA.",
    "Modo kiosco e impresión térmica para operación.",
  ], { x: 64, y: 270, w: 515, itemH: 44, size: 22.5, gap: 14, bulletColor: C.green });
  addShape(slide, { x: 624, y: 142, w: 592, h: 480, fill: C.ink, line: C.ink2, lineWidth: 1, radius: 22 });
  await addImage(slide, ASSETS.login, {
    x: 640, y: 158, w: 560, h: 448, fit: "cover", radius: 16,
    crop: { left: 0.47, top: 0, right: 0, bottom: 0 },
    alt: "Pantalla real de inicio de sesión del sistema",
  });
  addShape(slide, { x: 640, y: 158, w: 286, h: 448, fill: C.ink, line: C.ink, radius: 16 });
  addText(slide, "ACCESO POR\nFUNCIÓN", { x: 670, y: 252, w: 226, h: 92, size: 29, color: C.white, bold: true, align: "center", valign: "middle" });
  addText(slide, "Roles y sesión protegida", { x: 670, y: 370, w: 226, h: 64, size: 20, color: C.mutedLight, align: "center", valign: "middle" });
  addText(slide, "CAPTURA REAL", { x: 648, y: 166, w: 150, h: 24, size: 14, color: C.yellow, bold: true, autoFit: "none" });
  addFooter(slide, 4);
  addNotes(slide, [
    `Real UI capture: ${ASSETS.login}`,
    `${ROOT}/config/access_modules.php`,
    `${ROOT}/docs/pwa-y-modo-kiosco.md`,
  ]);
}

async function slide05(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Recepción: peso, origen y destino", { kicker: "Operación real" });
  addText(slide, "Cada pesada entra al sistema desde la balanza o mediante captura manual.", { x: 64, y: 154, w: 620, h: 84, size: 32, color: C.ink2, bold: true });
  addBulletList(slide, [
    "Conexión Serial o BLE, sujeta al equipo compatible.",
    "Origen propio o empresa externa.",
    "Aves, javas, tara, peso bruto y peso neto.",
    "Cuatro destinos configurables: almacén o despacho.",
  ], { x: 64, y: 268, w: 620, itemH: 44, size: 22.5, gap: 13, bulletColor: C.cyan });
  addText(slide, "SERIAL", { x: 64, y: 548, w: 150, h: 34, size: 26, color: C.ink, bold: true, autoFit: "none" });
  addText(slide, "BLE", { x: 245, y: 548, w: 120, h: 34, size: 26, color: C.ink, bold: true, autoFit: "none" });
  addText(slide, "MANUAL", { x: 395, y: 548, w: 180, h: 34, size: 26, color: C.ink, bold: true, autoFit: "none" });
  addRule(slide, 64, 590, 500, C.yellow, 5);
  addShape(slide, { x: 790, y: 126, w: 370, h: 520, fill: C.white, line: C.rule, lineWidth: 1, radius: 22 });
  await addImage(slide, ASSETS.recepcion, { x: 808, y: 144, w: 334, h: 484, fit: "contain", radius: 14, alt: "Captura real de recepción de pollo vivo" });
  addText(slide, "CAPTURA REAL • 22 AGO 2026", { x: 820, y: 154, w: 270, h: 22, size: 14, color: C.green, bold: true, autoFit: "none" });
  addFooter(slide, 5);
  addNotes(slide, [
    `Real UI capture: ${ASSETS.recepcion}`,
    `${ROOT}/app/Http/Requests/LiveChickenReception/UpdateLiveChickenReceptionConfigurationRequest.php`,
    `${ROOT}/routes/web.php`,
  ]);
}

async function slide06(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.ink;
  addTitle(slide, "Mayorista y minorista trabajan en paralelo", { dark: true, kicker: "Dos modalidades • cuatro puestos" });
  addShape(slide, { x: 64, y: 158, w: 552, h: 310, fill: C.ink2, line: "#365158", lineWidth: 1, radius: 18 });
  addShape(slide, { x: 664, y: 158, w: 552, h: 310, fill: C.ink2, line: "#365158", lineWidth: 1, radius: 18 });
  await addImage(slide, ASSETS.mayorista, { x: 72, y: 166, w: 536, h: 294, fit: "cover", radius: 13, alt: "Miniatura oficial del Sistema Avícola mayorista" });
  await addImage(slide, ASSETS.minorista, { x: 672, y: 166, w: 536, h: 294, fit: "cover", radius: 13, alt: "Miniatura oficial del Sistema Avícola minorista" });
  addText(slide, "MAYORISTA", { x: 72, y: 494, w: 250, h: 38, size: 30, color: C.yellow, bold: true, autoFit: "none" });
  addText(slide, "Javas • tara • aves • peso neto", { x: 72, y: 538, w: 500, h: 34, size: 22, color: C.white, autoFit: "none" });
  addText(slide, "MINORISTA", { x: 672, y: 494, w: 250, h: 38, size: 30, color: C.yellow, bold: true, autoFit: "none" });
  addText(slide, "Peso • ajustes • presentación • total", { x: 672, y: 538, w: 500, h: 34, size: 22, color: C.white, autoFit: "none" });
  addText(slide, "Pantalla secundaria para mostrar al cliente peso, cantidad y total.", { x: 64, y: 610, w: 1100, h: 44, size: 25, color: C.cyan, bold: true, autoFit: "none" });
  addFooter(slide, 6, true);
  addNotes(slide, [
    `Latest YouTube thumbnail: ${ASSETS.mayorista}`,
    `Latest YouTube thumbnail: ${ASSETS.minorista}`,
    `${ROOT}/routes/web.php`,
  ]);
}

async function slide07(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Un flujo conecta toda la jornada", { kicker: "De punta a punta" });
  addText(slide, "Cada etapa alimenta la siguiente y termina en control operativo y financiero.", { x: 64, y: 132, w: 1020, h: 42, size: 24, color: C.muted });
  const xs = [110, 320, 530, 740, 950, 1160];
  addRule(slide, 110, 320, 1050, C.rule, 4);
  const steps = [
    ["Configurar", "Usuarios, precios\ny balanzas"],
    ["Programar", "Jornada, proveedor\ny camión"],
    ["Recibir", "Origen, javas\ny peso"],
    ["Despachar", "Cliente, producto\ny ticket"],
    ["Finanzas", "Cobrar, pagar\ny conciliar"],
    ["Controlar", "Resumen, saldos\ny reportes"],
  ];
  steps.forEach(([head, body], i) => {
    const x = xs[i];
    addShape(slide, { x: x - 30, y: 290, w: 60, h: 60, fill: i === 5 ? C.green : C.ink, line: C.bg, lineWidth: 5, geometry: "ellipse" });
    addText(slide, String(i + 1).padStart(2, "0"), { x: x - 24, y: 302, w: 48, h: 32, size: 21, color: i === 5 ? C.white : C.yellow, bold: true, align: "center", autoFit: "none" });
    const above = i % 2 === 0;
    addText(slide, head, { x: x - 88, y: above ? 210 : 386, w: 176, h: 34, size: 25, color: C.ink, bold: true, align: "center", autoFit: "none" });
    addText(slide, body, { x: x - 92, y: above ? 246 : 426, w: 184, h: 68, size: 21.5, color: C.muted, align: "center" });
  });
  addShape(slide, { x: 260, y: 560, w: 760, h: 58, fill: C.panel, line: C.panel, radius: 18 });
  addText(slide, "Los precios usados y el movimiento quedan vinculados al ticket y al control posterior.", { x: 288, y: 575, w: 704, h: 30, size: 22, color: C.ink2, bold: true, align: "center", autoFit: "none" });
  addFooter(slide, 7);
  addNotes(slide, [
    `${ROOT}/routes/web.php`,
    `${ROOT}/app/Services/ReportDataService.php`,
    `${ROOT}/app/Http/Controllers/Api/V1/DailyDispatchTicketController.php`,
  ]);
}

async function slide08(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Venta y despacho se adaptan a tu operación", { kicker: "Configuración + servicio a medida", size: 48 });
  addShape(slide, { x: 64, y: 154, w: 560, h: 316, fill: C.ink, line: C.ink, radius: 18 });
  await addImage(slide, ASSETS.despachoMinorista, { x: 72, y: 162, w: 544, h: 300, fit: "cover", radius: 13, alt: "Venta minorista con lectura de balanza" });
  addText(slide, "CONFIGURACIÓN INCLUIDA", { x: 668, y: 150, w: 450, h: 34, size: 27, color: C.ink, bold: true, autoFit: "none" });
  addBulletList(slide, [
    "Precios generales y por cliente.",
    "Balanzas, ajustes de peso y estaciones.",
    "Métodos de pago, entregas y tickets.",
    "Roles, accesos y colores de reportes.",
  ], { x: 668, y: 202, w: 500, itemH: 35, size: 21.5, gap: 10, bulletColor: C.green });
  addRule(slide, 668, 420, 500, C.rule, 2);
  addText(slide, "ADAPTACIÓN A MEDIDA", { x: 668, y: 448, w: 515, h: 34, size: 25, color: C.ink, bold: true, autoFit: "none" });
  addText(slide, "Si tu negocio necesita pasos, campos, validaciones o formatos particulares, pueden desarrollarse como una adaptación a medida.", { x: 668, y: 496, w: 500, h: 112, size: 22, color: C.muted });
  addShape(slide, { x: 64, y: 514, w: 560, h: 102, fill: C.yellow2, line: C.yellow, lineWidth: 1, radius: 18 });
  addText(slide, "Tu forma de trabajar guía la configuración; los cambios adicionales se definen antes de implementar.", { x: 92, y: 536, w: 504, h: 60, size: 23, color: C.ink, bold: true, align: "center", valign: "middle" });
  addFooter(slide, 8);
  addNotes(slide, [
    `Visual asset: ${ASSETS.despachoMinorista}`,
    `${ROOT}/app/Http/Requests/Operation/UpdateRetailConfigurationRequest.php`,
    `${ROOT}/app/Models/AjustePesoMayoristaDos.php`,
    `${ROOT}/resources/views/precios-jornada.blade.php`,
    `${ROOT}/app/Services/ReportPaletteService.php`,
  ]);
}

async function slide09(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.ink;
  addTitle(slide, "Finanzas y control cierran el ciclo", { dark: true, kicker: "La operación también deja cuentas claras" });
  addText(slide, "Ventas, compras y movimientos de dinero dejan de vivir en registros aislados.", { x: 64, y: 168, w: 520, h: 170, size: 39, color: C.white, bold: true });
  addRule(slide, 64, 380, 180, C.yellow, 6);
  addText(slide, "Una misma lectura para operación, administración y cierre de jornada.", { x: 64, y: 414, w: 510, h: 96, size: 25, color: C.mutedLight });
  const finance = [
    ["Compras", "Contado o crédito"],
    ["Caja y cobranzas", "Efectivo y cuentas"],
    ["Pagos y gastos", "Método, responsable y detalle"],
    ["Saldos", "Clientes y proveedores"],
    ["Reportes", "PDF, imagen y CSV según el caso"],
  ];
  finance.forEach(([head, body], i) => {
    const y = 154 + i * 92;
    addText(slide, String(i + 1).padStart(2, "0"), { x: 650, y, w: 54, h: 32, size: 18, color: C.yellow, bold: true, autoFit: "none" });
    addText(slide, head, { x: 720, y: y - 4, w: 260, h: 36, size: 29, color: C.white, bold: true, autoFit: "none" });
    addText(slide, body, { x: 980, y: y, w: 236, h: 42, size: 21.5, color: C.mutedLight, align: "right" });
    if (i < finance.length - 1) addRule(slide, 650, y + 58, 566, "#365158", 1);
  });
  addText(slide, "VENTA / COMPRA", { x: 64, y: 588, w: 230, h: 36, size: 24, color: C.cyan, bold: true, align: "center", autoFit: "none" });
  addText(slide, "→", { x: 300, y: 580, w: 70, h: 42, size: 34, color: C.yellow, bold: true, align: "center", autoFit: "none" });
  addText(slide, "CAJA Y CARTERA", { x: 370, y: 588, w: 260, h: 36, size: 24, color: C.cyan, bold: true, align: "center", autoFit: "none" });
  addText(slide, "→", { x: 636, y: 580, w: 70, h: 42, size: 34, color: C.yellow, bold: true, align: "center", autoFit: "none" });
  addText(slide, "REPORTE", { x: 710, y: 588, w: 220, h: 36, size: 24, color: C.cyan, bold: true, align: "center", autoFit: "none" });
  addFooter(slide, 9, true);
  addNotes(slide, [
    `${ROOT}/routes/web.php`,
    `${ROOT}/docs/finanzas.md`,
    `${ROOT}/docs/compras.md`,
    `${ROOT}/app/Http/Controllers/Web/ReportController.php`,
  ]);
}

async function slide10(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Javas y bandejas permanecen trazables", { kicker: "Control de activos operativos" });
  addText(slide, "El sistema vincula inventario, préstamos, devoluciones y saldos con los movimientos del negocio.", { x: 64, y: 132, w: 1080, h: 46, size: 24, color: C.muted });
  const nodeXs = [62, 362, 662, 962];
  for (let i = 0; i < 3; i++) {
    addShape(slide, { x: nodeXs[i] + 254, y: 330, w: 66, h: 54, fill: C.yellow, line: C.yellow, geometry: "chevron" });
  }
  const lifecycle = [
    ["01", "Inventario", "Existencia por tipo y ubicación."],
    ["02", "Entrega o préstamo", "Movimiento asociado al cliente."],
    ["03", "Devolución", "Retorno asociado al cliente."],
    ["04", "Saldo trazable", "Historial y pendiente por devolver."],
  ];
  lifecycle.forEach(([num, head, body], i) => {
    const x = nodeXs[i];
    addShape(slide, { x, y: 242, w: 256, h: 238, fill: i === 3 ? C.ink : C.white, line: i === 3 ? C.ink : C.rule, lineWidth: 1, radius: 20 });
    addText(slide, num, { x: x + 22, y: 264, w: 58, h: 28, size: 18, color: i === 3 ? C.yellow : C.green, bold: true, autoFit: "none" });
    addText(slide, head, { x: x + 22, y: 310, w: 212, h: 66, size: 29, color: i === 3 ? C.white : C.ink, bold: true });
    addText(slide, body, { x: x + 22, y: 390, w: 212, h: 72, size: 21.5, color: i === 3 ? C.mutedLight : C.muted });
  });
  addShape(slide, { x: 245, y: 544, w: 790, h: 66, fill: C.panel, line: C.panel, radius: 18 });
  addText(slide, "Cada movimiento registra el cliente, el tipo y la cantidad; el sistema calcula el saldo pendiente.", { x: 276, y: 554, w: 728, h: 48, size: 21.5, color: C.ink2, bold: true, align: "center", autoFit: "none" });
  addFooter(slide, 10);
  addNotes(slide, [
    `${ROOT}/routes/web.php`,
    `${ROOT}/database/seeders/DatabaseSeeder.php`,
    `${ROOT}/resources/views/control-javas-inventario.blade.php`,
    `${ROOT}/resources/views/control-javas-trazabilidad.blade.php`,
  ]);
}

async function slide11(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Reportes listos para decidir y compartir", { kicker: "Evidencia y salida" });
  addText(slide, "REPORTE REAL DEL SISTEMA • DATOS DE DEMOSTRACIÓN", { x: 64, y: 136, w: 630, h: 28, size: 16, color: C.green, bold: true, autoFit: "none" });
  addShape(slide, { x: 64, y: 174, w: 680, h: 410, fill: C.white, line: C.rule, lineWidth: 1, radius: 18 });
  addText(slide, "REPORTE DE VENTAS POR CLIENTE", { x: 92, y: 194, w: 624, h: 24, size: 15, color: C.ink, bold: true, align: "center", autoFit: "none" });
  addText(slide, "Periodo de demostración", { x: 92, y: 220, w: 624, h: 18, size: 10, color: C.muted, align: "center", autoFit: "none" });
  const reportMetrics = [
    [82, 154, "REGISTROS", "88"],
    [244, 144, "AVES NETAS", "3,014"],
    [396, 166, "PESO NETO", "11,164.900 kg"],
    [570, 156, "VENTA TOTAL", "S/ 43,531.63"],
  ];
  reportMetrics.forEach(([x, w, label, value]) => {
    addShape(slide, { x, y: 246, w, h: 58, fill: C.panel, line: C.rule, lineWidth: 1, radius: 6 });
    addText(slide, label, { x: x + 8, y: 256, w: w - 16, h: 14, size: 8, color: C.muted, bold: true, align: "right", autoFit: "none" });
    addText(slide, value, { x: x + 8, y: 274, w: w - 16, h: 22, size: 15, color: C.ink, bold: true, align: "right", autoFit: "none" });
  });
  const reportColumns = [
    [82, 220, "CLIENTE"],
    [302, 112, "CANAL"],
    [414, 148, "PESO NETO"],
    [562, 164, "TOTAL S/"],
  ];
  reportColumns.forEach(([x, w, label]) => {
    addShape(slide, { x, y: 320, w, h: 30, fill: C.green, line: C.white, lineWidth: 1 });
    addText(slide, label, { x: x + 8, y: 329, w: w - 16, h: 14, size: 9, color: C.white, bold: true, autoFit: "none" });
  });
  const demoRows = [
    [350, "Cliente demo A", "Mayorista", "1,008.200 kg", "S/ 3,931.98"],
    [384, "Cliente demo B", "Minorista", "515.900 kg", "S/ 2,192.58"],
  ];
  demoRows.forEach(([y, client, channel, weight, total], index) => {
    const fill = index % 2 ? C.bg : C.white;
    [[82, 220, client], [302, 112, channel], [414, 148, weight], [562, 164, total]].forEach(([x, w, value], cellIndex) => {
      addShape(slide, { x, y, w, h: 34, fill, line: C.rule, lineWidth: 1 });
      addText(slide, value, { x: x + 8, y: y + 9, w: w - 16, h: 16, size: 10, color: C.ink, bold: cellIndex === 3, align: cellIndex > 1 ? "right" : "left", autoFit: "none" });
    });
  });
  addShape(slide, { x: 82, y: 438, w: 644, h: 116, fill: C.panel, line: C.panel, radius: 12 });
  addText(slide, "VISTA DEL REPORTE · DATOS ANONIMIZADOS", { x: 106, y: 458, w: 596, h: 24, size: 16, color: C.green, bold: true, align: "center", autoFit: "none" });
  addText(slide, "Muestra de datos: no representa resultados garantizados.", { x: 108, y: 500, w: 592, h: 28, size: 17, color: C.red, bold: true, align: "center", autoFit: "none" });
  addText(slide, "SALIDAS", { x: 80, y: 602, w: 120, h: 28, size: 17, color: C.muted, bold: true, autoFit: "none" });
  addText(slide, "PDF  •  IMAGEN  •  CSV SEGÚN REPORTE", { x: 200, y: 598, w: 530, h: 34, size: 24, color: C.ink, bold: true, autoFit: "none" });
  addText(slide, "7 reportes PDF disponibles", { x: 790, y: 142, w: 380, h: 36, size: 29, color: C.ink, bold: true, autoFit: "none" });
  const reports = [
    "Ventas por cliente",
    "Estado de cuenta de cliente",
    "Estado de cuenta de proveedor",
    "Pagos y cobros",
    "Movimientos por responsable",
    "Cuentas de clientes",
    "Ruta de cobranza 2",
  ];
  reports.forEach((report, i) => {
    const y = 205 + i * 58;
    addText(slide, String(i + 1).padStart(2, "0"), { x: 790, y, w: 42, h: 28, size: 16, color: C.green, bold: true, autoFit: "none" });
    addText(slide, report, { x: 844, y: y - 2, w: 360, h: 34, size: 21.5, color: C.ink, bold: i === 0, autoFit: "none" });
    if (i < reports.length - 1) addRule(slide, 790, y + 40, 414, C.rule, 1);
  });
  addFooter(slide, 11);
  addNotes(slide, [
    `Report preview: ${ASSETS.reporte}`,
    "Report source PDF: C:/Users/Gustavo/Downloads/ventas-clientes-2026-08-21-2026-08-21.pdf",
    `${ROOT}/app/Http/Controllers/Web/ReportController.php`,
    `${ROOT}/app/Services/ReportDataService.php`,
  ]);
}

async function slide12(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.bg;
  addTitle(slide, "Reporte de prueba: peso, venta y devolución", { kicker: "Escenario validado" });
  addText(slide, "PESO NETO POR CLIENTE (KG)", { x: 64, y: 142, w: 500, h: 28, size: 17, color: C.muted, bold: true, autoFit: "none" });
  slide.charts.add("bar", {
    position: { left: 56, top: 170, width: 620, height: 400 },
    categories: ["Cliente Alfa", "Cliente Beta"],
    series: [{
      name: "Peso neto",
      categories: ["Cliente Alfa", "Cliente Beta"],
      values: [80, 45],
      fill: C.cyan,
      points: [{ idx: 0, fill: C.green }, { idx: 1, fill: C.cyan }],
    }],
    hasLegend: false,
    dataLabels: { showValue: true, position: "outEnd", textStyle: { fill: C.ink, fontSize: 16, bold: true } },
    chartFill: C.bg,
    chartLine: { style: "solid", width: 0, fill: C.bg },
    plotAreaFill: { type: "none" },
    plotAreaLine: { style: "solid", width: 0, fill: C.bg },
    xAxis: {
      visible: true,
      line: { style: "solid", width: 1, fill: C.rule },
      textStyle: { fontSize: 15, fill: C.ink },
    },
    yAxis: {
      visible: true,
      min: 0,
      max: 100,
      majorUnit: 20,
      majorGridlines: { style: "solid", width: 1, fill: C.panel2 },
      line: { style: "solid", width: 0, fill: C.bg },
      textStyle: { fontSize: 14, fill: C.muted },
    },
    barOptions: { direction: "column", grouping: "clustered", gapWidth: 80 },
  });

  const values = [
    ["Cliente", "Peso neto", "Venta", "Aves"],
    ["Cliente Alfa", "80 kg", "S/ 390", "20"],
    ["Cliente Beta", "45 kg", "S/ 315", "10"],
    ["Total", "125 kg", "S/ 705", "30"],
  ];
  const table = slide.tables.add({
    rows: 4,
    columns: 4,
    left: 724,
    top: 190,
    width: 492,
    height: 252,
    columnWidths: [160, 120, 120, 92],
    values,
  });
  table.borders.assign({ style: "solid", fill: C.rule, width: 1 });
  table.cells.block({ row: 0, column: 0, rowCount: 1, columnCount: 4 }).assign({
    fill: C.ink,
    textStyle: { color: C.white, fontSize: 18, bold: true },
    margins: { top: 10, right: 8, bottom: 8, left: 8 },
    anchor: "middle",
  });
  table.cells.block({ row: 1, column: 0, rowCount: 2, columnCount: 4 }).assign({
    fill: C.white,
    textStyle: { color: C.ink, fontSize: 18 },
    margins: { top: 9, right: 8, bottom: 8, left: 8 },
    anchor: "middle",
  });
  table.cells.block({ row: 3, column: 0, rowCount: 1, columnCount: 4 }).assign({
    fill: C.yellow2,
    textStyle: { color: C.ink, fontSize: 18, bold: true },
    margins: { top: 9, right: 8, bottom: 8, left: 8 },
    anchor: "middle",
  });
  addShape(slide, { x: 724, y: 474, w: 492, h: 88, fill: C.panel, line: C.panel, radius: 16 });
  addText(slide, "3 javas  •  30 aves  •  10 kg devueltos", { x: 746, y: 492, w: 448, h: 36, size: 24, color: C.ink, bold: true, align: "center", autoFit: "none" });
  addText(slide, "La devolución queda registrada dentro del mismo escenario.", { x: 748, y: 532, w: 444, h: 24, size: 17, color: C.muted, align: "center", autoFit: "none" });
  addText(slide, "Ejemplo elaborado con datos de prueba validados; no corresponde a información de un cliente real.", { x: 64, y: 610, w: 1152, h: 28, size: 17, color: C.red, bold: true, align: "center", autoFit: "none" });
  addFooter(slide, 12);
  addNotes(slide, [
    `${ROOT}/tests/Feature/ReportPdfTest.php (validated fixture: Cliente Alfa/Beta, totals 125 kg, S/ 705, 30 birds, 3 containers, 10 kg return).`,
    `${ROOT}/app/Services/ReportDataService.php`,
  ]);
}

async function slide13(presentation) {
  const slide = presentation.slides.add();
  slide.background.fill = C.ink;
  await addImage(slide, ASSETS.closing, {
    x: 650, y: 0, w: 630, h: 720, fit: "cover",
    crop: { left: 0.12, top: 0, right: 0, bottom: 0 },
    alt: "Venta minorista con balanza y atención al cliente",
  });
  addShape(slide, { x: 638, y: 0, w: 12, h: 720, fill: C.yellow, line: C.yellow });
  await addImage(slide, ASSETS.icon, { x: 64, y: 48, w: 64, h: 64, fit: "contain", radius: 16, alt: "Ícono del Sistema Avícola" });
  addText(slide, "AGENDA UNA DEMOSTRACIÓN", { x: 152, y: 62, w: 370, h: 28, size: 16, color: C.yellow, bold: true, autoFit: "none" });
  addText(slide, "Tu flujo real es\nel punto de partida.", { x: 64, y: 152, w: 520, h: 146, size: 55, color: C.white, bold: true });
  const closeSteps = [
    ["01", "Revisamos", "cómo vendes y despachas"],
    ["02", "Configuramos", "puestos, precios y accesos"],
    ["03", "Personalizamos", "los pasos necesarios"],
  ];
  closeSteps.forEach(([num, head, body], i) => {
    const y = 344 + i * 76;
    addText(slide, num, { x: 64, y, w: 44, h: 28, size: 17, color: C.yellow, bold: true, autoFit: "none" });
    addText(slide, head, { x: 122, y: y - 4, w: 226, h: 34, size: 25, color: C.white, bold: true, autoFit: "none" });
    addText(slide, body, { x: 356, y: y, w: 232, h: 34, size: 19, color: C.mutedLight, autoFit: "none" });
  });
  addRule(slide, 64, 583, 150, C.cyan, 5);
  addText(slide, "Gustavo Noriega", { x: 64, y: 610, w: 290, h: 36, size: 27, color: C.white, bold: true, autoFit: "none" });
  addText(slide, "WhatsApp  949 421 023", { x: 64, y: 652, w: 360, h: 36, size: 25, color: C.yellow, bold: true, autoFit: "none" });
  addNotes(slide, [
    `Visual asset: ${ASSETS.closing}`,
    `Brand icon: ${ASSETS.icon}`,
    "Authorship and contact supplied directly by Gustavo Noriega.",
    "Customization language based on verified configuration surfaces and implementation scope.",
  ]);
}

async function writeBlob(filePath, blob) {
  await fs.writeFile(filePath, new Uint8Array(await blob.arrayBuffer()));
}

async function main() {
  await fs.mkdir(RENDER_DIR, { recursive: true });
  await fs.mkdir(LAYOUT_DIR, { recursive: true });
  await fs.mkdir(path.dirname(FINAL_PPTX), { recursive: true });

  const presentation = Presentation.create({ slideSize: { width: 1280, height: 720 } });
  await slide01(presentation);
  await slide02(presentation);
  await slide03(presentation);
  await slide04(presentation);
  await slide05(presentation);
  await slide06(presentation);
  await slide07(presentation);
  await slide08(presentation);
  await slide09(presentation);
  await slide10(presentation);
  await slide11(presentation);
  await slide12(presentation);
  await slide13(presentation);

  for (const [index, slide] of presentation.slides.items.entries()) {
    const stem = `slide-${String(index + 1).padStart(2, "0")}`;
    const png = await presentation.export({ slide, format: "png", scale: 2 });
    await writeBlob(path.join(RENDER_DIR, `${stem}.png`), png);
    const layout = await slide.export({ format: "layout" });
    await fs.writeFile(path.join(LAYOUT_DIR, `${stem}.layout.json`), await layout.text(), "utf8");
  }

  const montage = await presentation.export({ format: "webp", montage: true, scale: 0.5 });
  await writeBlob(path.join(TMP_DIR, "deck-montage.webp"), montage);
  const pptx = await PresentationFile.exportPptx(presentation);
  await pptx.save(FINAL_PPTX);
  const inspect = await presentation.inspect({ kind: "slide,textbox,shape,image,table,chart,notes", maxChars: 200000 });
  await fs.writeFile(path.join(TMP_DIR, "deck-inspect.ndjson"), inspect.ndjson, "utf8");
  console.log(JSON.stringify({ finalPptx: FINAL_PPTX, slides: presentation.slides.items.length, renderDir: RENDER_DIR }));
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
