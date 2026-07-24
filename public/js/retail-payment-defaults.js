export function normalizeRetailPaymentDefaultId(value) {
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : null;
}

export function resolveRetailPaymentDefaults(financial = {}) {
  const methods = Array.isArray(financial.methods) ? financial.methods : [];
  const accounts = Array.isArray(financial.own_accounts) ? financial.own_accounts : [];
  const configuredMethodId = normalizeRetailPaymentDefaultId(financial.default_method_id);
  const configuredAccountId = normalizeRetailPaymentDefaultId(financial.default_account_id);
  const method = methods.find(
    (entry) => normalizeRetailPaymentDefaultId(entry?.id) === configuredMethodId
  ) || methods[0] || null;
  const account = accounts.find(
    (entry) => normalizeRetailPaymentDefaultId(entry?.id) === configuredAccountId
  ) || accounts[0] || null;

  return {
    methodId: normalizeRetailPaymentDefaultId(method?.id) || "",
    accountId: normalizeRetailPaymentDefaultId(account?.id) || ""
  };
}
