export function normalizeRetailClientSearch(value) {
  return String(value ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLocaleLowerCase("es")
    .replace(/\s+/g, " ")
    .trim();
}

function clientSearchRank(client, query) {
  if (!query) return 0;

  const name = normalizeRetailClientSearch(client?.name);
  const nameWords = name.split(" ").filter(Boolean);

  if (nameWords.some((word) => word.startsWith(query))) return 0;
  if (name.includes(query)) return 1;
  return Number.POSITIVE_INFINITY;
}

export function filterAndRankRetailClients(clients, search = "") {
  const query = normalizeRetailClientSearch(search);

  return (Array.isArray(clients) ? clients : [])
    .map((client, originalIndex) => ({
      client,
      originalIndex,
      rank: clientSearchRank(client, query)
    }))
    .filter((entry) => Number.isFinite(entry.rank))
    .sort((left, right) => left.rank - right.rank || left.originalIndex - right.originalIndex)
    .map((entry) => entry.client);
}
