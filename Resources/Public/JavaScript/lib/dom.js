const extractRecord = (element) => {
  const tableName = element.dataset.mindfula11yRecordTableName ?? "";
  const columnName = element.dataset.mindfula11yRecordColumnName ?? "";
  const uid = Number.parseInt(element.dataset.mindfula11yRecordUid ?? "", 10);
  if (tableName === "" || columnName === "" || Number.isNaN(uid)) {
    return null;
  }
  return { tableName, columnName, uid, editLink: "" };
};
const buildStructureNodeId = (record, index, seen, fallbackBase = "") => {
  const base = record !== null ? `${record.tableName}:${record.uid}:${record.columnName}` : fallbackBase || `pos:${index}`;
  const occurrence = seen.get(base) ?? 0;
  seen.set(base, occurrence + 1);
  return occurrence === 0 ? base : `${base}#${occurrence}`;
};
const indexStructureNodes = (elements, fallbackBase = () => "") => {
  const index = /* @__PURE__ */ new Map();
  const seen = /* @__PURE__ */ new Map();
  elements.forEach((element, documentOrder) => {
    index.set(element, {
      id: buildStructureNodeId(extractRecord(element), documentOrder, seen, fallbackBase(element)),
      documentOrder
    });
  });
  return index;
};
const scrollIntoViewCentered = (element) => {
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  element.scrollIntoView({ block: "center", behavior: reduceMotion ? "auto" : "smooth" });
};
export {
  extractRecord,
  indexStructureNodes,
  scrollIntoViewCentered
};
