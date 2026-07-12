const extractRecord = (element) => {
  const tableName = element.dataset.mindfula11yRecordTableName ?? "";
  const columnName = element.dataset.mindfula11yRecordColumnName ?? "";
  const uid = Number.parseInt(element.dataset.mindfula11yRecordUid ?? "", 10);
  if (tableName === "" || columnName === "" || Number.isNaN(uid)) {
    return null;
  }
  return { tableName, columnName, uid, editLink: element.dataset.mindfula11yRecordEditLink ?? "" };
};
const buildStructureNodeId = (record, index, seen, fallbackBase = "") => {
  const base = record !== null ? `${record.tableName}:${record.uid}:${record.columnName}` : fallbackBase || `pos:${index}`;
  const occurrence = seen.get(base) ?? 0;
  seen.set(base, occurrence + 1);
  return occurrence === 0 ? base : `${base}#${occurrence}`;
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
  buildStructureNodeId,
  extractRecord,
  parseJsonMap,
  scrollIntoViewCentered
};
