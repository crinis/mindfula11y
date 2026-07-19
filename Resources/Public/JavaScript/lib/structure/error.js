class StructureAnalysisError extends Error {
  constructor(code, message, status, pageUrl) {
    super(message);
    this.code = code;
    this.status = status;
    this.pageUrl = pageUrl;
    this.name = "StructureAnalysisError";
  }
}
export {
  StructureAnalysisError
};
