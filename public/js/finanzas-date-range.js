export function formatFinanceFilterDate(value) {
  const [year, month, day] = String(value || "").split("-");
  return year && month && day ? `${day}/${month}/${year}` : String(value || "");
}

export function isValidFinanceFilterDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ""));
  if (!match) return false;

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year
    && date.getUTCMonth() === month - 1
    && date.getUTCDate() === day;
}

export function financeDateRangeContains(date, from, to) {
  const normalizedDate = String(date || "").slice(0, 10);
  return Boolean(normalizedDate && from && to && normalizedDate >= from && normalizedDate <= to);
}

export function financeDateRangePhrase(from, to, today) {
  if (from === today && to === today) return "de hoy";
  if (from === to) return `del ${formatFinanceFilterDate(from)}`;
  return `del ${formatFinanceFilterDate(from)} al ${formatFinanceFilterDate(to)}`;
}
