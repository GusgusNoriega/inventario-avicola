<div class="report-actions report-wide">
  <button class="fin-btn fin-btn-ghost" type="submit" name="descargar" value="0">Ver PDF</button>
  <button class="fin-btn fin-btn-primary" type="submit" name="descargar" value="1">Descargar PDF</button>
  <button class="fin-btn fin-btn-image" type="submit" formaction="{{ route('finanzas.reportes.imagen', $reportType) }}">Descargar imagen</button>
</div>
