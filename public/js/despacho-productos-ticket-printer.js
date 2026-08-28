import { escapeHtml, formatMoney, formatWeight, priceModeLabel } from "./despacho-productos-despacho-utils.js";

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

function ticketTotals(ticket, items) {
  const source = ticket.totals || ticket.totales || {};
  return {
    quantity: source.quantity ?? source.cantidad ?? items.reduce((sum, item) => sum + Number(item.quantity || 0), 0),
    readWeight: source.read_weight_kg ?? source.peso_leido_kg ?? items.reduce((sum, item) => sum + Number(item.read_weight_kg || 0), 0),
    waste: source.waste_grams ?? source.waste_total_grams ?? source.merma_gramos ?? items.reduce((sum, item) => sum + Number(item.waste_total_grams || 0), 0),
    netWeight: source.net_weight_kg ?? source.peso_neto_kg ?? items.reduce((sum, item) => sum + Number(item.net_weight_kg || 0), 0),
    amount: source.amount ?? source.total ?? ticket.total ?? items.reduce((sum, item) => sum + Number(itemAmount(item)), 0)
  };
}

function printableHtml(response, options = {}) {
  const ticket = ticketData(response);
  const items = ticketItems(ticket);
  const totals = ticketTotals(ticket, items);
  const currency = ticket.currency || ticket.moneda || options.currency || "S/";
  const customer = ticket.client?.name
    || ticket.cliente?.name
    || ticket.customer_label
    || ticket.client_label
    || ticket.tipo_cliente_label
    || "Venta al público";
  const date = ticket.registered_at || ticket.fecha_registro || new Date().toISOString();
  const formattedDate = new Intl.DateTimeFormat("es-PE", {
    dateStyle: "short",
    timeStyle: "short"
  }).format(new Date(date));

  const rows = items.map((item, index) => `
    <tr>
      <td colspan="4" class="name">${index + 1}. ${escapeHtml(displayName(item))}</td>
    </tr>
    <tr class="detail">
      <td>${Number(item.quantity || 0)} und</td>
      <td>${formatWeight(item.net_weight_kg || 0)}</td>
      <td>${escapeHtml(formatMoney(item.unit_price || 0, currency))}<br><small>${escapeHtml(priceModeLabel(item.price_mode))}</small></td>
      <td class="right">${escapeHtml(formatMoney(itemAmount(item), currency))}</td>
    </tr>`).join("");

  return `<!doctype html>
  <html lang="es"><head><meta charset="utf-8"><title>${escapeHtml(ticket.code || "Ticket")}</title>
  <style>
    @page{size:80mm auto;margin:4mm}*{box-sizing:border-box}body{font:12px/1.35 ui-monospace,Consolas,monospace;color:#111;margin:0}
    h1{font-size:17px;margin:0;text-align:center}p{margin:3px 0}.center{text-align:center}.rule{border-top:1px dashed #111;margin:8px 0}
    table{width:100%;border-collapse:collapse}.name{font-weight:700;padding-top:7px}.detail td{padding:2px 1px;vertical-align:top}.right{text-align:right;font-weight:700}
    .totals{display:grid;grid-template-columns:1fr auto;gap:3px 8px}.totals strong{text-align:right}.grand{font-size:17px;border-top:1px solid #111;padding-top:5px;margin-top:3px}
    .footer{text-align:center;margin-top:10px;white-space:pre-line}@media print{button{display:none}}
  </style></head><body>
    <h1>${escapeHtml(ticket.ticket_title || options.ticketTitle || "DESPACHO DE PRODUCTOS")}</h1>
    <p class="center"><strong>${escapeHtml(ticket.code || ticket.codigo || "Ticket confirmado")}</strong></p>
    <p class="center">${escapeHtml(formattedDate)}</p>
    <div class="rule"></div>
    <p><strong>Cliente:</strong> ${escapeHtml(customer)}</p>
    <div class="rule"></div>
    <table><tbody>${rows || '<tr><td>Sin detalle</td></tr>'}</tbody></table>
    <div class="rule"></div>
    <div class="totals">
      <span>Cantidad</span><strong>${Number(totals.quantity || 0)} und</strong>
      <span>Peso leído</span><strong>${escapeHtml(formatWeight(totals.readWeight))}</strong>
      <span>Merma</span><strong>${Number(totals.waste || 0)} g</strong>
      <span>Peso neto</span><strong>${escapeHtml(formatWeight(totals.netWeight))}</strong>
      <span class="grand">TOTAL</span><strong class="grand">${escapeHtml(formatMoney(totals.amount, currency))}</strong>
    </div>
    <p class="footer">${escapeHtml(ticket.message || ticket.ticket_message || options.ticketMessage || "Gracias por su compra")}</p>
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
