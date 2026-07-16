@props([
  'id' => 'touchKeyboard',
  'title' => 'Ingresar texto',
])

<aside
  id="{{ $id }}"
  class="touch-keyboard"
  data-touch-keyboard-component
  hidden
  aria-hidden="true"
>
  <section
    class="touch-keyboard-card"
    role="dialog"
    aria-modal="false"
    aria-labelledby="{{ $id }}Title"
    aria-describedby="{{ $id }}Value"
  >
    <header class="touch-keyboard-head">
      <div>
        <span>Teclado táctil</span>
        <strong id="{{ $id }}Title" data-touch-keyboard-title>{{ $title }}</strong>
      </div>
      <output id="{{ $id }}Value" data-touch-keyboard-value aria-live="polite">Campo vacío</output>
      <button type="button" data-touch-keyboard-action="cancel" aria-label="Cancelar y cerrar teclado">×</button>
    </header>

    <div class="touch-keyboard-keys" data-touch-keyboard-keys aria-label="Teclado táctil"></div>

    <footer class="touch-keyboard-actions">
      <button type="button" data-touch-keyboard-action="clear">Limpiar</button>
      <button type="button" data-touch-keyboard-action="backspace" aria-label="Borrar último carácter">← Borrar</button>
      <button type="button" class="is-accept" data-touch-keyboard-action="accept">Aceptar</button>
    </footer>
  </section>
</aside>
