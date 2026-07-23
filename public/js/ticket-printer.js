const TICKET_LOCALE = "es-PE";
const TICKET_TIME_ZONE = "America/Lima";

function escapeTicketHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function normalizeTicketRecord(record) {
  const grossWeight = Number(record?.grossWeight) || 0;
  const tareWeight = Number(record?.tareWeight) || 0;
  const suppliedNetWeight = Number(record?.netWeight);
  const priceKg = Number(record?.priceKg) || 0;
  const suppliedAmount = Number(record?.amount);
  const netWeight = Number.isFinite(suppliedNetWeight) ? suppliedNetWeight : grossWeight - tareWeight;

  return {
    typeCode: String(record?.typeCode || "PV").trim() || "PV",
    birdsPerCage: Math.max(0, Number(record?.birdsPerCage) || 0),
    cages: Math.max(0, Number(record?.cages) || 0),
    grossWeight,
    tareWeight,
    netWeight,
    priceKg,
    amount: Number.isFinite(suppliedAmount) ? suppliedAmount : netWeight * priceKg
  };
}

function summarizeTicketRecords(records) {
  const totalsByType = new Map();

  records.forEach((record) => {
    const current = totalsByType.get(record.typeCode) || {
      typeCode: record.typeCode,
      grossWeight: 0,
      tareWeight: 0,
      netWeight: 0,
      priceKg: record.priceKg,
      amount: 0
    };

    current.grossWeight += record.grossWeight;
    current.tareWeight += record.tareWeight;
    current.netWeight += record.netWeight;
    current.priceKg = record.priceKg;
    current.amount += record.amount;
    totalsByType.set(record.typeCode, current);
  });

  return Array.from(totalsByType.values());
}

function formatTicketMoney(value) {
  return `S/ ${Number(value || 0).toFixed(2)}`;
}

