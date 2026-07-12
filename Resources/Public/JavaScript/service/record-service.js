import AjaxDataHandler from "@typo3/backend/ajax-data-handler.js";
class RecordUpdateError extends Error {
  constructor(message) {
    super(message);
    this.name = "RecordUpdateError";
  }
}
class RecordService {
  async updateField(record, value) {
    let result;
    try {
      result = await AjaxDataHandler.process({
        data: {
          [record.tableName]: {
            [record.uid]: {
              [record.columnName]: value
            }
          }
        }
      });
    } catch (error) {
      throw new RecordUpdateError(error instanceof Error ? error.message : String(error));
    }
    if (result.hasErrors) {
      throw new RecordUpdateError(`DataHandler reported errors updating ${record.tableName}:${record.uid}`);
    }
  }
}
var record_service_default = RecordService;
export {
  RecordService,
  RecordUpdateError,
  record_service_default as default
};
