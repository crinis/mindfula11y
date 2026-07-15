const GLOBAL_ARIA_ATTRIBUTES = /* @__PURE__ */ new Set([
  "aria-atomic",
  "aria-busy",
  "aria-controls",
  "aria-current",
  "aria-describedby",
  "aria-details",
  "aria-disabled",
  "aria-dropeffect",
  "aria-errormessage",
  "aria-flowto",
  "aria-grabbed",
  "aria-haspopup",
  "aria-hidden",
  "aria-invalid",
  "aria-keyshortcuts",
  "aria-label",
  "aria-labelledby",
  "aria-live",
  "aria-owns",
  "aria-relevant",
  "aria-roledescription"
]);
const isFocusable = (element) => {
  if (element.matches(":disabled")) {
    return false;
  }
  if (element.hasAttribute("tabindex") || element.isContentEditable) {
    return true;
  }
  return element.matches(
    'a[href], area[href], button, input:not([type="hidden"]), select, textarea, iframe, object, embed, summary, audio[controls], video[controls]'
  );
};
const hasGlobalAriaAttribute = (element) => Array.from(element.attributes).some((attribute) => GLOBAL_ARIA_ATTRIBUTES.has(attribute.name.toLowerCase()));
const hasPresentationalRole = (element) => {
  const role = element.getAttribute("role")?.trim().toLowerCase() ?? "";
  return (role === "none" || role === "presentation") && !isFocusable(element) && !hasGlobalAriaAttribute(element);
};
const resolveExposure = (isExposed = isElementExposed) => (element) => isExposed(element) && !hasPresentationalRole(element);
const isInsideVisibleSummary = (element, details) => {
  const summary = Array.from(details.children).find((child) => child.tagName === "SUMMARY");
  return summary?.contains(element) === true;
};
const isElementExposed = (element) => {
  let current = element;
  const view = element.ownerDocument.defaultView;
  while (current !== null) {
    if (current.hidden || current.hasAttribute("inert") || current.getAttribute("aria-hidden")?.trim().toLowerCase() === "true") {
      return false;
    }
    if (current.tagName === "DETAILS" && !current.hasAttribute("open") && !isInsideVisibleSummary(element, current)) {
      return false;
    }
    if (view !== null) {
      const style = view.getComputedStyle(current);
      if (style.display === "none" || style.contentVisibility === "hidden" || current === element && (style.visibility === "hidden" || style.visibility === "collapse")) {
        return false;
      }
    }
    current = current.parentElement;
  }
  return true;
};
export {
  hasPresentationalRole,
  isElementExposed,
  resolveExposure
};