export function buildWeightControlTicketHtml(ticket, emittedAt = null) {
  const requestedPrintDate = emittedAt || ticket?.emittedAt;
  const printDate = requestedPrintDate ? new Date(requestedPrintDate) : new Date();
  const safePrintDate = Number.isNaN(printDate.getTime()) ? new Date() : printDate;
  const records = (ticket?.records || []).map(normalizeTicketRecord);
  const typeTotals = summarizeTicketRecords(records);
  const isReturn = ticket?.operationType === "DEVOLUCION";
  const isRetail = ticket?.channel === "MINORISTA";
  const customerKind = ticket?.customerKind === "VENTA_EXTERNA" ? "VENTA EXTERNA" : "CLIENTE REGISTRADO";
  const documentTitle = isRetail
    ? (isReturn ? "DEVOLUCION MINORISTA" : "DESPACHO MINORISTA")
    : (isReturn ? "DEVOLUCION" : "CONTROL DE PESO");
  const rows = records.map((record) => `
    <tr>
      <td>${escapeTicketHtml(record.typeCode)}</td>
      <td class="number">${record.birdsPerCage}</td>
      <td class="number">${record.cages}</td>
      ${isRetail ? `
        <td class="number">${record.netWeight.toFixed(2)}</td>
        <td class="number">${escapeTicketHtml(formatTicketMoney(record.priceKg))}</td>
        <td class="number">${escapeTicketHtml(formatTicketMoney(record.amount))}</td>
      ` : `
        <td class="number">${record.grossWeight.toFixed(2)}</td>
        <td class="number">${record.tareWeight.toFixed(2)}</td>
      `}
    </tr>
  `).join("");
  const totalRows = typeTotals.map((total) => `
    <tr>
      <td>${escapeTicketHtml(total.typeCode)}</td>
      ${isRetail ? `
        <td>${total.netWeight.toFixed(2)}</td>
        <td>${escapeTicketHtml(formatTicketMoney(total.priceKg))}</td>
        <td>${escapeTicketHtml(formatTicketMoney(total.amount))}</td>
      ` : `
        <td>${total.grossWeight.toFixed(2)}</td>
        <td>${total.tareWeight.toFixed(2)}</td>
        <td>${total.netWeight.toFixed(2)}</td>
      `}
    </tr>
  `).join("");
  const suppliedTotalAmount = Number(ticket?.totalAmount);
  const totalAmount = Number.isFinite(suppliedTotalAmount)
    ? suppliedTotalAmount
    : records.reduce((total, record) => total + record.amount, 0);
  const ticketCode = String(ticket?.code || "--");
  const destinationName = String(ticket?.destinationName || "Sin destino asignado");
  const deliveryVehicle = ticket?.delivery?.vehicle || null;
  const deliveryDriver = ticket?.delivery?.driver || null;
  const deliveryHtml = deliveryVehicle || deliveryDriver
    ? `<section class="delivery">
        ${deliveryVehicle ? `<p>CAMIÓN: ${escapeTicketHtml(deliveryVehicle.plate || "--")}</p>` : ""}
        ${deliveryDriver ? `<p>CHOFER: ${escapeTicketHtml(deliveryDriver.name || "--")}</p>` : ""}
      </section>`
    : "";
  const printedDate = safePrintDate.toLocaleDateString(TICKET_LOCALE, { timeZone: TICKET_TIME_ZONE });
  const printedTime = safePrintDate.toLocaleTimeString(TICKET_LOCALE, { timeZone: TICKET_TIME_ZONE });

  return `<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>${escapeTicketHtml(ticketCode)} - ${escapeTicketHtml(documentTitle)}</title>
  <style>
    @page {
      size: auto;
      margin: 0;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      margin: 0;
      padding: 0;
      background: #fff;
      color: #000;
    }

    body {
      width: 76mm;
      margin: 0 auto;
      padding: 3mm 2.5mm 8mm;
      font-family: "Courier New", Courier, monospace;
      font-size: 13px;
      font-weight: 700;
      line-height: 1.25;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    h1,
    h2,
    p {
      margin: 0;
    }

    .center {
      text-align: center;
    }

    .business-name {
      font-size: 17px;
      font-weight: 900;
      line-height: 1.15;
    }

    .document-title {
      margin-top: 4mm;
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 2mm;
      font-size: 14px;
      font-weight: 900;
    }

    .info {
      margin-top: 1.5mm;
    }

    .info p {
      margin-bottom: 0.5mm;
    }

    .destination {
      margin: 3mm 0 1mm;
      text-align: center;
      font-size: 13.5px;
      font-weight: 900;
    }

    .delivery {
      margin: 2mm 0;
      padding: 1.5mm 0;
      border-top: 1px dashed #000;
      border-bottom: 1px dashed #000;
      font-size: 12.5px;
      font-weight: 900;
    }

    .delivery p + p {
      margin-top: 0.7mm;
    }

    .channel {
      margin-top: 1mm;
      text-align: center;
      font-size: 11px;
      font-weight: 900;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    th,
    td {
      padding: 0.9mm 0.35mm;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: clip;
    }

    th {
      border-top: 1.5px solid #000;
      border-bottom: 1.5px solid #000;
      font-size: 10.5px;
      font-weight: 900;
      text-align: right;
      white-space: normal;
      line-height: 1.05;
    }

    th:first-child,
    td:first-child {
      text-align: left;
    }

    td {
      font-size: 12.5px;
      font-weight: 700;
    }

    .number {
      text-align: right;
    }

    .detail-table th:nth-child(1) { width: 14%; }
    .detail-table th:nth-child(2) { width: 13%; }
    .detail-table th:nth-child(3) { width: 12%; }
    .detail-table th:nth-child(4) { width: 31%; }
    .detail-table th:nth-child(5) { width: 30%; }

    .retail-detail-table th,
    .retail-detail-table td {
      padding-left: 0.2mm;
      padding-right: 0.2mm;
      font-size: 9.5px;
    }

    .retail-detail-table th:nth-child(1) { width: 11%; }
    .retail-detail-table th:nth-child(2) { width: 11%; }
    .retail-detail-table th:nth-child(3) { width: 10%; }
    .retail-detail-table th:nth-child(4) { width: 18%; }
    .retail-detail-table th:nth-child(5) { width: 24%; }
    .retail-detail-table th:nth-child(6) { width: 26%; }

    .summary-title {
      margin: 4mm 0 1mm;
      font-size: 14px;
      font-weight: 900;
    }

    .summary-table th,
    .summary-table td {
      text-align: right;
      font-size: 11.5px;
    }

    .summary-table th:first-child,
    .summary-table td:first-child {
      text-align: left;
    }

    .form-fields {
      margin-top: 5mm;
      display: grid;
      gap: 8mm;
      font-size: 14px;
      font-weight: 900;
    }

    .sale-total {
      margin-top: 3mm;
      padding: 2mm 0;
      border-top: 2px solid #000;
      border-bottom: 2px solid #000;
      display: flex;
      justify-content: space-between;
      gap: 2mm;
      font-size: 16px;
      font-weight: 900;
    }
  </style>
</head>
<body>
  <header class="center">
    <h1 class="business-name">DISTRIBUIDORA<br>DIEGO ALBERTO</h1>
  </header>

  <h2 class="document-title">
    <span>${escapeTicketHtml(documentTitle)}</span>
    <span>${escapeTicketHtml(ticketCode)}</span>
  </h2>

  <section class="info">
    <p>FECHA ${escapeTicketHtml(printedDate)}</p>
    <p>${escapeTicketHtml(printedTime)}</p>
  </section>

  <p class="destination">${escapeTicketHtml(destinationName)}</p>
  ${isRetail ? `<p class="channel">DESPACHO MINORISTA · ${escapeTicketHtml(customerKind)}</p>` : ""}
  ${deliveryHtml}

  <table class="detail-table${isRetail ? " retail-detail-table" : ""}">
    <thead>
      <tr>
        ${isRetail
          ? "<th>TIPO</th><th>AV/B</th><th>BAN</th><th>NETO<br>KG</th><th>PRECIO<br>/KG</th><th>SUBTOTAL</th>"
          : "<th>TIPO</th><th>C/A</th><th>CJ</th><th>PESO<br>BRUTO</th><th>PESO<br>TARA</th>"}
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>

  <p class="summary-title">TOTAL X</p>
  <table class="summary-table">
    <thead>
      <tr>
        <th>TIPO</th>
        ${isRetail
          ? "<th>NETO KG</th><th>PRECIO/KG</th><th>SUBTOTAL</th>"
          : "<th>PB</th><th>TARA</th><th>PN</th>"}
      </tr>
    </thead>
    <tbody>${totalRows}</tbody>
  </table>

  ${isRetail ? `
    <p class="sale-total">
      <span>TOTAL TICKET</span>
      <span>${escapeTicketHtml(formatTicketMoney(totalAmount))}</span>
    </p>
  ` : ""}

  <section class="form-fields">
    <p>OBSERV:</p>
    <p>NOMBRE:</p>
    <p>FIRMA:</p>
  </section>
</body>
</html>`;
}

export function printWeightControlTicket(ticket, options = {}) {
  const printFrame = document.createElement("iframe");
  let cleanupTimer = null;

  printFrame.className = "ticket-print-frame";
  printFrame.title = options.frameTitle || `Impresión de ${ticket?.code || "ticket"}`;
  printFrame.setAttribute("aria-hidden", "true");
  printFrame.addEventListener("load", () => {
    const printWindow = printFrame.contentWindow;

    if (!printWindow) {
      printFrame.remove();
      options.onError?.();
      return;
    }

    const cleanup = () => {
      if (cleanupTimer) {
        window.clearTimeout(cleanupTimer);
      }
      printFrame.remove();
    };

    printWindow.addEventListener("afterprint", cleanup, { once: true });
    cleanupTimer = window.setTimeout(cleanup, 60000);

    window.setTimeout(() => {
      try {
        printWindow.focus();
        printWindow.print();
        options.onSuccess?.();
      } catch {
        cleanup();
        options.onError?.();
      }
    }, 150);
  }, { once: true });

  printFrame.srcdoc = buildWeightControlTicketHtml(ticket);
  document.body.appendChild(printFrame);
}
