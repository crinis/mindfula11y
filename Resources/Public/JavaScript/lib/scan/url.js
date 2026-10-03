function normalizeScanTargetUrl(url) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }
  if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
    return null;
  }
  parsed.hash = "";
  if (parsed.pathname.length > 1 && parsed.pathname.endsWith("/")) {
    parsed.pathname = parsed.pathname.replace(/\/+$/, "");
  }
  return parsed.href;
}
function urlListCoveredByTargets(urlList, targets) {
  const normalized = (url) => normalizeScanTargetUrl(url) ?? url;
  const targetSet = new Set(targets.map(normalized));
  return urlList.every((url) => targetSet.has(normalized(url)));
}
export {
  normalizeScanTargetUrl,
  urlListCoveredByTargets
};
