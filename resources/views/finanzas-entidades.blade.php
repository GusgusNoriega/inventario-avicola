<!doctype html>
<html lang="es" class="fin-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Empresas y cuentas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}">
  <link rel="stylesheet" href="{{ asset('css/finanzas.css') }}?v={{ filemtime(public_path('css/finanzas.css')) }}">
</head>
<body class="fin-page fin-entities-page">
  <main class="fin-shell">
    @include('partials.finanzas-header', [
      'active' => 'entidades',
      'eyebrow' => 'Destinos de pago',
      'title' => 'Empresas y cuentas',
      'description' => 'Registra las empresas propias y las externas asociadas a proveedores, junto con sus cuentas receptoras.'
    ])

    <section class="fin-summary-grid fin-summary-grid-compact" aria-label="Resumen de empresas y cuentas">
      <article class="fin-summary-card fin-card">
        <div><span>Empresas propias</span><small>Con saldo administrado</small></div>
        <strong id="financeOwnEntityCount">0</strong>
      </article>
      <article class="fin-summary-card fin-card">
        <div><span>Empresas externas</span><small>Relacionadas con proveedores</small></div>
        <strong id="financeExternalEntityCount">0</strong>
      </article>
      <article class="fin-summary-card fin-card">
        <div><span>Cuentas activas</span><small>Bancos, cajas y billeteras</small></div>
        <strong id="financeAccountCount">0</strong>
      </article>
    </section>

    <section class="fin-entity-workspace">
      <section class="fin-card fin-form-panel" aria-labelledby="financeEntityFormTitle">
        <div class="fin-section-head">
          <div>
            <p class="fin-eyebrow">Titular del destino</p>
            <h2 id="financeEntityFormTitle">Agregar empresa</h2>
          </div>
          <span id="financeEntityEditBadge" class="fin-badge" hidden>Editando</span>
        </div>

        <form id="financeEntityForm" class="fin-form" novalidate>
          <input id="financeEntityId" type="hidden">
          <div class="fin-segmented" role="radiogroup" aria-label="Tipo de empresa">
            <label>
              <input type="radio" name="financeEntityType" value="PROPIA" checked>
              <span><strong>Propia</strong><small>El saldo queda disponible para la empresa</small></span>
            </label>
            <label>
              <input type="radio" name="financeEntityType" value="EXTERNA">
              <span><strong>Externa</strong><small>El dinero se deposita a un proveedor</small></span>
            </label>
          </div>

          <label id="financeEntityProviderField" class="fin-field" hidden>
            <span>Proveedor relacionado <b>*</b></span>
            <select id="financeEntityProvider"><option value="">Selecciona un proveedor</option></select>
            <small>Todo depósito recibido aquí reducirá la deuda con este proveedor.</small>
          </label>
          <label class="fin-field">
            <span>Razón social <b>*</b></span>
            <input id="financeEntityName" type="text" maxlength="180" autocomplete="organization" placeholder="Ej: Distribuidora El Corral" required>
          </label>
          <label class="fin-field">
            <span>Nombre comercial</span>
            <input id="financeEntityCommercialName" type="text" maxlength="180" autocomplete="organization" placeholder="Ej: El Corral">
          </label>
          <div class="fin-form-grid">
            <label class="fin-field">
              <span>Tipo de documento</span>
              <select id="financeEntityDocumentType">
                <option value="">Sin documento</option>
                <option value="RUC">RUC</option>
                <option value="DNI">DNI</option>
                <option value="CE">Carné de extranjería</option>
                <option value="PASAPORTE">Pasaporte</option>
                <option value="OTRO">Otro</option>
              </select>
            </label>
            <label class="fin-field">
              <span>Número de documento</span>
              <input id="financeEntityDocument" type="text" maxlength="30" autocomplete="off" placeholder="Ej: 20123456789">
            </label>
          </div>
          <label class="fin-field">
            <span>Dirección</span>
            <input id="financeEntityAddress" type="text" maxlength="250" autocomplete="street-address" placeholder="Dirección fiscal o comercial">
          </label>
          <div class="fin-form-grid">
            <label class="fin-field">
              <span>Teléfono</span>
              <input id="financeEntityPhone" type="tel" maxlength="30" autocomplete="tel" placeholder="Ej: 999 999 999">
            </label>
            <label class="fin-field">
              <span>Correo</span>
              <input id="financeEntityEmail" type="email" maxlength="180" autocomplete="email" placeholder="pagos@empresa.com">
            </label>
          </div>
          <p id="financeEntityFormMessage" class="fin-message" role="status" aria-live="polite"></p>
          <div class="fin-form-actions">
            <button id="financeEntitySave" class="fin-btn fin-btn-primary" type="submit">Guardar empresa</button>
            <button id="financeEntityCancel" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
          </div>
        </form>
      </section>

      <section class="fin-card fin-list-panel" aria-labelledby="financeEntityListTitle">
        <div class="fin-section-head fin-section-head-wrap">
          <div>
            <p class="fin-eyebrow">Directorio financiero</p>
            <h2 id="financeEntityListTitle">Empresas registradas</h2>
          </div>
          <div class="fin-inline-filters">
            <label class="fin-field fin-field-compact">
              <span class="fin-sr-only">Filtrar por tipo</span>
              <select id="financeEntityTypeFilter">
                <option value="">Todas</option>
                <option value="PROPIA">Propias</option>
                <option value="EXTERNA">Externas</option>
              </select>
            </label>
            <label class="fin-field fin-field-compact fin-search-field">
              <span class="fin-sr-only">Buscar empresa</span>
              <input id="financeEntitySearch" type="search" placeholder="Buscar empresa, RUC o proveedor">
            </label>
          </div>
        </div>
        <p id="financeEntityListMessage" class="fin-message" role="status" aria-live="polite">Cargando empresas...</p>
        <div id="financeEntityList" class="fin-entity-list" aria-live="polite"></div>
      </section>
    </section>

    <section id="financeAccountsPanel" class="fin-card fin-accounts-admin" aria-labelledby="financeAccountPanelTitle">
      <div class="fin-section-head fin-section-head-wrap">
        <div>
          <p class="fin-eyebrow">Cuentas receptoras</p>
          <h2 id="financeAccountPanelTitle">Selecciona una empresa</h2>
          <p id="financeAccountPanelCopy">Elige “Administrar cuentas” en una empresa para registrar sus bancos, cajas o billeteras.</p>
        </div>
        <span id="financeAccountEntityBadge" class="fin-badge" hidden></span>
      </div>

      <div id="financeAccountWorkspace" class="fin-account-workspace" hidden>
        <form id="financeAccountForm" class="fin-form fin-account-form" novalidate>
          <input id="financeAccountId" type="hidden">
          <div class="fin-form-subhead">
            <h3 id="financeAccountFormTitle">Agregar cuenta</h3>
            <span id="financeAccountEditBadge" class="fin-badge" hidden>Editando</span>
          </div>
          <div class="fin-form-grid">
            <label class="fin-field">
              <span>Tipo de cuenta <b>*</b></span>
              <select id="financeAccountType" required>
                <option value="BANCO">Cuenta bancaria</option>
                <option value="CAJA">Caja / efectivo</option>
                <option value="BILLETERA">Billetera digital</option>
                <option value="OTRA">Otra</option>
              </select>
            </label>
            <label class="fin-field">
              <span>Alias de la cuenta <b>*</b></span>
              <input id="financeAccountName" type="text" maxlength="100" placeholder="Ej: Cuenta corriente principal" required>
            </label>
            <label id="financeAccountBankField" class="fin-field">
              <span>Banco</span>
              <input id="financeAccountBank" type="text" maxlength="120" placeholder="Ej: BCP">
            </label>
            <label id="financeAccountNumberField" class="fin-field">
              <span>Número de cuenta</span>
              <input id="financeAccountNumber" type="text" maxlength="80" autocomplete="off" placeholder="Ej: 191-1234567-0-01">
            </label>
            <label id="financeAccountCciField" class="fin-field">
              <span>CCI</span>
              <input id="financeAccountCci" type="text" maxlength="40" autocomplete="off" placeholder="Código interbancario">
            </label>
            <label class="fin-field">
              <span>Moneda</span>
              <select id="financeAccountCurrency"><option value="PEN">Soles (PEN)</option><option value="USD">Dólares (USD)</option></select>
            </label>
            <label id="financeAccountOpeningField" class="fin-field">
              <span>Saldo inicial</span>
              <input id="financeAccountOpening" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
              <small>Solo se registra al crear la cuenta; luego el saldo proviene de movimientos.</small>
            </label>
          </div>
          <p id="financeAccountFormMessage" class="fin-message" role="status" aria-live="polite"></p>
          <div class="fin-form-actions">
            <button id="financeAccountSave" class="fin-btn fin-btn-primary" type="submit">Guardar cuenta</button>
            <button id="financeAccountCancel" class="fin-btn fin-btn-ghost" type="button">Limpiar</button>
          </div>
        </form>

        <div class="fin-account-list-panel">
          <h3>Cuentas de la empresa</h3>
          <div id="financeAccountList" class="fin-account-list" aria-live="polite"></div>
        </div>
      </div>
    </section>
  </main>

  <script type="module" src="{{ asset('js/finanzas-entidades.js') }}"></script>
</body>
</html>
