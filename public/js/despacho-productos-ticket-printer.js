import { currencyLabel, escapeHtml, normalizePriceMode } from "./despacho-productos-despacho-utils.js";

function ticketData(response) {
  return response?.data?.ticket || response?.data || response?.ticket || response || {};
}

function ticketItems(ticket) {
  return ticket.weighings || ticket.pesadas || ticket.items || [];
}

function displayName(item) {
  const product = item.product?.name || item.product_name || item.nombre_producto || "Producto";
  const variation = item.variation?.name || item.variation_name || item.nombre_variacion || "";
  return variation ? `${product} · ${variation}` : product;
}

function itemAmount(item) {
  return item.amount ?? item.total ?? item.importe ?? 0;
}

function decimal(value, places) {
  const number = Number(value || 0);
  return (Number.isFinite(number) ? number : 0).toLocaleString("es-PE", {
    minimumFractionDigits: places,
    maximumFractionDigits: places,
    useGrouping: false
  });
}

function priceUnit(mode) {
  return normalizePriceMode(mode) === "POR_UNIDAD" ? "/und" : "/kg";
}

function dateParts(value, timezone) {
  const date = new Date(value || Date.now());
  const safeDate = Number.isNaN(date.getTime()) ? new Date() : date;
  let options = {};

  if (timezone) {
    try {
      new Intl.DateTimeFormat("es-PE", { timeZone: timezone }).format(safeDate);
      options = { timeZone: timezone };
    } catch {
      options = {};
    }
  }

  return {
    date: new Intl.DateTimeFormat("es-PE", {
      ...options,
      day: "2-digit",
      month: "2-digit",
      year: "numeric"
    }).format(safeDate),
    time: new Intl.DateTimeFormat("es-PE", {
      ...options,
      hour: "2-digit",
      minute: "2-digit",
      hour12: false
    }).format(safeDate)
  };
}

function ticketTotals(ticket, items) {
  const source = ticket.totals || ticket.totales || {};
  return {
    amount: source.amount
      ?? source.total
      ?? ticket.total
      ?? items.reduce((sum, item) => sum + Number(itemAmount(item)), 0)
  };
}

function summaryRows(items) {
  return items.map((item) => `
    <tr>
      <td class="product">${escapeHtml(displayName(item))}</td>
      <td class="number">${Number(item.quantity || 0)}</td>
      <td class="number">${decimal(item.net_weight_kg, 3)}</td>
    </tr>`).join("");
}

function detailRows(items) {
  return items.map((item) => `
    <tr>
      <td class="product">${escapeHtml(displayName(item))}</td>
      <td class="number">${Number(item.quantity || 0)}</td>
      <td class="number">${decimal(item.net_weight_kg, 3)}</td>
      <td class="number price">${decimal(item.unit_price, 2)}<small>${priceUnit(item.price_mode)}</small></td>
      <td class="number strong">${decimal(itemAmount(item), 2)}</td>
    </tr>`).join("");
}

