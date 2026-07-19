const IMPACT_ORDER = ["critical", "serious", "moderate", "minor"];
function dispatch(target, name, detail) {
  target.dispatchEvent(new CustomEvent(name, { bubbles: true, composed: true, detail }));
}
export {
  IMPACT_ORDER,
  dispatch
};
