const parseUid = (raw) => raw !== void 0 && /^[1-9]\d*$/.test(raw) ? Number(raw) : null;
const extractRecord = (element) => {
  const tableName = element.dataset.mindfula11yRecordTableName ?? "";
  const columnName = element.dataset.mindfula11yRecordColumnName ?? "";
  const uid = parseUid(element.dataset.mindfula11yRecordUid);
  if (tableName === "" || columnName === "" || uid === null) {
    return null;
  }
  const storedValue = element.dataset.mindfula11yRecordValue;
  return { tableName, columnName, uid, editLink: "", ...storedValue !== void 0 ? { storedValue } : {} };
};
const extractChildTypeRecord = (element) => {
  const tableName = element.dataset.mindfula11yChildtypeTableName ?? "";
  const columnName = element.dataset.mindfula11yChildtypeColumnName ?? "";
  const uid = parseUid(element.dataset.mindfula11yChildtypeUid);
  if (tableName === "" || columnName === "" || uid === null) {
    return null;
  }
  return { tableName, columnName, uid, editLink: "", storedValue: element.dataset.mindfula11yChildtypeValue ?? "" };
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
export {
  extractChildTypeRecord,
  extractRecord,
  indexStructureNodes
};
