@once
  <x-product-dispatch-numeric-keypad />
  <x-product-dispatch-text-keyboard />
  <script type="module" src="{{ asset('js/despacho-productos-keyboards.js') }}?v={{ filemtime(public_path('js/despacho-productos-keyboards.js')) }}"></script>
@endonce
