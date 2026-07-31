function escapePrintHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

const PRINT_COLUMN_LABELS = Object.freeze({
  "Num. javas": "Javas",
  "Cant. aves": "Aves",
  "Peso bruto": "P. bruto",
  Devoluciones: "Dev.",
  "Peso neto": "P. neto"
});

function formatPrintWeight(value) {
  const numericValue = Number(value);
  return Number.isFinite(numericValue) ? numericValue.toFixed(2) : "--";
}

function formatPrintPrice(value) {
  const normalized = String(value ?? "").trim();

  if (normalized === "VARIOS" || normalized === "SIN PRECIO") {
    return normalized;
  }

  if (!normalized) return "--";

  const numericValue = Number(normalized);
  return Number.isFinite(numericValue) ? numericValue.toFixed(2) : "--";
}

function formatPrintAmount(value) {
  const normalized = String(value ?? "").trim();
  if (!normalized) return "--";

  const numericValue = Number(normalized);
  return Number.isFinite(numericValue) ? numericValue.toFixed(2) : "--";
}

export function buildDailySummaryPrintTableHtml(tableHtml) {
  return String(tableHtml || "")
    .replace(/<th([^>]*)>([^<]*)<\/th>/g, (match, attributes, label) => {
      const shortLabel = PRINT_COLUMN_LABELS[label.trim()] || label;
      return `<th${attributes}>${shortLabel}</th>`;
    })
    .replace(/<td([^>]*\sdata-print-weight="([^"]*)"[^>]*)>[\s\S]*?<\/td>/g, (match, attributes, value) => {
      const cleanAttributes = attributes.replace(/\sdata-print-weight="[^"]*"/, "");
      return `<td${cleanAttributes}>${formatPrintWeight(value)}</td>`;
    })
    .replace(/<tr([^>]*\sdata-print-price="[^"]*"[^>]*)>([\s\S]*?)<\/tr>/g, (match, attributes, cells) => {
      const price = attributes.match(/\sdata-print-price="([^"]*)"/)?.[1] || "";
      const amount = attributes.match(/\sdata-print-amount="([^"]*)"/)?.[1] || "";
      const cleanAttributes = attributes
        .replace(/\sdata-print-price="[^"]*"/, "")
        .replace(/\sdata-print-amount="[^"]*"/, "");

      return `<tr${cleanAttributes}>${cells}<td class="daily-client-price">${formatPrintPrice(price)}</td><td class="daily-client-amount">${formatPrintAmount(amount)}</td></tr>`;
    })
    .replace(
      /(<thead[^>]*>[\s\S]*?<tr[^>]*>[\s\S]*?)(<\/tr>)/,
      "$1<th>Precio</th><th>Importe</th>$2"
    )
    .replace(/colspan="8"/g, 'colspan="10"');
}

export function buildDailySummaryPrintHtml({ dateLabel, windowLabel, tableHtml }) {
  const printableTableHtml = buildDailySummaryPrintTableHtml(tableHtml);

  return `<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resumen de la jornada</title>
  <style>
    @page {
      size: landscape;
      margin: 10mm;
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
      font-family: Arial, Helvetica, sans-serif;
      font-size: 18px;
    }

    header {
      margin: 0 0 14px;
      text-align: center;
    }

    h1 {
      margin: 0 0 5px;
      font-size: 28px;
      line-height: 1.2;
    }

    p {
      margin: 0;
      font-size: 18px;
    }

    .journey-window {
      margin-top: 5px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
      font-size: 18px;
      line-height: 1.2;
    }

    thead {
      display: table-header-group;
    }

    tr {
      break-inside: avoid;
      page-break-inside: avoid;
    }

    th,
    td {
      padding: 7px 6px;
      border: 1px solid #000;
      background: #fff;
      color: #000;
      vertical-align: middle;
    }

    th {
      font-weight: 800;
    }

    th:not(:first-child),
    td:not(:first-child) {
      text-align: right;
      white-space: nowrap;
    }

    th:nth-child(2),
    td:nth-child(2) {
      text-align: left;
    }

    td:first-child {
      overflow-wrap: anywhere;
    }

    .daily-client-types {
      white-space: normal;
    }

    .daily-client-type {
      display: inline;
      font-size: 18px;
    }

    .daily-client-type + .daily-client-type::before {
      content: ", ";
    }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
  </style>
</head>
<body>
  <header>
    <h1>Resumen de la jornada</h1>
    <p>Fecha: <strong>${escapePrintHtml(dateLabel)}</strong></p>
    <p class="journey-window"><strong>Horario:</strong> ${escapePrintHtml(windowLabel)}</p>
  </header>
  ${printableTableHtml}
</body>
</html>`;
}

export function printDailySummary({ dateLabel, windowLabel, table, onError } = {}) {
  if (!table?.outerHTML) {
    onError?.();
    return;
  }

  const printFrame = document.createElement("iframe");
  let cleanupTimer = null;

  printFrame.className = "ticket-print-frame";
  printFrame.title = "Impresión del resumen de la jornada";
  printFrame.setAttribute("aria-hidden", "true");
  printFrame.addEventListener("load", () => {
    const printWindow = printFrame.contentWindow;

    if (!printWindow) {
      printFrame.remove();
      onError?.();
      return;
    }

    const cleanup = () => {
      if (cleanupTimer) window.clearTimeout(cleanupTimer);
      printFrame.remove();
    };

    printWindow.addEventListener("afterprint", cleanup, { once: true });
    cleanupTimer = window.setTimeout(cleanup, 60000);

    window.setTimeout(() => {
      try {
        printWindow.focus();
        printWindow.print();
      } catch {
        cleanup();
        onError?.();
      }
    }, 150);
  }, { once: true });

  printFrame.srcdoc = buildDailySummaryPrintHtml({
    dateLabel,
    windowLabel,
    tableHtml: table.outerHTML
  });
  document.body.appendChild(printFrame);
}
