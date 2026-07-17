const extractRecord = (element) => {
  const tableName = element.dataset.mindfula11yRecordTableName ?? "";
  const columnName = element.dataset.mindfula11yRecordColumnName ?? "";
  const uid = Number.parseInt(element.dataset.mindfula11yRecordUid ?? "", 10);
  if (tableName === "" || columnName === "" || Number.isNaN(uid)) {
    return null;
  }
  const storedValue = element.dataset.mindfula11yRecordValue;
  return { tableName, columnName, uid, editLink: "", ...storedValue !== void 0 ? { storedValue } : {} };
};
const extractChildTypeRecord = (element) => {
  const tableName = element.dataset.mindfula11yChildtypeTableName ?? "";
  const columnName = element.dataset.mindfula11yChildtypeColumnName ?? "";
  const uid = Number.parseInt(element.dataset.mindfula11yChildtypeUid ?? "", 10);
  if (tableName === "" || columnName === "" || Number.isNaN(uid)) {
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
