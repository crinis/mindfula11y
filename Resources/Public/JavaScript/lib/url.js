function safeHttpUrl(url) {
  if (typeof url !== "string" || url === "") {
    return "#";
  }
  try {
    const parsed = new URL(url, window.location.origin);
    return parsed.protocol === "http:" || parsed.protocol === "https:" ? url : "#";
  } catch {
    return "#";
  }
}
function withQueryParams(base, params) {
  const url = new URL(base, window.location.origin);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }
  return url.href;
}
export {
  safeHttpUrl,
  withQueryParams
};
