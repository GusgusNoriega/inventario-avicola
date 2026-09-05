<dialog id="pddNumericKeypad" class="pdk-dialog pdk-numeric-dialog" aria-labelledby="pddNumericKeypadTitle">
  <section>
    <header class="pdk-head">
      <div><p>Teclado táctil</p><h2 id="pddNumericKeypadTitle">Ingresar valor</h2></div>
      <button type="button" data-pdd-close="pddNumericKeypad" aria-label="Cerrar">×</button>
    </header>
    <div class="pdk-value-wrap">
      <span id="pddNumericKeypadValueLabel">Valor seleccionado</span>
      <output id="pddNumericKeypadValue" class="pdk-value" tabindex="-1" aria-labelledby="pddNumericKeypadValueLabel" aria-live="polite">0</output>
    </div>
    <p id="pddNumericKeypadHint" class="pdk-hint" hidden></p>
    <p id="pddNumericKeypadMessage" class="pdk-message" role="status" aria-live="polite"></p>
    <div class="pdk-numeric-grid" role="group" aria-label="Números disponibles">
      @foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '00', '0'] as $key)
        <button class="pdk-key" type="button" data-pdd-keypad-key="{{ $key }}">{{ $key }}</button>
      @endforeach
      <button class="pdk-key is-backspace" type="button" data-pdd-keypad-key="backspace" aria-label="Borrar último número">⌫</button>
    </div>
    <footer class="pdk-actions">
      <button id="pddNumericKeypadClear" class="pdk-cancel" type="button">Limpiar</button>
      <button class="pdk-cancel" type="button" data-pdd-close="pddNumericKeypad">Cancelar</button>
      <button id="pddNumericKeypadConfirm" class="pdk-confirm" type="button">Usar valor</button>
    </footer>
  </section>
</dialog>
