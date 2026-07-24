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
  const suppliedReadWeight = Number(record?.readWeight);
  const suppliedNetWeight = Number(record?.netWeight);
  const priceKg = Number(record?.priceKg) || 0;
  const suppliedAmount = Number(record?.amount);
  const readWeight = Number.isFinite(suppliedReadWeight) ? suppliedReadWeight : grossWeight;
  const netWeight = Number.isFinite(suppliedNetWeight) ? suppliedNetWeight : grossWeight - tareWeight;

  return {
    typeCode: String(record?.typeCode || "PV").trim() || "PV",
    birdsPerCage: Math.max(0, Number(record?.birdsPerCage) || 0),
    cages: Math.max(0, Number(record?.cages) || 0),
    birds: Math.max(
      0,
      Number(record?.birds)
      || (Math.max(0, Number(record?.birdsPerCage) || 0) * Math.max(0, Number(record?.cages) || 0))
    ),
    readWeight,
    grossWeight,
    tareWeight,
    netWeight,
    adjustmentWeight: grossWeight - readWeight,
    priceKg,
    amount: Number.isFinite(suppliedAmount) ? suppliedAmount : netWeight * priceKg
  };
}

function summarizeTicketRecords(records) {
  const totalsByType = new Map();

  records.forEach((record) => {
    const current = totalsByType.get(record.typeCode) || {
      typeCode: record.typeCode,
      birds: 0,
      cages: 0,
      readWeight: 0,
      grossWeight: 0,
      tareWeight: 0,
      netWeight: 0,
      adjustmentWeight: 0,
      priceKg: record.priceKg,
      amount: 0
    };

    current.birds += record.birds;
    current.cages += record.cages;
    current.readWeight += record.readWeight;
    current.grossWeight += record.grossWeight;
    current.tareWeight += record.tareWeight;
    current.netWeight += record.netWeight;
    current.adjustmentWeight += record.adjustmentWeight;
    current.priceKg = record.priceKg;
    current.amount += record.amount;
    totalsByType.set(record.typeCode, current);
  });

  return Array.from(totalsByType.values());
}

function formatTicketMoney(value) {
  return `S/ ${Number(value || 0).toFixed(2)}`;
}

function formatTicketNumber(value, decimals = 2) {
  return Number(value || 0).toLocaleString("en-US", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals
  });
}

function formatTicketDate(operatingDate, fallbackDate) {
  const dateParts = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(operatingDate || ""));
  if (dateParts) {
    return `${dateParts[3]}/${dateParts[2]}/${dateParts[1]}`;
  }

  return fallbackDate.toLocaleDateString(TICKET_LOCALE, {
    timeZone: TICKET_TIME_ZONE,
    day: "2-digit",
    month: "2-digit",
    year: "numeric"
  });
}

