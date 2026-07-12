const extractRecord = (element) => {
  const tableName = element.dataset.mindfula11yRecordTableName ?? "";
  const columnName = element.dataset.mindfula11yRecordColumnName ?? "";
  const uid = Number.parseInt(element.dataset.mindfula11yRecordUid ?? "", 10);
  if (tableName === "" || columnName === "" || Number.isNaN(uid)) {
    return null;
  }
  return { tableName, columnName, uid, editLink: element.dataset.mindfula11yRecordEditLink ?? "" };
};
const parseJsonMap = (raw) => {
  if (raw === void 0 || raw === "") {
    return {};
  }
  try {
    return JSON.parse(raw);
  } catch {
    return {};
  }
};
const scrollIntoViewCentered = (element) => {
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  element.scrollIntoView({ block: "center", behavior: reduceMotion ? "auto" : "smooth" });
};
export {
  extractRecord,
  parseJsonMap,
  scrollIntoViewCentered
};
