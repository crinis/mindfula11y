var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __decorateClass = (decorators, target, key, kind) => {
  var result = kind > 1 ? void 0 : kind ? __getOwnPropDesc(target, key) : target;
  for (var i = decorators.length - 1, decorator; i >= 0; i--)
    if (decorator = decorators[i])
      result = (kind ? decorator(target, key, result) : decorator(result)) || result;
  if (kind && result) __defProp(target, key, result);
  return result;
};
import { html, LitElement } from "lit";
import { property } from "lit/decorators.js";
import "@typo3/backend/element/icon-element.js";
import { baseStyles } from "../../styles/base-styles.js";
import noticeStyles from "../../styles/notice.css.js";
import componentStyles from "./notice.css.js";
const stateIcons = {
  info: "status-dialog-information",
  success: "status-dialog-ok",
  warning: "status-dialog-warning",
  serious: "status-dialog-warning",
  danger: "status-dialog-error"
};
class Notice extends LitElement {
  constructor() {
    super(...arguments);
    this.state = "info";
  }
  static {
    this.styles = [...baseStyles, noticeStyles, componentStyles];
  }
  render() {
    return html`<div class="notice" data-state=${this.state}>
            <slot name="icon">
                <typo3-backend-icon identifier=${stateIcons[this.state]} size="small"></typo3-backend-icon>
            </slot>
            <slot></slot>
        </div>`;
  }
}
__decorateClass([
  property()
], Notice.prototype, "state", 2);
if (customElements.get("mindfula11y-notice") === void 0) {
  customElements.define("mindfula11y-notice", Notice);
}
export {
  Notice
};
