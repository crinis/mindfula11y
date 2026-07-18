import Notification from "@typo3/backend/notification.js";
import DocumentService from "@typo3/core/document-service.js";
import RegularEvent from "@typo3/core/event/regular-event.js";
import { lll } from "@typo3/core/lit-helper.js";
import "@typo3/backend/element/spinner-element.js";
import { AltTextApi } from "./alt-text-api.js";
import { errorView } from "./request-error.js";
class GenerateAltTextControl {
  constructor(selector, demand) {
    this.busy = false;
    DocumentService.ready().then(() => {
      const control = document.querySelector(selector);
      const itemName = control?.dataset.itemName ?? "";
      const input = document.querySelector(
        `[data-formengine-input-name="${CSS.escape(itemName)}"]`
      );
      if (control === null || input === null) {
        console.error(`Generate-alt-text control or its input not found for selector "${selector}".`);
        return;
      }
      new RegularEvent("click", (event) => {
        event.preventDefault();
        void this.handleGenerate(control, input, demand);
      }).bindTo(control);
    });
  }
  async handleGenerate(control, input, demand) {
    if (this.busy) {
      return;
    }
    this.busy = true;
    const icon = control.firstElementChild;
    const spinner = document.createElement("typo3-backend-spinner");
    spinner.setAttribute("size", "small");
    control.setAttribute("aria-busy", "true");
    control.replaceChildren(spinner);
    try {
      const altText = await new AltTextApi().generateAltText(demand);
      input.value = altText;
      input.dispatchEvent(new Event("change", { bubbles: true }));
      Notification.success(
        lll("mindfula11y.altText.generate.success"),
        lll("mindfula11y.altText.generate.success.description")
      );
    } catch (error) {
      const view = errorView(error, "mindfula11y.altText.generate.error.unknown");
      Notification.error(view.title, view.description);
    } finally {
      if (icon !== null) {
        control.replaceChildren(icon);
      }
      control.removeAttribute("aria-busy");
      this.busy = false;
    }
  }
}
var generate_alt_text_control_default = GenerateAltTextControl;
export {
  GenerateAltTextControl,
  generate_alt_text_control_default as default
};
