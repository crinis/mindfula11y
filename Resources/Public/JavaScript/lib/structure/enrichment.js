const recordKey = (record) => `${record.tableName}\0${record.uid}\0${record.columnName}`;
const walk = (nodes, visit) => {
  for (const node of nodes) {
    visit(node);
    walk(node.children, visit);
  }
};
const addRecord = (records, record) => {
  if (record === null) {
    return;
  }
  const request = { tableName: record.tableName, columnName: record.columnName, uid: record.uid };
  records.set(recordKey(request), request);
};
const collectRecordRequests = (analysis) => {
  const records = /* @__PURE__ */ new Map();
  if (analysis.headings !== null) {
    walk(analysis.headings.nodes, (node) => addRecord(records, node.record));
  }
  if (analysis.landmarks !== null) {
    walk(analysis.landmarks.nodes, (node) => addRecord(records, node.record));
  }
  return Array.from(records.values());
};
const enrichNode = (node, metadata, applyValues) => {
  const record = node.record;
  if (record === null) {
    return;
  }
  const value = metadata.get(recordKey(record));
  if (value === void 0) {
    return;
  }
  node.record = { ...record, editLink: value.editLink };
  applyValues(value.availableValues);
};
const applyRecordMetadata = (analysis, metadata) => {
  if (analysis.headings !== null) {
    walk(
      analysis.headings.nodes,
      (node) => enrichNode(node, metadata, (values) => {
        node.availableTypes = values;
      })
    );
  }
  if (analysis.landmarks !== null) {
    walk(
      analysis.landmarks.nodes,
      (node) => enrichNode(node, metadata, (values) => {
        node.availableRoles = values;
      })
    );
  }
};
export {
  applyRecordMetadata,
  collectRecordRequests,
  recordKey
};
