<!doctype html>
<html lang="es" class="customer-history-root weighing-management-root">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestión de pesadas | Sistema Pollos</title>
  <link rel="stylesheet" href="{{ asset('css/style.css') }}?v={{ filemtime(public_path('css/style.css')) }}">
</head>
<body class="customer-history-page weighing-management-page">
  <main class="customer-history-view weighing-management-view" data-weighing-management>
    <section class="weighing-search-card card" aria-labelledby="ticketSearchTitle">
      <form id="ticketSearchForm" class="weighing-search-form">
        <h1 id="ticketSearchTitle">Buscar ticket</h1>
        <label class="field">
          Número de ticket o cliente registrado
          <input id="ticketSearchInput" type="search" maxlength="100" autocomplete="off" placeholder="Busca tickets mayoristas, minoristas o ventas externas por número">
        </label>
        <button class="btn btn-primary directory-btn" type="submit">Buscar</button>
        <button id="ticketSearchClear" class="btn btn-ghost directory-btn" type="button">Limpiar</button>
        <a class="menu-return-btn weighing-menu-btn" href="{{ route('menu') }}">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 6h7v7H4z"></path>
            <path d="M13 6h7v7h-7z"></path>
            <path d="M4 15h7v3H4z"></path>
            <path d="M13 15h7v3h-7z"></path>
          </svg>
          <span>Menú</span>
        </a>
      </form>
      <p id="weighingManagementMessage" class="form-message weighing-search-message" role="status" aria-live="polite" hidden></p>
    </section>

    <div class="weighing-management-layout">
      <aside class="weighing-ticket-results card" aria-labelledby="ticketResultsTitle">
        <div class="weighing-panel-head">
          <div>
            <p class="eyebrow">Resultados</p>
            <h2 id="ticketResultsTitle">Tickets de despacho</h2>
          </div>
          <span id="ticketResultsCount" class="directory-record-tag">0</span>
        </div>
        <div id="ticketResultsList" class="weighing-ticket-list" aria-live="polite"></div>
      </aside>

      <section id="selectedTicketPanel" class="weighing-selected-panel" aria-live="polite">
        <div class="directory-empty card">
          <strong>Selecciona un ticket</strong>
          <span>Solo se mostrarán las pesadas del ticket seleccionado.</span>
        </div>
      </section>
    </div>
  </main>

  <div id="editWeighingModal" class="weighing-modal" hidden>
    <div class="weighing-modal-card card" role="dialog" aria-modal="true" aria-labelledby="editWeighingTitle">
      <div class="weighing-modal-head">
        <div>
          <p class="eyebrow">Modificar registro</p>
          <h2 id="editWeighingTitle">Editar pesada</h2>
        </div>
        <button id="editWeighingClose" class="btn btn-ghost" type="button" aria-label="Cerrar">Cerrar</button>
      </div>
      <form id="editWeighingForm" class="weighing-edit-form">
        <label id="editChickenTypeField" class="field">
          Tipo de pollo
          <select id="editChickenType" required></select>
        </label>
        <label id="editChickenConditionField" class="field">
          Condición
          <select id="editChickenCondition" required>
            <option value="VIVO">Pollo vivo</option>
            <option value="MUERTO">Pollo muerto</option>
          </select>
        </label>
        <fieldset class="sex-selector management-sex-selector" aria-label="Sexo de los pollos">
          <legend>Sexo</legend>
          <div class="sex-selector-buttons">
            <button class="sex-btn sex-btn-male is-active" type="button" data-management-sex="MACHO" aria-pressed="true">Macho</button>
            <button class="sex-btn sex-btn-female" type="button" data-management-sex="HEMBRA" aria-pressed="false">Hembra</button>
          </div>
        </fieldset>
        <label class="field">
          Aves por java (o total sin javas)
          <input id="editBirdsPerCage" type="number" min="1" max="1000" step="1" required>
        </label>
        <label class="field">
          Cantidad de javas
          <input id="editCages" type="number" min="0" max="10000" step="1" required>
        </label>
        <label class="field">
          Tipo de java
          <select id="editCageType" required></select>
        </label>
        <label class="field">
          Peso bruto (kg)
          <input id="editGrossWeight" type="number" min="0.001" max="99999999.999" step="0.001" required>
        </label>
        <label id="editOriginTruckField" class="field">
          Camión de origen de la jornada
          <select id="editOriginTruck">
            <option value="">Mantener el origen actual</option>
          </select>
          <small id="editOriginTruckHelp" class="field-help">Solo aparecen camiones incluidos en la jornada de este ticket.</small>
        </label>
        <label class="field">
          Origen del peso
          <select id="editWeightSource" required>
            <option value="MANUAL">Manual</option>
            <option value="BALANZA_1">Balanza 1</option>
            <option value="BALANZA_2">Balanza 2</option>
            <option value="BALANZA">Balanza</option>
          </select>
        </label>
        <label class="field">
          Fecha y hora
          <input id="editWeighedAt" type="datetime-local" required>
        </label>
        <div id="editWeightPreview" class="weighing-edit-preview"></div>
        <p id="editWeighingMessage" class="form-message" role="status" aria-live="polite"></p>
        <div class="weighing-modal-actions">
          <button id="editWeighingCancel" class="btn btn-ghost" type="button">Cancelar</button>
          <button class="btn btn-success" type="submit">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>

  <div id="editTicketDeliveryModal" class="weighing-modal" hidden>
    <div class="weighing-modal-card weighing-delivery-card card" role="dialog" aria-modal="true" aria-labelledby="editTicketDeliveryTitle">
      <div class="weighing-modal-head">
        <div>
          <p class="eyebrow">Transporte de entrega</p>
          <h2 id="editTicketDeliveryTitle">Editar camión y chofer</h2>
        </div>
        <button id="editTicketDeliveryClose" class="btn btn-ghost" type="button" aria-label="Cerrar">Cerrar</button>
      </div>
      <p id="editTicketDeliveryCode" class="weighing-delivery-ticket">--</p>
      <form id="editTicketDeliveryForm" class="weighing-delivery-form">
        <label class="field">
          Camión de la empresa
          <select id="editTicketVehicle" required></select>
        </label>
        <label class="field">
          Chofer de la empresa
          <select id="editTicketDriver" required></select>
        </label>
        <p id="editTicketDeliveryMessage" class="form-message" role="status" aria-live="polite"></p>
        <div class="weighing-modal-actions">
          <button id="editTicketDeliveryCancel" class="btn btn-ghost" type="button">Cancelar</button>
          <button class="btn btn-success" type="submit">Guardar transporte</button>
        </div>
      </form>
    </div>
  </div>

  <div id="deleteWeighingModal" class="weighing-modal" hidden>
    <div class="weighing-modal-card weighing-delete-card card" role="dialog" aria-modal="true" aria-labelledby="deleteWeighingTitle">
      <div class="weighing-modal-head">
        <div>
          <p class="eyebrow">Confirmación</p>
          <h2 id="deleteWeighingTitle">Eliminar pesada</h2>
        </div>
      </div>
      <p id="deleteWeighingCopy" class="weighing-delete-copy"></p>
      <form id="deleteWeighingForm">
        <label class="field">
          Motivo de eliminación
          <textarea id="deleteWeighingReason" minlength="3" maxlength="250" rows="3" required placeholder="Indica por qué se elimina esta pesada"></textarea>
        </label>
        <p id="deleteWeighingMessage" class="form-message" role="status" aria-live="polite"></p>
        <div class="weighing-modal-actions">
          <button id="deleteWeighingCancel" class="btn btn-ghost" type="button">Cancelar</button>
          <button class="btn btn-danger" type="submit">Eliminar pesada</button>
        </div>
      </form>
    </div>
  </div>

  <script type="module" src="{{ asset('js/gestion-pesadas.js') }}?v={{ filemtime(public_path('js/gestion-pesadas.js')) }}"></script>
</body>
</html>