function buildRetailWeightControlTicketHtml(ticket, safePrintDate, records, isReturn) {
  const ticketCode = String(ticket?.code || "--");
  const destinationName = String(ticket?.destinationName || "Venta externa");
  const deliveryVehicle = ticket?.delivery?.vehicle || null;
  const deliveryDriver = ticket?.delivery?.driver || null;
  const hasAssignedTransport = Boolean(deliveryVehicle || deliveryDriver);
  const totalBaseWeight = records.reduce(
    (total, record) => total + record.readWeight - record.tareWeight,
    0
  );
  const totalBirds = records.reduce((total, record) => total + record.birds, 0);
  const totalAdjustmentWeight = records.reduce(
    (total, record) => total + record.adjustmentWeight,
    0
  );
  const totalNetWeight = records.reduce((total, record) => total + record.netWeight, 0);
  const suppliedTotalAmount = Number(ticket?.totalAmount);
  const totalAmount = Number.isFinite(suppliedTotalAmount)
    ? suppliedTotalAmount
    : records.reduce((total, record) => total + record.amount, 0);
  const distinctPrices = [...new Set(
    records.map((record) => Number(record.priceKg || 0).toFixed(2))
  )];
  const priceLabel = distinctPrices.length === 1
    ? formatTicketNumber(distinctPrices[0])
    : (distinctPrices.length > 1 ? "VARIOS" : "0.00");
  const printedDate = formatTicketDate(ticket?.operatingDate, safePrintDate);
  const rows = records.map((record) => `
    <tr>
      <td>${escapeTicketHtml(record.typeCode)}</td>
      <td class="number">${record.birds}</td>
      <td class="number">${record.cages}</td>
      <td class="number">${formatTicketNumber(record.readWeight)}</td>
      <td class="number">${formatTicketNumber(record.tareWeight)}</td>
      <td class="control-cell">&nbsp;</td>
    </tr>
  `).join("");
  const deliveryHtml = hasAssignedTransport
    ? `<section class="delivery">
        ${deliveryVehicle ? `<p>CAMIÓN: ${escapeTicketHtml(deliveryVehicle.plate || "--")}</p>` : ""}
        ${deliveryDriver ? `<p>CHOFER: ${escapeTicketHtml(deliveryDriver.name || "--")}</p>` : ""}
      </section>`
    : "";

  return `<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>${escapeTicketHtml(ticketCode)} - CONTROL DE PESO</title>
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
      padding: 2.5mm 1.5mm 12mm;
      font-family: "Courier New", Courier, monospace;
      font-size: 15px;
      font-weight: 700;
      line-height: 1.15;
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

    .business-distributor {
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    .business-name {
      margin-top: 0.4mm;
      font-size: 23px;
      font-weight: 900;
      line-height: 1;
      letter-spacing: 0.4px;
    }

    .business-product {
      margin-top: 0.5mm;
      font-size: 19px;
      font-weight: 900;
      letter-spacing: 0.7px;
    }

    .business-mark {
      margin-top: 3.5mm;
      font-size: 18px;
      font-weight: 900;
    }

    .document-title {
      margin-top: 3.5mm;
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 2mm;
      font-size: 16px;
      font-weight: 900;
    }

    .date {
      margin-top: 1.8mm;
      font-size: 15px;
    }

    .operation-note {
      margin-top: 1.5mm;
      text-align: center;
      font-size: 16px;
      font-weight: 900;
    }

    .destination {
      margin: 7mm 0 1.3mm;
      text-align: center;
      font-size: 20px;
      font-weight: 900;
      overflow-wrap: anywhere;
    }

    .delivery {
      margin: 1.8mm 0 2mm;
      font-size: 14.5px;
      font-weight: 900;
    }

    .delivery p + p {
      margin-top: 0.6mm;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    th,
    td {
      overflow: hidden;
      text-overflow: clip;
    }

    .detail-table th {
      padding: 0.8mm 0.25mm;
      border-top: 1.4px solid #000;
      border-right: 1px solid #000;
      border-bottom: 1.4px solid #000;
      font-size: 11.5px;
      font-weight: 900;
      line-height: 1.05;
      text-align: center;
      white-space: normal;
    }

    .detail-table th:first-child {
      border-left: 1px solid #000;
    }

    .detail-table td {
      padding: 1.3mm 0.35mm 0.5mm;
      font-size: 15.5px;
      font-weight: 700;
      white-space: nowrap;
    }

    .detail-table td:first-child {
      text-align: left;
      font-weight: 900;
    }

    .detail-table .number {
      text-align: right;
    }

    .detail-table .control-cell {
      text-align: center;
    }

    .detail-table th:nth-child(1) { width: 12%; }
    .detail-table th:nth-child(2) { width: 10%; }
    .detail-table th:nth-child(3) { width: 10%; }
    .detail-table th:nth-child(4) { width: 22%; }
    .detail-table th:nth-child(5) { width: 22%; }
    .detail-table th:nth-child(6) { width: 24%; }

    .retail-summary-stack {
      width: 64%;
      margin: 7mm 0 0 auto;
    }

    .retail-summary-table + .retail-summary-table {
      margin-top: 1.3mm;
    }

    .retail-summary-table th {
      padding: 0.8mm 0.25mm;
      border-top: 1.2px solid #000;
      border-bottom: 1.2px solid #000;
      font-size: 13.5px;
      font-weight: 900;
      text-align: center;
      white-space: nowrap;
    }

    .retail-summary-table td {
      padding: 1.2mm 0.25mm 0.7mm;
      border-bottom: 1.2px solid #000;
      font-size: 14.5px;
      font-weight: 700;
      text-align: right;
      white-space: nowrap;
    }

    .retail-summary-table td:nth-child(2) {
      text-align: center;
    }

    .retail-summary-table th:nth-child(1),
    .retail-summary-table td:nth-child(1) {
      width: 34%;
    }

    .retail-summary-table th:nth-child(2),
    .retail-summary-table td:nth-child(2) {
      width: 26%;
    }

    .retail-summary-table th:nth-child(3),
    .retail-summary-table td:nth-child(3) {
      width: 40%;
    }

    .retail-summary-table .price-various {
      font-size: 12.5px;
      font-weight: 900;
    }

    .form-fields {
      margin-top: 30mm;
      display: grid;
      gap: 12mm;
      font-size: 16px;
      font-weight: 900;
    }
  </style>
</head>
<body class="retail-ticket">
  <header class="center">
    <p class="business-distributor">DISTRIBUIDORA</p>
    <h1 class="business-name">DIEGO ALBERTO</h1>
    <p class="business-product">GALLINA</p>
    <p class="business-mark">GD</p>
  </header>

  <h2 class="document-title">
    <span>CONTROL DE PESO</span>
    <span>${escapeTicketHtml(ticketCode)}</span>
  </h2>

  <p class="date">FECHA ${escapeTicketHtml(printedDate)}</p>
  ${isReturn ? '<p class="operation-note">DEVOLUCIÓN MINORISTA</p>' : ""}

  <p class="destination">${escapeTicketHtml(destinationName.toLocaleUpperCase(TICKET_LOCALE))}</p>
  ${deliveryHtml}

  <table class="detail-table retail-detail-table">
    <thead>
      <tr>
        <th>TIPO</th>
        <th>C/A</th>
        <th>C.J</th>
        <th>PESO<br>BRUTO</th>
        <th>PESO<br>TARA</th>
        <th>CONTROL<br>PESO</th>
      </tr>
    </thead>
    <tbody>${rows}</tbody>
  </table>

  <section class="retail-summary-stack">
    <table class="retail-summary-table retail-weight-summary">
      <thead>
        <tr><th>PESO</th><th>AVES</th><th>MERM</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>${formatTicketNumber(totalBaseWeight)}</td>
          <td>${totalBirds}</td>
          <td>${formatTicketNumber(totalAdjustmentWeight)}</td>
        </tr>
      </tbody>
    </table>

    <table class="retail-summary-table retail-sale-summary">
      <thead>
        <tr><th>P.NETO</th><th>PRE.</th><th>SOLES</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>${formatTicketNumber(totalNetWeight)}</td>
          <td class="${distinctPrices.length > 1 ? "price-various" : ""}">${escapeTicketHtml(priceLabel)}</td>
          <td>${formatTicketNumber(totalAmount)}</td>
        </tr>
      </tbody>
    </table>
  </section>

  <section class="form-fields">
    <p>OBSERV:</p>
    <p>NOMBRE:</p>
    <p>FIRMA:</p>
  </section>
</body>
</html>`;
}

