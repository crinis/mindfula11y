var StructureErrorSeverity = /* @__PURE__ */ ((StructureErrorSeverity2) => {
  StructureErrorSeverity2["Error"] = "error";
  StructureErrorSeverity2["Warning"] = "warning";
  return StructureErrorSeverity2;
})(StructureErrorSeverity || {});
const HEADING_ERROR_KEYS = {
  missingH1: "mindfula11y.structure.headings.error.missingH1",
  multipleH1: "mindfula11y.structure.headings.error.multipleH1",
  emptyHeading: "mindfula11y.structure.headings.error.emptyHeadings",
  skippedLevel: "mindfula11y.structure.headings.error.skippedLevel"
};
const STRUCTURE_VIEWPORT_ORDER = ["mobile", "desktop"];
export {
  HEADING_ERROR_KEYS,
  STRUCTURE_VIEWPORT_ORDER,
  StructureErrorSeverity
};
