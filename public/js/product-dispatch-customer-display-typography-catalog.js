export const PRODUCT_CUSTOMER_DISPLAY_TYPOGRAPHY_GROUPS = [
  {
    id: "header",
    title: "Cabecera",
    description: "Marca, título y acciones superiores.",
    controls: [
      { label: "Marca DP", variable: "--pdcd-fs-mark", selector: ".pdcd-mark", defaultValue: 48, min: 20, max: 88, step: 1, preview: "DP" },
      { label: "Título empresa", variable: "--pdcd-fs-company-title", selector: ".pdcd-header h1", defaultValue: 64, min: 18, max: 120, step: 1, preview: "LA CENTRAL DE LOS POLLOS" },
      { label: "Estado", variable: "--pdcd-fs-status", selector: ".pdcd-status", defaultValue: 14, min: 9, max: 36, step: 0.5, preview: "EN VIVO" },
      { label: "Botones", variable: "--pdcd-fs-actions", selector: ".pdcd-actions button", defaultValue: 14, min: 9, max: 36, step: 0.5, preview: "Aa  Monitor" }
    ]
  },
  {
    id: "live",
    title: "Peso e importe",
    description: "Lectura neta e importe de la pesada actual.",
    open: true,
    controls: [
      { label: "Etiqueta peso", variable: "--pdcd-fs-live-label", selector: ".pdcd-live-heading > span", defaultValue: 34, min: 12, max: 64, step: 0.5, preview: "PESO NETO" },
      { label: "Estado cálculo", variable: "--pdcd-fs-live-status", selector: ".pdcd-live-heading small", defaultValue: 17, min: 9, max: 40, step: 0.5, preview: "Neto en vivo" },
      { label: "Peso", variable: "--pdcd-fs-live-weight", selector: ".pdcd-live-weight strong", defaultValue: 140, min: 48, max: 240, step: 2, preview: "12.450" },
      { label: "Unidad kg", variable: "--pdcd-fs-live-unit", selector: ".pdcd-live-weight span", defaultValue: 30, min: 12, max: 72, step: 1, preview: "kg" },
      { label: "Etiqueta importe", variable: "--pdcd-fs-live-amount-label", selector: ".pdcd-live-amount span", defaultValue: 38, min: 12, max: 72, step: 1, preview: "IMPORTE" },
      { label: "Valor importe", variable: "--pdcd-fs-live-amount-value", selector: ".pdcd-live-amount strong", defaultValue: 48, min: 18, max: 100, step: 1, preview: "S/ 68.48" }
    ]
  },
  {
    id: "list",
    title: "Lista activa",
    description: "Identificación de la lista y del cliente.",
    controls: [
      { label: "Etiqueta lista", variable: "--pdcd-fs-list-caption", selector: ".pdcd-list-heading span", defaultValue: 20, min: 10, max: 42, step: 0.5, preview: "LISTA DE VENTA" },
      { label: "Número de lista", variable: "--pdcd-fs-list-number", selector: ".pdcd-list-heading h2", defaultValue: 42, min: 16, max: 80, step: 1, preview: "Lista 1" },
      { label: "Cliente", variable: "--pdcd-fs-customer", selector: ".pdcd-list-heading > strong", defaultValue: 24, min: 12, max: 56, step: 0.5, preview: "Venta al público" }
    ]
  },
  {
    id: "table",
    title: "Productos",
    description: "Encabezados y columnas de la tabla.",
    open: true,
    controls: [
      { label: "Encabezados", variable: "--pdcd-fs-table-head", selector: ".pdcd-list th", defaultValue: 18, min: 10, max: 40, step: 0.5, preview: "PROD.  CANT.  NETO" },
      { label: "Nombre producto", variable: "--pdcd-fs-row-product", selector: ".pdcd-list td:first-child:not([colspan])", defaultValue: 24, min: 12, max: 52, step: 0.5, preview: "Pollo beneficiado" },
      { label: "Cantidad", variable: "--pdcd-fs-row-quantity", selector: ".pdcd-list td:nth-child(2)", defaultValue: 24, min: 12, max: 52, step: 0.5, preview: "2" },
      { label: "Peso producto", variable: "--pdcd-fs-row-net", selector: ".pdcd-list td:nth-child(3)", defaultValue: 24, min: 12, max: 52, step: 0.5, preview: "7.125 kg" },
      { label: "Importe producto", variable: "--pdcd-fs-row-amount", selector: ".pdcd-list td:nth-child(4)", defaultValue: 24, min: 12, max: 52, step: 0.5, preview: "S/ 39.19" },
      { label: "Lista vacía", variable: "--pdcd-fs-empty", selector: ".pdcd-list .pdcd-empty-row td", defaultValue: 24, min: 12, max: 52, step: 0.5, preview: "Lista vacía" }
    ]
  },
  {
    id: "totals",
    title: "Totales",
    description: "Peso e importe acumulados de la lista.",
    controls: [
      { label: "Etiquetas", variable: "--pdcd-fs-total-label", selector: ".pdcd-list-total span", defaultValue: 17, min: 10, max: 40, step: 0.5, preview: "TOTAL" },
      { label: "Neto lista", variable: "--pdcd-fs-total-net", selector: ".pdcd-list-total > div:first-child strong", defaultValue: 40, min: 16, max: 80, step: 1, preview: "9.575 kg" },
      { label: "Total lista", variable: "--pdcd-fs-total-amount", selector: ".pdcd-list-total > div:last-child strong", defaultValue: 42, min: 16, max: 90, step: 1, preview: "S/ 107.67" }
    ]
  },
  {
    id: "screen",
    title: "Selector de monitor",
    description: "Textos de la ventana para elegir pantalla.",
    controls: [
      { label: "Etiqueta ventana", variable: "--pdcd-fs-dialog-caption", selector: ".pdcd-screen-dialog-head small", defaultValue: 13, min: 9, max: 32, step: 0.5, preview: "MONITORES" },
      { label: "Título ventana", variable: "--pdcd-fs-dialog-title", selector: ".pdcd-screen-dialog-head h2", defaultValue: 26, min: 14, max: 56, step: 1, preview: "Elige una pantalla" },
      { label: "Botón cerrar", variable: "--pdcd-fs-dialog-close", selector: ".pdcd-screen-dialog-head > button", defaultValue: 28, min: 18, max: 48, step: 1, preview: "×" },
      { label: "Nombre monitor", variable: "--pdcd-fs-dialog-option-title", selector: ".pdcd-screen-list strong", defaultValue: 16, min: 10, max: 40, step: 0.5, preview: "Pantalla principal" },
      { label: "Detalle monitor", variable: "--pdcd-fs-dialog-option-detail", selector: ".pdcd-screen-list span", defaultValue: 14, min: 9, max: 32, step: 0.5, preview: "1920 × 1080" },
      { label: "Mensaje ventana", variable: "--pdcd-fs-dialog-feedback", selector: "#productCustomerDisplayScreenFeedback", defaultValue: 14, min: 9, max: 32, step: 0.5, preview: "2 pantallas" }
    ]
  }
];