export function buildWeightControlTicketHtml(ticket, emittedAt = null) {
  const requestedPrintDate = emittedAt || ticket?.emittedAt;
  const printDate = requestedPrintDate ? new Date(requestedPrintDate) : new Date();
  const safePrintDate = Number.isNaN(printDate.getTime()) ? new Date() : printDate;
  const records = (ticket?.records || []).map(normalizeTicketRecord);
  const typeTotals = summarizeTicketRecords(records);
  const isReturn = ticket?.operationType === "DEVOLUCION";
  const isRetail = ticket?.channel === "MINORISTA";

  if (isRetail) {
    return buildRetailWeightControlTicketHtml(ticket, safePrintDate, records, isReturn);
  }

  const customerKind = ticket?.customerKind === "VENTA_EXTERNA" ? "VENTA EXTERNA" : "CLIENTE REGISTRADO";
  const documentTitle = isRetail
    ? (isReturn ? "DEVOLUCION MINORISTA" : "DESPACHO MINORISTA")
    : (isReturn ? "DEVOLUCION" : "CONTROL DE PESO");
  const rows = records.map((record) => `
    <tr>
      <td>${escapeTicketHtml(record.typeCode)}</td>
      ${isRetail ? `
        <td class="number">${record.birds}</td>
        <td class="number">${record.netWeight.toFixed(2)}</td>
        <td class="number">${escapeTicketHtml(formatTicketMoney(record.priceKg))}</td>
        <td class="number">${escapeTicketHtml(formatTicketMoney(record.amount))}</td>
      ` : `
        <td class="number">${record.birdsPerCage}</td>
        <td class="number">${record.cages}</td>
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
  const deliveryMode = String(ticket?.delivery?.mode || "");
  const deliveryVehicle = ticket?.delivery?.vehicle || null;
  const deliveryDriver = ticket?.delivery?.driver || null;
  const customerPickup = deliveryMode === "CUSTOMER_PICKUP";
  const deliveryHtml = customerPickup || deliveryVehicle || deliveryDriver
    ? `<section class="delivery">
        ${customerPickup ? "<p>TRANSPORTE: RETIRO DIRECTO POR EL CLIENTE</p>" : ""}
        ${!customerPickup && deliveryVehicle ? `<p>CAMIÓN: ${escapeTicketHtml(deliveryVehicle.plate || "--")}</p>` : ""}
        ${!customerPickup && deliveryDriver ? `<p>CHOFER: ${escapeTicketHtml(deliveryDriver.name || "--")}</p>` : ""}
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
      padding: 3mm 1mm 8mm;
      font-family: "Courier New", Courier, monospace;
      font-size: 18px;
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
      font-size: 22px;
      font-weight: 900;
      line-height: 1.15;
    }

    .document-title {
      margin-top: 4mm;
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 2mm;
      font-size: 15.5px;
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
      font-size: 18px;
      font-weight: 900;
    }

    .delivery {
      margin: 2mm 0;
      padding: 1.5mm 0;
      border-top: 1px dashed #000;
      border-bottom: 1px dashed #000;
      font-size: 17px;
      font-weight: 900;
    }

    .delivery p + p {
      margin-top: 0.7mm;
    }

    .channel {
      margin-top: 1mm;
      text-align: center;
      font-size: 14px;
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
      font-size: 14px;
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
      font-size: 17px;
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
    }

    .retail-detail-table th:nth-child(1) { width: 13%; }
    .retail-detail-table th:nth-child(2) { width: 15%; }
    .retail-detail-table th:nth-child(3) { width: 18%; }
    .retail-detail-table th:nth-child(4) { width: 26%; }
    .retail-detail-table th:nth-child(5) { width: 28%; }

    .summary-title {
      margin: 4mm 0 1mm;
      font-size: 19px;
      font-weight: 900;
    }

    .summary-table th,
    .summary-table td {
      text-align: right;
      font-size: 15.5px;
    }

    .summary-table th:first-child,
    .summary-table td:first-child {
      text-align: left;
    }

    .form-fields {
      margin-top: 5mm;
      display: grid;
      gap: 8mm;
      font-size: 19px;
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
      font-size: 19px;
      font-weight: 900;
    }
  </style>
</head>
<body class="${isRetail ? "retail-ticket" : "wholesale-ticket"}">
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
          ? "<th>TIPO</th><th>POLLOS</th><th>NETO<br>KG</th><th>PRECIO<br>/KG</th><th>SUBTOTAL</th>"
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
