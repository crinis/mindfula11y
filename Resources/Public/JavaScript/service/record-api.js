import AjaxDataHandler from "@typo3/backend/ajax-data-handler.js";
class RecordUpdateError extends Error {
  constructor(message) {
    super(message);
    this.name = "RecordUpdateError";
  }
}
class RecordApi {
  async updateField(record, value) {
    await this.updateFields(record.tableName, record.uid, { [record.columnName]: value });
  }
  async updateFields(tableName, uid, fields) {
    let result;
    try {
      result = await AjaxDataHandler.process({
        data: {
          [tableName]: {
            [uid]: fields
          }
        }
      });
    } catch (error) {
      throw new RecordUpdateError(error instanceof Error ? error.message : String(error));
    }
    if (result.hasErrors) {
      throw new RecordUpdateError(`DataHandler reported errors updating ${tableName}:${uid}`);
    }
  }
}
export {
  RecordApi,
  RecordUpdateError
};
