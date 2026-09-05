@props(['id' => 'pddTextKeyboard'])

<dialog id="{{ $id }}" class="pdk-dialog pdk-text-dialog" aria-labelledby="{{ $id }}Title">
  <div class="pdk-card">
    <header class="pdk-head">
      <div>
        <p>Teclado táctil</p>
        <h2 id="{{ $id }}Title" data-pdk-text-title>Ingresar texto</h2>
      </div>
      <button type="button" data-pdk-text-action="cancel" aria-label="Cancelar y cerrar teclado">×</button>
    </header>

    <div class="pdk-value-wrap">
      <span id="{{ $id }}ValueLabel">Texto seleccionado</span>
      <div id="{{ $id }}Value" class="pdk-value pdk-text-value" data-pdk-text-value tabindex="-1" role="textbox" aria-readonly="true" aria-labelledby="{{ $id }}ValueLabel"></div>
    </div>
    <p class="pdk-hint">Escribe con las teclas de abajo o con tu teclado físico.</p>
    <p id="{{ $id }}Message" class="pdk-message" data-pdk-text-message role="status" aria-live="polite"></p>

    <div class="pdk-text-row pdk-text-navigation" role="group" aria-label="Posición en el texto">
      <button class="pdk-key" type="button" data-pdk-text-action="home" aria-label="Ir al inicio">Inicio</button>
      <button class="pdk-key" type="button" data-pdk-text-action="left" aria-label="Mover cursor a la izquierda">←</button>
      <button class="pdk-key" type="button" data-pdk-text-action="select-all">Seleccionar todo</button>
      <button class="pdk-key" type="button" data-pdk-text-action="right" aria-label="Mover cursor a la derecha">→</button>
      <button class="pdk-key" type="button" data-pdk-text-action="end" aria-label="Ir al final">Fin</button>
    </div>
    <div class="pdk-text-keys" data-pdk-text-keys role="group" aria-label="Letras del teclado español"></div>

    <footer class="pdk-actions">
      <button class="pdk-cancel" type="button" data-pdk-text-action="clear">Limpiar</button>
      <button class="pdk-cancel" type="button" data-pdk-text-action="cancel">Cancelar</button>
      <button class="pdk-confirm" type="button" data-pdk-text-action="accept">Aceptar texto</button>
    </footer>
  </div>
</dialog>
