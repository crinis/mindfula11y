class StructureAnalysisError extends Error {
  constructor(code, message, status) {
    super(message);
    this.code = code;
    this.status = status;
    this.name = "StructureAnalysisError";
  }
}
export {
  StructureAnalysisError
};
