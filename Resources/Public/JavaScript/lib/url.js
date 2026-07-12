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
export {
  safeHttpUrl
};