function printableHtml(response, options = {}) {
  const ticket = ticketData(response);
  const items = ticketItems(ticket);
  const totals = ticketTotals(ticket, items);
  const currency = currencyLabel(ticket.currency || ticket.moneda || options.currency || "S/");
  const title = ticket.product_ticket_title
    || ticket.ticket_title
    || options.productTicketTitle
    || options.ticketTitle
    || "DESPACHO DE PRODUCTOS";
  const customer = ticket.client?.name
    || ticket.cliente?.name
    || ticket.customer_label
    || ticket.client_label
    || ticket.tipo_cliente_label
    || "Venta al público";
  const listNumber = Number(ticket.list_number ?? ticket.numero_lista ?? options.listNumber);
  const listSuffix = Number.isInteger(listNumber) && listNumber > 0 ? ` - ${listNumber}` : "";
  const footerMessage = String(
    ticket.ticket_message
    || ticket.message
    || options.ticketMessage
    || ""
  ).trim();
  const registered = ticket.registered_at || ticket.fecha_registro || new Date().toISOString();
  const when = dateParts(registered, options.timezone);
  const summaryEmptyRow = '<tr><td colspan="3" class="empty">Sin detalle</td></tr>';
  const detailEmptyRow = '<tr><td colspan="5" class="empty">Sin detalle</td></tr>';

  return `<!doctype html>
  <html lang="es"><head><meta charset="utf-8"><title>${escapeHtml(ticket.code || "Ticket")}</title>
  <style>
    @page{size:80mm auto;margin:3mm}
    *{box-sizing:border-box}
    body{width:74mm;margin:0;color:#111;font:10.5px/1.25 ui-monospace,"Cascadia Mono",Consolas,monospace}
    h1{margin:0;text-align:center;font-size:16px;line-height:1.12;white-space:pre-line;overflow-wrap:anywhere}
    .subtitle{text-align:center;font-size:12px;font-weight:800;margin:3px 0 12px}
    .control{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:2px 8px;align-items:baseline}
    .control strong{font-size:12px}.code{text-align:right;overflow-wrap:anywhere}
    .date-label{margin-top:5px}.time{text-align:right}
    .sale{text-align:center;font-weight:800;font-size:12px;margin:10px 0 3px;overflow-wrap:anywhere}
    table{width:100%;table-layout:fixed;border-collapse:collapse}
    th{padding:3px 2px;border-top:1px solid #111;border-bottom:1px solid #111;font-size:9px;white-space:nowrap}
    td{padding:3px 2px;vertical-align:top;overflow-wrap:anywhere}
    .summary th:nth-child(1),.summary td:nth-child(1){width:56%}
    .summary th:nth-child(2),.summary td:nth-child(2){width:16%}
    .summary th:nth-child(3),.summary td:nth-child(3){width:28%}
    .details{margin-top:13px}
    .details th:nth-child(1),.details td:nth-child(1){width:34%}
    .details th:nth-child(2),.details td:nth-child(2){width:11%}
    .details th:nth-child(3),.details td:nth-child(3){width:20%}
    .details th:nth-child(4),.details td:nth-child(4){width:17%}
    .details th:nth-child(5),.details td:nth-child(5){width:18%}
    .product{text-align:left;font-weight:700}.number{text-align:right}.strong{font-weight:800}
    .price small{display:block;font-size:7px;font-weight:400}.empty{text-align:center;padding:12px 0}
    .total{display:flex;justify-content:flex-end;gap:18px;margin-top:10px;padding-top:5px;border-top:1px solid #111;font-size:14px;font-weight:900}
    .observ{min-height:28px;margin-top:24px;font-size:11px;font-weight:800;border-bottom:1px solid #111}
    .footer{margin:14px 0 0;text-align:center;white-space:pre-line;overflow-wrap:anywhere}
    @media print{body{width:auto}}
  </style></head><body>
    <h1>${escapeHtml(title)}</h1>
    <div class="subtitle">CONTROL DE DESPACHO</div>
    <section class="control">
      <strong>CONTROL DE PESO</strong>
      <strong class="code">${escapeHtml(ticket.code || ticket.codigo || "-")}</strong>
      <span class="date-label">FECHA ${escapeHtml(when.date)}</span>
      <span class="time">${escapeHtml(when.time)}</span>
    </section>
    <p class="sale">${escapeHtml(String(customer).toLocaleUpperCase("es"))}${escapeHtml(listSuffix)}</p>
    <table class="summary">
      <thead><tr><th>PROD.</th><th>CANT.</th><th>P. NETO</th></tr></thead>
      <tbody>${summaryRows(items) || summaryEmptyRow}</tbody>
    </table>
    <table class="details">
      <thead><tr><th>PRODUCTO</th><th>C/A</th><th>P. NETO</th><th>PRECIO</th><th>TOTAL</th></tr></thead>
      <tbody>${detailRows(items) || detailEmptyRow}</tbody>
    </table>
    <div class="total"><span>TOT ${escapeHtml(currency)}</span><span>${decimal(totals.amount, 2)}</span></div>
    <p class="observ">OBSERV:</p>
    ${footerMessage ? `<p class="footer">${escapeHtml(footerMessage)}</p>` : ""}
    <script>window.addEventListener("load",()=>{window.focus();window.print();});<\/script>
  </body></html>`;
}

export function printProductDispatchTicket(response, options = {}) {
  const popup = options.printWindow && !options.printWindow.closed
    ? options.printWindow
    : window.open("", "_blank", "popup=yes,width=420,height=720");
  if (!popup) {
    throw new Error("El navegador bloqueó la ventana de impresión. Habilita las ventanas emergentes e inténtalo nuevamente.");
  }

  popup.document.open();
  popup.document.write(printableHtml(response, options));
  popup.document.close();
  return true;
}

export { printableHtml as buildProductDispatchTicketHtml };
