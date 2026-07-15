const isObject = (value) => typeof value === "object" && value !== null;
const isBoundedString = (value, maximum = 1e4) => typeof value === "string" && value.length <= maximum;
const isStringMap = (value) => isObject(value) && Object.keys(value).length <= 100 && Object.entries(value).every(([key, label]) => key.length <= 128 && isBoundedString(label, 1e3));
export {
  isBoundedString,
  isObject,
  isStringMap
};
