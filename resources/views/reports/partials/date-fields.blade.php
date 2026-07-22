<label class="fin-field"><span>Desde</span><input type="date" name="desde" value="{{ now()->startOfMonth()->format('Y-m-d') }}" required></label>
<label class="fin-field"><span>Hasta</span><input type="date" name="hasta" value="{{ now()->format('Y-m-d') }}" required></label>
